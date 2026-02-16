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

class ItopClient
{
    private array $lastDebug = [];
    private array $rootClassCandidatesTried = [];
    private ?string $resolvedDiscoveryRootClass = null;

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function testConnectivity(): array
    {
        $startedAt = microtime(true);

        try {
            $rootClass = $this->resolveDiscoveryRootClass();
            $probe = $this->coreGet($rootClass, '1=1', ['id', 'friendlyname', 'finalclass'], 1, 0);
            $probeCount = count($probe);

            return [
                'ok' => true,
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'mode' => $this->connection->auth_mode,
                'root_class' => $rootClass,
                'probe_count' => $probeCount,
                'warning' => $probeCount === 0
                    ? 'Connexion OK mais la sonde a retourné 0 objet (droits limités ou incompatibilité OQL sur l’instance iTop cible).'
                    : null,
                'probe_sample' => $probeCount > 0 ? Arr::first($probe) : null,
                'debug' => $this->buildDebugPayload(),
            ];
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException && $this->isInvalidClassError($exception->getMessage())) {
                return [
                    'ok' => true,
                    'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'mode' => $this->connection->auth_mode,
                    'probe_count' => 0,
                    'warning' => 'iTop est joignable/authentifié, mais la classe racine générique est indisponible sur cette instance. Utilisez le métamodèle du connecteur (recommandé) ou des classes de secours.',
                    'debug' => $this->buildDebugPayload($exception),
                    'hints' => $this->connectionHints($exception->getMessage()),
                ];
            }

            return [
                'ok' => false,
                'error' => $exception->getMessage(),
                'mode' => $this->connection->auth_mode,
                'debug' => $this->buildDebugPayload($exception),
                'hints' => $this->connectionHints($exception->getMessage()),
            ];
        }
    }

    public function discoverClassesFromData(int $scanLimit = 1500): array
    {
        $rootClass = $this->resolveDiscoveryRootClass();
        $classes = [];
        $offset = 0;
        $pageSize = min(config('oqlike.page_size', 250), 250);

        while ($offset < $scanLimit) {
            $objects = $this->coreGet($rootClass, '1=1', ['finalclass'], $pageSize, $offset);

            if ($objects === []) {
                break;
            }

            foreach ($objects as $object) {
                $finalClass = Arr::get($object, 'fields.finalclass');

                if (! is_string($finalClass) || $finalClass === '') {
                    // Some iTop responses do not expose finalclass in fields
                    // for abstract queries; fallback to object class metadata.
                    $finalClass = Arr::get($object, 'class');
                }

                if (is_string($finalClass) && $finalClass !== '') {
                    $classes[$finalClass] = true;
                }
            }

            if (count($objects) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        return array_keys($classes);
    }

    /**
     * @return array<int, array{
     *   rule_id: string,
     *   name: string,
     *   status: string|null,
     *   target_class: string|null,
     *   oql: string|null,
     *   executable: bool,
     *   raw_fields: array<string, mixed>
     * }>
     */
    public function discoverAuditRules(int $maxRecords = 1200): array
    {
        $maxRecords = max(1, min($maxRecords, 5000));
        $attempts = [
            ['*'],
            ['id', 'friendlyname', 'status', 'name', 'description', 'query', 'oql', 'target_class'],
            ['id', 'friendlyname', 'status'],
            ['id'],
        ];

        $objects = [];
        $lastError = null;

        foreach ($attempts as $fields) {
            try {
                $objects = $this->fetchObjects('AuditRule', '1=1', $fields, $maxRecords);
                break;
            } catch (\Throwable $exception) {
                $lastError = $exception;
            }
        }

        if ($objects === [] && $lastError !== null) {
            throw new RuntimeException('Impossible de récupérer les règles AuditRule iTop: '.$lastError->getMessage());
        }

        $rules = [];

        foreach ($objects as $object) {
            $fields = Arr::get($object, 'fields', []);
            $fields = is_array($fields) ? $fields : [];

            $ruleId = trim((string) Arr::get($object, 'id', Arr::get($fields, 'id', '')));

            if ($ruleId === '') {
                continue;
            }

            $name = $this->firstString($object, [
                'friendlyname',
                'fields.friendlyname',
                'fields.name',
                'fields.code',
                'fields.ref',
            ]) ?? ('AuditRule #'.$ruleId);

            $status = $this->firstString($object, [
                'fields.status',
                'fields.lifecycle_status',
                'fields.state',
            ]);

            $oql = $this->firstString($object, [
                'fields.oql',
                'fields.query',
                'fields.definition',
                'fields.scope_query',
                'fields.rule',
                'fields.condition',
                'fields.filter',
            ]);
            $oql = is_string($oql) ? trim($oql) : null;
            if ($oql === '') {
                $oql = null;
            }

            $targetClass = $this->firstString($object, [
                'fields.target_class',
                'fields.class_name',
                'fields.item_class',
                'fields.target',
            ]);

            if (($targetClass === null || $targetClass === '') && is_string($oql)) {
                $targetClass = $this->extractSelectClassFromOql($oql);
            }

            if ($targetClass !== null) {
                $targetClass = trim($targetClass);
                if ($targetClass === '') {
                    $targetClass = null;
                }
            }

            $isExecutable = $oql !== null && $targetClass !== null;

            $rules[] = [
                'rule_id' => $ruleId,
                'name' => $name,
                'status' => $status,
                'target_class' => $targetClass,
                'oql' => $oql,
                'executable' => $isExecutable,
                'raw_fields' => $fields,
            ];
        }

        usort($rules, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rules;
    }

    public function coreGet(string $class, string $key, array $outputFields, int $limit, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $basePayload = [
            'operation' => 'core/get',
            'class' => $class,
            'key' => $this->normalizeKey($class, $key),
            'output_fields' => implode(',', $outputFields),
        ];

        // iTop versions differ on pagination shape:
        // - some accept "limit" as "offset,count"
        // - others accept integer limit with "page"
        // - some only accept integer limit
        $candidates = [];
        $candidates[] = ['limit' => sprintf('%d,%d', $offset, $limit)];
        $candidates[] = ['limit' => $limit, 'page' => (int) floor($offset / $limit) + 1];

        if ($offset === 0) {
            $candidates[] = ['limit' => $limit];
        }

        $seen = [];

        foreach ($candidates as $pagination) {
            $signature = json_encode($pagination);

            if (! is_string($signature) || isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $response = $this->request([...$basePayload, ...$pagination]);
            $objects = $this->extractObjects($response);

            if ($objects !== []) {
                return $objects;
            }
        }

        return [];
    }

    public function countAndSample(
        string $class,
        string $key,
        array $sampleFields,
        int $sampleLimit = 10,
        ?int $maxRecords = null
    ): array {
        $maxRecords = $maxRecords ?? PHP_INT_MAX;
        $pageSize = max(1, (int) config('oqlike.page_size', 250));
        $maxLoopDurationMs = max(0, (int) config('oqlike.itop_loop_max_duration_s', 0)) * 1000;
        $maxLoopPages = max(0, (int) config('oqlike.itop_loop_max_pages', 0));
        $heartbeatEveryPages = max(0, (int) config('oqlike.itop_loop_heartbeat_pages', 20));

        $count = 0;
        $offset = 0;
        $samples = [];
        $truncated = false;
        $stopReason = null;
        $loopStartedAt = microtime(true);
        $pageCount = 0;
        $samePageCount = 0;
        $lastPageFingerprint = null;

        while ($count < $maxRecords) {
            if ($maxLoopDurationMs > 0) {
                $elapsedMs = (int) round((microtime(true) - $loopStartedAt) * 1000);

                if ($elapsedMs >= $maxLoopDurationMs) {
                    $truncated = true;
                    $stopReason = 'max_loop_duration';
                    Log::warning('iTop countAndSample loop stopped by duration guard.', [
                        'class' => $class,
                        'elapsed_ms' => $elapsedMs,
                        'max_loop_duration_ms' => $maxLoopDurationMs,
                        'page_count' => $pageCount,
                        'count' => $count,
                        'offset' => $offset,
                    ]);
                    break;
                }
            }

            if ($maxLoopPages > 0 && $pageCount >= $maxLoopPages) {
                $truncated = true;
                $stopReason = 'max_loop_pages';
                Log::warning('iTop countAndSample loop stopped by page guard.', [
                    'class' => $class,
                    'max_loop_pages' => $maxLoopPages,
                    'count' => $count,
                    'offset' => $offset,
                ]);
                break;
            }

            $limit = min($pageSize, $maxRecords - $count);
            $objects = $this->coreGet($class, $key, $sampleFields, $limit, $offset);

            if ($objects === []) {
                break;
            }

            $pageCount++;
            $count += count($objects);
            $pageFingerprint = $this->pageFingerprint($objects);

            if ($offset > 0 && $pageFingerprint !== null && $pageFingerprint === $lastPageFingerprint) {
                $samePageCount++;
            } else {
                $samePageCount = 0;
            }

            $lastPageFingerprint = $pageFingerprint;

            if ($samePageCount >= 2) {
                $truncated = true;
                $stopReason = 'pagination_stalled';
                Log::warning('iTop countAndSample pagination looks stalled, stopping loop.', [
                    'class' => $class,
                    'page_count' => $pageCount,
                    'count' => $count,
                    'offset' => $offset,
                ]);
                break;
            }

            if (count($samples) < $sampleLimit) {
                foreach ($objects as $object) {
                    if (count($samples) >= $sampleLimit) {
                        break;
                    }

                    $samples[] = $this->normalizeSample($object, $class);
                }
            }

            if (count($objects) < $limit) {
                break;
            }

            $offset += $limit;

            if ($count >= $maxRecords) {
                $truncated = true;
                $stopReason = 'max_records';
            }

            if ($heartbeatEveryPages > 0 && $pageCount % $heartbeatEveryPages === 0) {
                Log::info('iTop countAndSample progress.', [
                    'class' => $class,
                    'page_count' => $pageCount,
                    'count' => $count,
                    'offset' => $offset,
                    'elapsed_ms' => (int) round((microtime(true) - $loopStartedAt) * 1000),
                ]);
            }
        }

        return [
            'count' => $count,
            'samples' => $samples,
            'truncated' => $truncated,
            'stop_reason' => $stopReason,
            'page_count' => $pageCount,
            'elapsed_ms' => (int) round((microtime(true) - $loopStartedAt) * 1000),
        ];
    }

    public function fetchObjects(
        string $class,
        string $key,
        array $fields,
        ?int $maxRecords = null
    ): array {
        $pageSize = max(1, (int) config('oqlike.page_size', 250));
        $maxRecords = $maxRecords ?? PHP_INT_MAX;
        $maxLoopDurationMs = max(0, (int) config('oqlike.itop_loop_max_duration_s', 0)) * 1000;
        $maxLoopPages = max(0, (int) config('oqlike.itop_loop_max_pages', 0));
        $heartbeatEveryPages = max(0, (int) config('oqlike.itop_loop_heartbeat_pages', 20));

        $all = [];
        $offset = 0;
        $loopStartedAt = microtime(true);
        $pageCount = 0;
        $samePageCount = 0;
        $lastPageFingerprint = null;

        while (count($all) < $maxRecords) {
            if ($maxLoopDurationMs > 0) {
                $elapsedMs = (int) round((microtime(true) - $loopStartedAt) * 1000);

                if ($elapsedMs >= $maxLoopDurationMs) {
                    Log::warning('iTop fetchObjects loop stopped by duration guard.', [
                        'class' => $class,
                        'elapsed_ms' => $elapsedMs,
                        'max_loop_duration_ms' => $maxLoopDurationMs,
                        'page_count' => $pageCount,
                        'fetched_count' => count($all),
                        'offset' => $offset,
                    ]);
                    break;
                }
            }

            if ($maxLoopPages > 0 && $pageCount >= $maxLoopPages) {
                Log::warning('iTop fetchObjects loop stopped by page guard.', [
                    'class' => $class,
                    'max_loop_pages' => $maxLoopPages,
                    'page_count' => $pageCount,
                    'fetched_count' => count($all),
                    'offset' => $offset,
                ]);
                break;
            }

            $limit = min($pageSize, $maxRecords - count($all));
            $page = $this->coreGet($class, $key, $fields, $limit, $offset);

            if ($page === []) {
                break;
            }

            $pageCount++;
            $pageFingerprint = $this->pageFingerprint($page);

            if ($offset > 0 && $pageFingerprint !== null && $pageFingerprint === $lastPageFingerprint) {
                $samePageCount++;
            } else {
                $samePageCount = 0;
            }

            $lastPageFingerprint = $pageFingerprint;

            if ($samePageCount >= 2) {
                Log::warning('iTop fetchObjects pagination looks stalled, stopping loop.', [
                    'class' => $class,
                    'page_count' => $pageCount,
                    'fetched_count' => count($all),
                    'offset' => $offset,
                ]);
                break;
            }

            foreach ($page as $object) {
                $all[] = $object;
            }

            if (count($page) < $limit) {
                break;
            }

            $offset += $limit;

            if ($heartbeatEveryPages > 0 && $pageCount % $heartbeatEveryPages === 0) {
                Log::info('iTop fetchObjects progress.', [
                    'class' => $class,
                    'page_count' => $pageCount,
                    'fetched_count' => count($all),
                    'offset' => $offset,
                    'elapsed_ms' => (int) round((microtime(true) - $loopStartedAt) * 1000),
                ]);
            }
        }

        return $all;
    }

    public function itopObjectUrl(string $class, string|int $id): string
    {
        return sprintf(
            '%s/pages/UI.php?operation=details&class=%s&id=%s',
            rtrim($this->itopBaseUrl(), '/'),
            rawurlencode($class),
            rawurlencode((string) $id)
        );
    }

    public function itopBaseUrl(): string
    {
        $url = rtrim($this->connection->itop_url, '/');

        if (! str_contains($url, 'rest.php')) {
            return $url;
        }

        $parsed = parse_url($url);

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $path = preg_replace('#/webservices/rest\.php$#', '', $path) ?? '';

        return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
    }

    public function discoveryRootClass(): ?string
    {
        return $this->resolvedDiscoveryRootClass;
    }

    private function resolveDiscoveryRootClass(): string
    {
        if (is_string($this->resolvedDiscoveryRootClass) && $this->resolvedDiscoveryRootClass !== '') {
            return $this->resolvedDiscoveryRootClass;
        }

        $lastException = null;
        $this->rootClassCandidatesTried = [];

        foreach ($this->discoveryRootCandidates() as $candidate) {
            $this->rootClassCandidatesTried[] = $candidate;

            try {
                $this->coreGet($candidate, '1=1', ['id'], 1, 0);
                $this->resolvedDiscoveryRootClass = $candidate;

                return $candidate;
            } catch (RuntimeException $exception) {
                $lastException = $exception;

                if ($this->isInvalidClassError($exception->getMessage())) {
                    continue;
                }
            }
        }

        if ($lastException instanceof RuntimeException) {
            throw $lastException;
        }

        throw new RuntimeException(sprintf(
            'Aucune classe racine de découverte iTop valide n’a été trouvée. Candidats testés: %s',
            implode(', ', $this->rootClassCandidatesTried)
        ));
    }

    private function discoveryRootCandidates(): array
    {
        $configured = config('oqlike.itop_discovery_root_classes', []);
        $candidates = is_array($configured) ? $configured : [];

        // Keep a defensive default fallback even if config is empty.
        $candidates = [...$candidates, 'cmdbAbstractObject', 'CMDBAbstractObject', 'CMDBObject'];

        $normalized = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }

            $normalized[] = $candidate;
        }

        return $normalized;
    }

    private function request(array $operationPayload): array
    {
        $url = $this->restUrl();
        $body = [
            ...$this->authPayload(),
            ...$operationPayload,
        ];

        $version = (string) config('oqlike.itop_version', '1.3');
        $jsonData = json_encode($body, JSON_THROW_ON_ERROR);

        $this->lastDebug = [
            'rest_url' => $url,
            'auth_mode' => $this->connection->auth_mode,
            'operation' => Arr::get($operationPayload, 'operation'),
            'class' => Arr::get($operationPayload, 'class'),
            'key' => Arr::get($operationPayload, 'key'),
            'output_fields' => Arr::get($operationPayload, 'output_fields'),
            'http_timeout_s' => (int) config('oqlike.http_timeout', 30),
            'http_retries' => (int) config('oqlike.http_retries', 3),
            'http_retry_ms' => (int) config('oqlike.http_retry_ms', 250),
            'http_verify_tls' => config('oqlike.http_verify_tls', true),
            'http_ca_path' => config('oqlike.http_ca_path'),
            'itop_rest_version' => $version,
        ];

        $response = $this->baseRequest()->asForm()->post($url, [
            'json_data' => $jsonData,
            'version' => $version,
        ]);

        $this->lastDebug['http_status'] = $response->status();
        $this->lastDebug['response_content_type'] = $response->header('Content-Type');
        $this->lastDebug['response_excerpt'] = $this->excerpt($response->body());

        $payload = $response->json();

        if (is_array($payload)) {
            $this->lastDebug['itop_code'] = Arr::get($payload, 'code');
            $this->lastDebug['itop_message'] = Arr::get($payload, 'message');
        }

        $response->throw();

        if (! is_array($payload)) {
            throw new RuntimeException('Réponse JSON iTop invalide.');
        }

        $code = (int) Arr::get($payload, 'code', 0);

        if ($code !== 0) {
            $message = (string) Arr::get($payload, 'message', 'Erreur iTop inconnue');

            throw new RuntimeException(sprintf('iTop API error (%d): %s', $code, $message));
        }

        $this->throttle();

        return $payload;
    }

    private function authPayload(): array
    {
        if ($this->connection->auth_mode === 'basic') {
            return [
                'auth_user' => (string) $this->connection->username,
                'auth_pwd' => (string) $this->connection->password_encrypted,
            ];
        }

        return [
            'auth_token' => (string) $this->connection->token_encrypted,
        ];
    }

    private function restUrl(): string
    {
        $url = rtrim($this->connection->itop_url, '/');

        if (str_contains($url, 'rest.php')) {
            return $url;
        }

        return sprintf('%s/webservices/rest.php?version=%s', $url, config('oqlike.itop_version', '1.3'));
    }

    private function baseRequest(): PendingRequest
    {
        $verifyTls = config('oqlike.http_verify_tls', true);
        $caPath = config('oqlike.http_ca_path');

        $verifyOption = $verifyTls;

        if (is_string($caPath) && trim($caPath) !== '') {
            $verifyOption = trim($caPath);
        }

        $request = Http::acceptJson()
            ->timeout((int) config('oqlike.http_timeout', 30))
            ->withOptions([
                'verify' => $verifyOption,
            ])
            ->retry(
                (int) config('oqlike.http_retries', 3),
                (int) config('oqlike.http_retry_ms', 250)
            );

        if ($this->connection->auth_mode === 'basic' && $this->connection->username !== null) {
            $request = $request->withBasicAuth(
                $this->connection->username,
                (string) $this->connection->password_encrypted
            );
        }

        return $request;
    }

    private function throttle(): void
    {
        $rateLimitMs = (int) config('oqlike.rate_limit_ms', 150);

        if ($rateLimitMs > 0) {
            usleep($rateLimitMs * 1000);
        }
    }

    private function buildDebugPayload(?\Throwable $exception = null): array
    {
        $debug = $this->lastDebug;
        $debug['resolved_rest_url'] = $this->restUrl();
        $debug['resolved_base_url'] = $this->itopBaseUrl();
        $debug['discovery_root_class_candidates'] = $this->rootClassCandidatesTried;
        $debug['discovery_root_class_selected'] = $this->resolvedDiscoveryRootClass;

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
        $url = $this->restUrl();
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $isIpHost = $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isHttps = ($parts['scheme'] ?? '') === 'https';
        $verifyTls = (bool) config('oqlike.http_verify_tls', true);

        if (str_contains($lower, 'connection refused')) {
            $hints[] = 'Connexion refusée par le serveur iTop (protocole/port à vérifier).';
        }

        if ($this->isInvalidClassError($message)) {
            $hints[] = sprintf(
                'Classe racine de découverte invalide. Configurer OQLIKE_ITOP_DISCOVERY_ROOT_CLASSES (candidats testés: %s).',
                implode(', ', $this->rootClassCandidatesTried)
            );
        }

        if (
            str_contains($lower, 'ssl') ||
            str_contains($lower, 'certificate') ||
            str_contains($lower, 'tls') ||
            str_contains($lower, 'schannel')
        ) {
            $hints[] = 'Erreur TLS/certificat détectée. Utiliser un hostname qui correspond au certificat ou une CA valide.';
        }

        if ($isHttps && $isIpHost && $verifyTls) {
            $hints[] = 'HTTPS + IP détecté: incompatibilité certificat probable. Utiliser un DNS/certificat valide ou désactiver temporairement OQLIKE_HTTP_VERIFY_TLS.';
        }

        if ($isHttps && ! $verifyTls) {
            $hints[] = 'Vérification TLS désactivée (temporaire). Revenir à true dès que possible.';
        }

        if ($hints === []) {
            $hints[] = 'Vérifier l’URL iTop, les identifiants et l’accès réseau depuis le serveur OQLook.';
        }

        return $hints;
    }

    private function isInvalidClassError(string $message): bool
    {
        $lower = mb_strtolower($message);

        return str_contains($lower, 'not a valid class')
            || str_contains($lower, 'invalid class');
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

    private function extractObjects(array $payload): array
    {
        $objects = Arr::get($payload, 'objects', []);

        if (! is_array($objects)) {
            return [];
        }

        $normalized = [];

        foreach ($objects as $objectKey => $objectPayload) {
            if (! is_array($objectPayload)) {
                continue;
            }

            $class = (string) Arr::get($objectPayload, 'class', '');
            $fields = Arr::get($objectPayload, 'fields', []);
            $id = Arr::get($fields, 'id') ?? $this->extractIdFromObjectKey((string) $objectKey);

            if ($id === null) {
                continue;
            }

            $normalized[] = [
                'class' => $class,
                'id' => (string) $id,
                'fields' => is_array($fields) ? $fields : [],
                'friendlyname' => Arr::get($objectPayload, 'friendlyname'),
            ];
        }

        return $normalized;
    }

    private function extractIdFromObjectKey(string $objectKey): ?string
    {
        if (preg_match('/::(\d+)$/', $objectKey, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function normalizeSample(array $object, string $defaultClass): array
    {
        $class = Arr::get($object, 'class', $defaultClass);
        $id = (string) Arr::get($object, 'id', Arr::get($object, 'fields.id', ''));
        $name = Arr::get($object, 'friendlyname')
            ?? Arr::get($object, 'fields.friendlyname')
            ?? Arr::get($object, 'fields.name')
            ?? sprintf('%s::%s', $class, $id);

        return [
            'class' => (string) $class,
            'id' => $id,
            'name' => (string) $name,
            'link' => $this->itopObjectUrl((string) $class, $id),
        ];
    }

    private function pageFingerprint(array $objects): ?string
    {
        if ($objects === []) {
            return null;
        }

        $ids = [];

        foreach ($objects as $object) {
            $id = Arr::get($object, 'id', Arr::get($object, 'fields.id'));

            if ($id === null) {
                continue;
            }

            $ids[] = (string) $id;
        }

        if ($ids === []) {
            return null;
        }

        return sprintf('%s|%s|%d', $ids[0], $ids[count($ids) - 1], count($ids));
    }

    private function normalizeKey(string $class, string $key): string
    {
        $trimmed = trim($key);

        if ($trimmed === '') {
            return sprintf('SELECT %s', $class);
        }

        if (preg_match('/^SELECT\\s+/i', $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match('/^\\d+$/', $trimmed) === 1) {
            return $trimmed;
        }

        return sprintf('SELECT %s WHERE %s', $class, $trimmed);
    }

    private function firstString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if (is_string($value)) {
                $value = trim($value);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function extractSelectClassFromOql(string $oql): ?string
    {
        if (preg_match('/^\s*SELECT\s+([A-Za-z0-9_]+)/i', $oql, $matches) !== 1) {
            return null;
        }

        $className = trim((string) ($matches[1] ?? ''));
        return $className !== '' ? $className : null;
    }
}
