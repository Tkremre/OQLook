<?php

namespace App\OQLike\Discovery;

use App\Models\Connection;
use App\Models\MetamodelCache;
use App\OQLike\Clients\ConnectorClient;
use App\OQLike\Clients\ItopClient;
use Illuminate\Support\Arr;
use Throwable;

class MetamodelDiscoveryService
{
    public function __construct(
        private readonly FallbackMetamodelBuilder $fallbackBuilder,
        private readonly MetamodelPayloadParser $metamodelPayloadParser,
    ) {
    }

    public function discover(Connection $connection, array $targetClasses = []): array
    {
        $itopClient = new ItopClient($connection);
        $connectorClient = new ConnectorClient($connection);

        $metamodel = null;
        $discoveryErrors = [];

        if ($connectorClient->isConfigured()) {
            $preflight = $this->connectorPreflight($connectorClient);
            $metamodel = $this->attemptFastCacheReuse($connection, $targetClasses, $preflight, $discoveryErrors);

            if ($metamodel === null) {
                if ((bool) Arr::get($preflight, 'ok', false)) {
                    try {
                        $candidate = $this->metamodelPayloadParser->parseConnectorPayload(
                            $connectorClient->fetchMetamodel(
                                $targetClasses,
                                (array) Arr::get($preflight, 'classes', [])
                            ),
                            $targetClasses,
                        );
                        $classCount = count((array) Arr::get($candidate, 'classes', []));

                        if ($classCount > 0) {
                            $candidate['source'] = 'connector';
                            $candidate['source_detail'] = 'connector_live';
                            $candidate['connector_class_list_hash'] = Arr::get($preflight, 'hash');
                            $candidate['connector_class_count'] = count((array) Arr::get($preflight, 'classes', []));
                            $metamodel = $candidate;

                            $connectorErrors = Arr::get($candidate, 'connector_errors', []);
                            if (is_array($connectorErrors) && $connectorErrors !== []) {
                                $discoveryErrors[] = sprintf(
                                    'Connecteur: %d classe(s) ignorée(s): %s',
                                    count($connectorErrors),
                                    implode(' | ', array_slice(
                                        array_map(
                                            static fn (string $className, string $message): string => $className.': '.$message,
                                            array_keys($connectorErrors),
                                            array_values($connectorErrors)
                                        ),
                                        0,
                                        5
                                    ))
                                );
                            }
                        } else {
                            $discoveryErrors[] = 'Le connecteur a renvoyé 0 classe.';
                            $this->cleanupTemporaryMetamodelFile(Arr::get($candidate, 'classes_jsonl_path'));
                        }
                    } catch (Throwable $exception) {
                        $discoveryErrors[] = 'Échec de récupération via le connecteur: '.$exception->getMessage();
                    }
                } else {
                    $discoveryErrors[] = 'Échec de la pré-vérification connecteur: '.(string) Arr::get($preflight, 'error', 'inconnu');
                }

                if ($metamodel === null) {
                    $cached = $this->latestCache($connection);

                    if (is_array($cached)) {
                        $filtered = $this->filterMetamodelClasses($cached, $targetClasses, true);
                        $cachedClasses = count((array) Arr::get($filtered, 'classes', []));

                        if (is_array($filtered) && $cachedClasses > 0) {
                            $metamodel = $filtered;
                            $metamodel['source'] = (string) Arr::get($cached, 'source', 'cache');
                            $metamodel['source_detail'] = 'metamodel_cache';
                        }
                    }
                }
            }
        }

        if ($metamodel === null) {
            try {
                $classes = $this->resolveFallbackClasses($itopClient, $targetClasses, $connection);
            } catch (Throwable $exception) {
                $classes = [];
                $discoveryErrors[] = 'Échec de la découverte des classes de secours: '.$exception->getMessage();
            }

            $metamodel = $this->fallbackBuilder->build($itopClient, $classes, $connection->fallback_config_json ?? []);

            $metamodel['source_detail'] = $connectorClient->isConfigured()
                ? 'fallback_after_connector'
                : 'fallback_only';
        } elseif (! array_key_exists('source_detail', $metamodel)) {
            $metamodel['source_detail'] = (string) Arr::get($metamodel, 'source', 'inconnu');
        }

        if ($discoveryErrors !== []) {
            $metamodel['discovery_error'] = implode(' | ', array_values(array_unique($discoveryErrors)));
        }

        $this->persistCache($connection, $metamodel);

        return $metamodel;
    }

    public function latestCache(Connection $connection): ?array
    {
        $cache = $this->latestCacheEntry($connection);

        if (! $cache instanceof MetamodelCache) {
            return null;
        }

        return $cache->payload_json;
    }

    private function resolveFallbackClasses(ItopClient $itopClient, array $targetClasses, Connection $connection): array
    {
        if ($targetClasses !== []) {
            return array_values(array_unique($targetClasses));
        }

        $configured = Arr::get($connection->fallback_config_json ?? [], 'classes', []);

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return $itopClient->discoverClassesFromData();
    }

    private function persistCache(Connection $connection, array $metamodel): void
    {
        if (! (bool) Arr::get($metamodel, 'cache_persistable', true)) {
            return;
        }

        $payloadToPersist = $metamodel;
        unset($payloadToPersist['classes_jsonl_path'], $payloadToPersist['classes_index'], $payloadToPersist['lazy_attributes'], $payloadToPersist['cache_persistable']);

        $hash = (string) Arr::get($payloadToPersist, 'metamodel_hash', '');
        $classes = (array) Arr::get($payloadToPersist, 'classes', []);

        if ($hash === '') {
            return;
        }

        // Do not overwrite a previously usable cache with an empty discovery payload.
        if ($classes === []) {
            return;
        }

        $latest = $connection->metamodelCaches()->latest('id')->first();

        if ($latest instanceof MetamodelCache && $latest->metamodel_hash === $hash) {
            return;
        }

        $connection->metamodelCaches()->create([
            'metamodel_hash' => $hash,
            'payload_json' => $payloadToPersist,
        ]);
    }

