<?php

namespace App\OQLike\Clients;

use App\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ConnectorClient
{
    private array $lastDebug = [];

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function isConfigured(): bool
    {
        return (bool) $this->connection->connector_url
            && (bool) $this->connection->connector_bearer_encrypted;
    }

    public function testConnectivity(): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'error' => 'URL du connecteur ou jeton bearer manquant.',
                'debug' => $this->buildDebugPayload(),
            ];
        }

        $startedAt = microtime(true);

        try {
            $payload = $this->request('GET', '/ping');
            $pingDebug = $this->buildDebugPayload();
            $persistentClassCount = null;
            $classProbeError = null;
            $classProbeDebug = null;

            if ((bool) Arr::get($payload, 'metamodel_available', false)) {
                try {
                    $persistentClassCount = count($this->fetchPersistentClasses([
                        'timeout' => 8,
                        'retries' => 1,
                        'retry_ms' => 0,
                    ]));
                    $classProbeDebug = $this->buildDebugPayload();
                } catch (Throwable $exception) {
                    $classProbeError = $exception->getMessage();
                    $classProbeDebug = $this->buildDebugPayload($exception);
                }
            }

            return [
                'ok' => (bool) Arr::get($payload, 'ok', false),
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'metamodel_available' => (bool) Arr::get($payload, 'metamodel_available', false),
                'itop_version' => Arr::get($payload, 'itop_version'),
                'persistent_class_count' => $persistentClassCount,
                'class_probe_error' => $classProbeError,
                'debug' => [
                    'ping' => $pingDebug,
                    'class_probe' => $classProbeDebug,
                    'connector_url' => $this->connection->connector_url,
                ],
            ];
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
                'debug' => $this->buildDebugPayload($exception),
                'hints' => $this->connectionHints($exception->getMessage()),
            ];
        }
    }

    public function fetchPersistentClasses(array $httpOverrides = []): array
    {
        $payload = $this->request('GET', '/classes', [
            'filter' => 'persistent',
            'include_hash' => 0,
        ], $httpOverrides);

        return Arr::get($payload, 'classes', []);
    }

    public function fetchClass(string $className): array
    {
        return $this->request('GET', '/class/'.rawurlencode($className));
    }

    public function fetchClassRelations(string $className): array
    {
        return $this->request('GET', '/class/'.rawurlencode($className).'/relations');
    }

    public function fetchMetamodel(array $targetClasses = [], array $classListOverride = []): array
    {
        $errors = [];
        $sourceClassList = $classListOverride !== []
            ? $classListOverride
            : $this->fetchPersistentClasses();

        $classList = array_values(array_unique(array_filter(
            $sourceClassList,
            static fn (mixed $className): bool => is_string($className) && $className !== ''
        )));

        if ($targetClasses !== []) {
            $classList = array_values(array_intersect($classList, $targetClasses));
        }

        $maxClasses = (int) config('oqlike.max_connector_classes', 800);
        if ($maxClasses > 0 && count($classList) > $maxClasses) {
            $errors['__meta__'] = sprintf(
                'Liste de classes tronquée de %d à %d (OQLIKE_MAX_CONNECTOR_CLASSES).',
                count($classList),
                $maxClasses
            );
            $classList = array_slice($classList, 0, $maxClasses);
        }

        $jsonlPath = $this->createTempMetamodelFile();
        $jsonlHandle = @fopen($jsonlPath, 'wb');

        if ($jsonlHandle === false) {
            throw new RuntimeException(sprintf('Impossible de créer le fichier temporaire de métamodèle: %s', $jsonlPath));
        }

        $hashContext = hash_init('sha256');
        $writtenClasses = 0;
        $hardStopRatio = $this->connectorMemoryHardStopRatio();
        $hardStopTriggered = false;
        $failedClasses = [];
        $totalClasses = count($classList);
        $startedAt = microtime(true);

        Log::info('Récupération du métamodèle connecteur démarrée', [
            'connection_id' => $this->connection->id,
            'classes_total' => $totalClasses,
            'target_classes_count' => count($targetClasses),
            'max_connector_classes' => $maxClasses,
        ]);

        try {
            foreach ($classList as $className) {
                if (! is_string($className) || $className === '') {
                    continue;
                }

                if ($this->isMemoryPressureHigh()) {
                    gc_collect_cycles();
                    usleep(1000);
                }

                if ($this->isMemoryPressureCritical($hardStopRatio)) {
                    $hardStopTriggered = true;
                    $errors['__memory__'] = sprintf(
                        'Récupération du métamodèle arrêtée prématurément à cause de la pression mémoire après %d classe(s). Augmentez PHP memory_limit ou réduisez le périmètre des classes.',
                        $writtenClasses
                    );
                    break;
                }

                try {
                    $classPayload = $this->fetchClass($className);
                    $reducedPayload = $this->reduceClassPayload($className, $classPayload);
                    $line = json_encode($reducedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if (! is_string($line) || $line === '') {
                        throw new RuntimeException('Impossible d’encoder la charge de classe en ligne JSON.');
                    }

                    $line .= PHP_EOL;
                    fwrite($jsonlHandle, $line);
                    hash_update($hashContext, $line);
                    $writtenClasses++;

                    if ($writtenClasses % 25 === 0 || $writtenClasses === $totalClasses) {
                        Log::info('Progression de récupération du métamodèle connecteur', [
                            'connection_id' => $this->connection->id,
                            'written_classes' => $writtenClasses,
                            'classes_total' => $totalClasses,
                            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                        ]);
                    }
                } catch (Throwable $exception) {
                    $errors[$className] = $exception->getMessage();
                    $failedClasses[$className] = true;
                }
            }

            if (! $hardStopTriggered && (bool) config('oqlike.connector_class_second_pass', true) && $failedClasses !== []) {
                Log::info('Réessai des classes connecteur en échec (seconde passe)', [
                    'connection_id' => $this->connection->id,
                    'failed_classes_count' => count($failedClasses),
                ]);

                foreach (array_keys($failedClasses) as $className) {
                    if (! is_string($className) || $className === '') {
                        continue;
                    }

                    if ($this->isMemoryPressureCritical($hardStopRatio)) {
                        $hardStopTriggered = true;
                        break;
                    }

                    try {
                        $classPayload = $this->fetchClass($className);
                        $reducedPayload = $this->reduceClassPayload($className, $classPayload);
                        $line = json_encode($reducedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        if (! is_string($line) || $line === '') {
                            throw new RuntimeException('Impossible d’encoder la charge de classe en ligne JSON.');
                        }

                        $line .= PHP_EOL;
                        fwrite($jsonlHandle, $line);
                        hash_update($hashContext, $line);
                        $writtenClasses++;
                        unset($errors[$className], $failedClasses[$className]);
                    } catch (Throwable $exception) {
                        $errors[$className] = $exception->getMessage();
                    }
                }
            }
        } finally {
            fclose($jsonlHandle);
        }

        if ($writtenClasses <= 0) {
            @unlink($jsonlPath);

            Log::warning('Récupération du métamodèle connecteur terminée sans résultat', [
                'connection_id' => $this->connection->id,
                'classes_total' => $totalClasses,
                'errors_count' => count($errors),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'metamodel_hash' => hash('sha256', ''),
                'classes' => [],
                'source' => 'connector',
                'errors' => $errors,
                'normalized' => true,
            ];
        }

        Log::info('Récupération du métamodèle connecteur terminée', [
            'connection_id' => $this->connection->id,
            'written_classes' => $writtenClasses,
            'classes_total' => $totalClasses,
            'errors_count' => count($errors),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return [
            'metamodel_hash' => hash_final($hashContext),
            'classes' => [],
            'classes_jsonl_path' => $jsonlPath,
            'classes_jsonl_count' => $writtenClasses,
            'source' => 'connector',
            'errors' => $errors,
            'normalized' => true,
            'cache_persistable' => false,
            'lazy_attributes' => true,
        ];
    }

    private function reduceClassPayload(string $fallbackClassName, array $payload): array
    {
        $className = (string) Arr::get($payload, 'name', $fallbackClassName);
        if ($className === '') {
            $className = $fallbackClassName;
        }

        $attributes = [];
        foreach ((array) Arr::get($payload, 'attributes', []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $normalized = $this->reduceAttributePayload($attribute);
            if ($normalized !== null) {
                $attributes[] = $normalized;
            }
        }

        return [
            'name' => $className,
            'label' => (string) Arr::get($payload, 'label', $className),
            'is_abstract' => (bool) Arr::get($payload, 'is_abstract', false),
            'is_persistent' => (bool) Arr::get($payload, 'is_persistent', true),
            'attributes' => $attributes,
            'relations' => [],
        ];
    }

    private function reduceAttributePayload(array $attribute): ?array
    {
        $code = (string) Arr::get($attribute, 'code', '');
        if ($code === '') {
            return null;
        }

        $mandatory = (bool) Arr::get($attribute, 'mandatory', false);
        $isExternalKey = (bool) Arr::get($attribute, 'is_external_key', false);

        if (! $this->shouldKeepAttribute($code, $mandatory, $isExternalKey)) {
            return null;
        }

        return [
            'code' => $code,
            'label' => (string) Arr::get($attribute, 'label', $code),
            'type' => (string) Arr::get($attribute, 'type', 'string'),
            'mandatory' => $mandatory,
            'is_external_key' => $isExternalKey,
            'target_class' => Arr::get($attribute, 'target_class'),
            'enum_values' => $code === 'status'
                ? $this->normalizeEnumValues(Arr::get($attribute, 'enum_values', []))
                : [],
            'is_computed' => (bool) Arr::get($attribute, 'is_computed', false),
            'is_readonly' => (bool) Arr::get($attribute, 'is_readonly', false),
        ];
    }

    private function normalizeEnumValues(mixed $rawValues): array
    {
        if (! is_array($rawValues) || $rawValues === []) {
            return [];
        }

        $values = [];

        foreach ($rawValues as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }

            $values[$stringValue] = true;

            if (count($values) >= 60) {
                break;
            }
        }

        return array_keys($values);
    }

    private function shouldKeepAttribute(string $code, bool $mandatory, bool $isExternalKey): bool
    {
        if ($mandatory || $isExternalKey) {
            return true;
        }

        return in_array($code, [
            'name',
            'friendlyname',
            'status',
            'org_id',
            'location_id',
            'last_update',
            'last_modified',
            'sys_last_update',
        ], true);
    }

    private function request(string $method, string $path, array $query = [], array $httpOverrides = []): array
    {
        $url = rtrim((string) $this->connection->connector_url, '/').$path;
        $timeout = max(1, (int) ($httpOverrides['timeout'] ?? config('oqlike.http_timeout', 30)));
        $retries = max(1, (int) ($httpOverrides['retries'] ?? config('oqlike.http_retries', 3)));
        $retryMs = max(0, (int) ($httpOverrides['retry_ms'] ?? config('oqlike.http_retry_ms', 250)));
        $this->lastDebug = [
            'url' => $url,
            'method' => strtoupper($method),
            'query' => $query,
            'http_timeout_s' => $timeout,
            'http_retries' => $retries,
            'http_retry_ms' => $retryMs,
            'http_verify_tls' => config('oqlike.http_verify_tls', true),
            'http_ca_path' => config('oqlike.http_ca_path'),
        ];

        $response = $this->baseRequest($timeout, $retries, $retryMs)
            ->send($method, $url, [
                'query' => $query,
            ]);

        $this->lastDebug['http_status'] = $response->status();
        $this->lastDebug['response_content_type'] = $response->header('Content-Type');
        $this->lastDebug['response_excerpt'] = null;

        if ($response->failed()) {
            $this->lastDebug['response_excerpt'] = $this->excerpt($response->body());
            $status = $response->status();
            $message = sprintf('Erreur HTTP connecteur %d', $status);

            $json = $response->json();

            if (is_array($json)) {
                $error = Arr::get($json, 'error');

                if (is_string($error) && $error !== '') {
                    $message .= ': '.$error;
                }
            } elseif (is_string($this->lastDebug['response_excerpt'] ?? null)) {
                $message .= ': '.$this->lastDebug['response_excerpt'];
            }

            throw new RuntimeException($message);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Réponse JSON du connecteur invalide.');
        }

        return $payload;
    }

    private function baseRequest(int $timeout, int $retries, int $retryMs): PendingRequest
    {
        $verifyTls = config('oqlike.http_verify_tls', true);
        $caPath = config('oqlike.http_ca_path');
        $verifyOption = $verifyTls;

        if (is_string($caPath) && trim($caPath) !== '') {
            $verifyOption = trim($caPath);
        }

        return Http::acceptJson()
            ->timeout($timeout)
            ->withOptions([
                'verify' => $verifyOption,
            ])
            ->retry(
                $retries,
                $retryMs,
                null,
                false
            )
            ->withToken((string) $this->connection->connector_bearer_encrypted);
    }

    private function isMemoryPressureHigh(): bool
    {
        $usageRatio = $this->memoryUsageRatio();

        if ($usageRatio === null) {
            return false;
        }

        $ratio = (float) config('oqlike.connector_memory_guard_ratio', 0.70);
        $ratio = min(max($ratio, 0.10), 0.95);

        return $usageRatio >= $ratio;
    }

    private function isMemoryPressureCritical(float $ratio): bool
    {
        $usageRatio = $this->memoryUsageRatio();

        if ($usageRatio === null) {
            return false;
        }

        $ratio = min(max($ratio, 0.50), 0.99);

        return $usageRatio >= $ratio;
    }

    private function memoryUsageRatio(): ?float
    {
        $limit = $this->memoryLimitBytes();

        if ($limit === null || $limit <= 0) {
            return null;
        }

        return memory_get_usage(false) / $limit;
    }

    private function memoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private function connectorMemoryHardStopRatio(): float
    {
        $soft = (float) config('oqlike.connector_memory_guard_ratio', 0.70);
        $hard = (float) config('oqlike.connector_memory_hard_stop_ratio', 0.92);

        $soft = min(max($soft, 0.10), 0.95);
        $hard = min(max($hard, $soft + 0.05), 0.99);

        return $hard;
    }

    private function createTempMetamodelFile(): string
    {
        $directory = trim((string) config('oqlike.connector_temp_dir', ''));

        if ($directory === '') {
            throw new RuntimeException('Le répertoire temporaire du connecteur est vide.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Impossible de créer le répertoire temporaire du connecteur: %s', $directory));
        }

        $path = tempnam($directory, 'oqlike_metamodel_');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException(sprintf('Impossible d’allouer un fichier temporaire dans: %s', $directory));
        }

        return $path;
    }

    private function buildDebugPayload(?\Throwable $exception = null): array
    {
        $debug = $this->lastDebug;
        $debug['connector_url'] = $this->connection->connector_url;

        if ($exception !== null) {
            $debug['exception_class'] = $exception::class;
            $debug['exception_message'] = $exception->getMessage();
        }

        return $debug;
    }

    private function connectionHints(string $message): array
    {
        $lower = mb_strtolower($message);
        $hints = [];

        if (str_contains($lower, 'connection refused')) {
            $hints[] = 'Connexion refusée vers le connecteur. Vérifier URL/port/vhost HTTPS.';
        }

        if (
            str_contains($lower, 'ssl') ||
            str_contains($lower, 'certificate') ||
            str_contains($lower, 'tls') ||
            str_contains($lower, 'schannel')
        ) {
            $hints[] = 'Erreur TLS/certificat détectée. Utiliser un hostname avec certificat valide ou désactiver temporairement OQLIKE_HTTP_VERIFY_TLS.';
        }

        if ($hints === []) {
            $hints[] = 'Vérifier l’URL du connecteur, le jeton bearer et l’accessibilité réseau depuis OQLook.';
        }

        return $hints;
    }

    private function excerpt(?string $value, int $max = 1200): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).'...';
    }
}