    private function cleanupTemporaryMetamodelFile(mixed $path): void
    {
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return;
        }

        @unlink($path);
    }

    private function latestCacheEntry(Connection $connection): ?MetamodelCache
    {
        $cache = $connection->metamodelCaches()->latest('id')->first();

        return $cache instanceof MetamodelCache ? $cache : null;
    }

    private function connectorPreflight(ConnectorClient $connectorClient): array
    {
        try {
            $classes = $connectorClient->fetchPersistentClasses([
                'timeout' => max(3, (int) config('oqlike.metamodel_precheck_timeout', 12)),
                'retries' => max(1, (int) config('oqlike.metamodel_precheck_retries', 1)),
                'retry_ms' => max(0, (int) config('oqlike.metamodel_precheck_retry_ms', 0)),
            ]);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
                'classes' => [],
                'hash' => null,
            ];
        }

        $normalized = [];

        foreach ((array) $classes as $className) {
            if (! is_string($className)) {
                continue;
            }

            $className = trim($className);

            if ($className === '') {
                continue;
            }

            $normalized[$className] = true;
        }

        $classList = array_keys($normalized);
        sort($classList);

        return [
            'ok' => true,
            'error' => null,
            'classes' => $classList,
            'hash' => $this->hashClassList($classList),
        ];
    }

    private function attemptFastCacheReuse(
        Connection $connection,
        array $targetClasses,
        array $preflight,
        array &$discoveryErrors
    ): ?array {
        if (! (bool) config('oqlike.metamodel_fast_path_enabled', true)) {
            return null;
        }

        $latestCache = $this->latestCacheEntry($connection);

        if (! $latestCache instanceof MetamodelCache) {
            return null;
        }

        $maxAgeMinutes = max(1, (int) config('oqlike.metamodel_fast_path_max_age_min', 240));
        $cacheAgeMinutes = (int) $latestCache->created_at?->diffInMinutes(now());

        if ($cacheAgeMinutes > $maxAgeMinutes) {
            return null;
        }

        $payload = is_array($latestCache->payload_json) ? $latestCache->payload_json : [];
        $filtered = $this->filterMetamodelClasses($payload, $targetClasses, true);

        if (! is_array($filtered) || count((array) Arr::get($filtered, 'classes', [])) === 0) {
            return null;
        }

        if ((bool) Arr::get($preflight, 'ok', false)) {
            $liveHash = (string) Arr::get($preflight, 'hash', '');
            $liveCount = count((array) Arr::get($preflight, 'classes', []));
            $cachedHash = (string) Arr::get($payload, 'connector_class_list_hash', '');
            $cachedCount = (int) Arr::get($payload, 'connector_class_count', count((array) Arr::get($payload, 'classes', [])));

            $hashMatches = $cachedHash !== '' && $liveHash !== '' && hash_equals($cachedHash, $liveHash);
            $countMatches = $cachedHash === '' && $cachedCount > 0 && $cachedCount === $liveCount;

            if (! $hashMatches && ! $countMatches) {
                return null;
            }

            unset($filtered['discovery_error']);
            $filtered['source'] = 'cache';
            $filtered['source_detail'] = 'metamodel_cache_validated';
            $filtered['cache_validated'] = true;

            return $filtered;
        }

        if (! (bool) config('oqlike.metamodel_allow_cache_on_precheck_failure', true)) {
            return null;
        }

        $discoveryErrors[] = 'Pré-vérification connecteur indisponible, utilisation du cache métamodèle récent.';
        unset($filtered['discovery_error']);
        $filtered['source'] = 'cache';
        $filtered['source_detail'] = 'metamodel_cache_unverified';
        $filtered['cache_validated'] = false;

        return $filtered;
    }

    private function filterMetamodelClasses(array $metamodel, array $targetClasses, bool $requireAllTargets): ?array
    {
        if ($targetClasses === []) {
            return $metamodel;
        }

        $normalizedTargets = [];

        foreach ($targetClasses as $className) {
            if (! is_string($className)) {
                continue;
            }

            $className = trim($className);

            if ($className === '') {
                continue;
            }

            $normalizedTargets[$className] = true;
        }

        if ($normalizedTargets === []) {
            return $metamodel;
        }

        $targetNames = array_keys($normalizedTargets);
        $classes = (array) Arr::get($metamodel, 'classes', []);

        if ($requireAllTargets) {
            $missing = array_diff($targetNames, array_keys($classes));

            if ($missing !== []) {
                return null;
            }
        }

        $filteredClasses = [];
        foreach ($targetNames as $className) {
            if (! array_key_exists($className, $classes)) {
                continue;
            }

            $classPayload = $classes[$className];
            if (! is_array($classPayload)) {
                continue;
            }

            $filteredClasses[$className] = $classPayload;
        }

        if ($filteredClasses === []) {
            return null;
        }

        $result = $metamodel;
        $result['classes'] = $filteredClasses;

        if (is_array(Arr::get($metamodel, 'classes_index'))) {
            $filteredIndex = [];

            foreach ($targetNames as $className) {
                $indexPayload = Arr::get($metamodel, 'classes_index.'.$className);

                if (is_array($indexPayload)) {
                    $filteredIndex[$className] = $indexPayload;
                }
            }

            $result['classes_index'] = $filteredIndex;
        }

        return $result;
    }

    private function hashClassList(array $classList): string
    {
        if ($classList === []) {
            return hash('sha256', '');
        }

        return hash('sha256', implode('|', $classList));
    }
}

