<?php

namespace App\OQLike\Scanning;

use App\Models\Connection;
use App\OQLike\Clients\ItopClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class ScanContext
{
    private const CLASS_PAYLOAD_CACHE_LIMIT = 32;

    public readonly int $thresholdDays;
    public readonly int $maxSamples;
    public readonly ?int $maxFullRecordsPerClass;
    public readonly ?int $maxFullRecordsWithoutDelta;
    private array $classPayloadCache = [];

    public function __construct(
        public readonly Connection $connection,
        public readonly ItopClient $itopClient,
        public readonly array $metamodel,
        public readonly string $mode = 'delta',
        int $thresholdDays = 365,
        public readonly ?CarbonImmutable $lastScanAt = null,
        public readonly array $selectedClasses = [],
        public readonly bool $forceSelectedClasses = false,
        public readonly array $acknowledgedChecks = [],
    ) {
        $this->thresholdDays = max(1, $thresholdDays);
        $this->maxSamples = max(1, (int) config('oqlike.max_samples', 25));
        $this->maxFullRecordsPerClass = $this->normalizeClassRecordCap(
            (int) config('oqlike.max_full_records_per_class', 5000)
        );
        $this->maxFullRecordsWithoutDelta = $this->normalizeClassRecordCap(
            (int) config('oqlike.max_full_records_without_delta', 5000)
        );
    }

    public function classes(): array
    {
        $classes = Arr::get($this->metamodel, 'classes', []);

        if (! is_array($classes)) {
            return [];
        }

        $filtered = [];

        foreach ($classes as $className => $classMeta) {
            if (! is_array($classMeta)) {
                continue;
            }

            if ((bool) Arr::get($classMeta, 'is_abstract', false)) {
                continue;
            }

            if (! (bool) Arr::get($classMeta, 'is_persistent', true)) {
                continue;
            }

            if ($this->selectedClasses !== [] && ! in_array($className, $this->selectedClasses, true)) {
                continue;
            }

            $filtered[$className] = $classMeta;
        }

        return $filtered;
    }

    public function attributes(string $className): array
    {
        $classMeta = Arr::get($this->metamodel, 'classes.'.$className, []);
        $attributes = Arr::get($classMeta, 'attributes', []);

        if (is_array($attributes) && $attributes !== []) {
            return $attributes;
        }

        $lazyClassPayload = $this->loadClassPayload($className);
        $lazyAttributes = Arr::get($lazyClassPayload, 'attributes', []);

        return is_array($lazyAttributes) ? $lazyAttributes : [];
    }

    public function attributeByCode(string $className, string $code): ?array
    {
        foreach ($this->attributes($className) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            if ((string) Arr::get($attribute, 'code') === $code) {
                return $attribute;
            }
        }

        return null;
    }

    public function resolveDeltaField(string $className): ?string
    {
        $candidates = ['last_update', 'last_modified', 'sys_last_update'];

        foreach ($candidates as $candidate) {
            if ($this->attributeByCode($className, $candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public function classScopeKey(string $className): array
    {
        if ($this->mode !== 'delta') {
            $fullScanUnlimited = (bool) config('oqlike.full_scan_unlimited', false);

            return [
                'key' => '1=1',
                'delta_applied' => false,
                'warning' => (! $fullScanUnlimited && $this->maxFullRecordsPerClass !== null)
                    ? sprintf(
                        'Limite du scan complet active (%d enregistrements/classe). Ajustez OQLIKE_MAX_FULL_RECORDS_PER_CLASS si nécessaire.',
                        $this->maxFullRecordsPerClass
                    )
                    : null,
                'max_records' => $fullScanUnlimited ? null : $this->maxFullRecordsPerClass,
                'skip_class' => false,
                'skip_reason' => null,
            ];
        }

        if ($this->lastScanAt === null) {
            return [
                'key' => '1=1',
                'delta_applied' => false,
                'warning' => null,
                'max_records' => null,
                'skip_class' => false,
                'skip_reason' => null,
            ];
        }

        $deltaField = $this->resolveDeltaField($className);

        if ($deltaField === null) {
            $deltaStrictMode = (bool) config('oqlike.delta_strict_mode', false);

            if ($deltaStrictMode) {
                return [
                    'key' => '1=1',
                    'delta_applied' => false,
                    'warning' => null,
                    'max_records' => 0,
                    'skip_class' => true,
                    'skip_reason' => sprintf(
                        'Classe ignorée en mode delta strict: %s (aucun champ de type last_update). Désactivez OQLIKE_DELTA_STRICT_MODE pour repasser en fallback complet.',
                        $className
                    ),
                ];
            }

            return [
                'key' => '1=1',
                'delta_applied' => false,
                'warning' => $this->maxFullRecordsWithoutDelta !== null
                    ? sprintf(
                        'Delta indisponible pour %s (aucun champ de type last_update), scan complet limité à %d enregistrements.',
                        $className,
                        $this->maxFullRecordsWithoutDelta
                    )
                    : sprintf(
                        'Delta indisponible pour %s (aucun champ de type last_update), scan complet sans limite.',
                        $className
                    ),
                'max_records' => $this->maxFullRecordsWithoutDelta,
                'skip_class' => false,
                'skip_reason' => null,
            ];
        }

        $threshold = $this->lastScanAt->format('Y-m-d H:i:s');

        return [
            'key' => sprintf("%s >= '%s'", $deltaField, addslashes($threshold)),
            'delta_applied' => true,
            'warning' => null,
            'max_records' => null,
            'skip_class' => false,
            'skip_reason' => null,
        ];
    }

    private function normalizeClassRecordCap(int $raw): ?int
    {
        if ($raw <= 0) {
            return null;
        }

        return max(100, $raw);
    }

    public function isIssueAcknowledged(string $className, string $issueCode): bool
    {
        if ($className === '' || $issueCode === '') {
            return false;
        }

        if ($this->forceSelectedClasses && in_array($className, $this->selectedClasses, true)) {
            return false;
        }

        return (bool) ($this->acknowledgedChecks[$className.'|'.$issueCode] ?? false);
    }

    private function loadClassPayload(string $className): ?array
    {
        if (array_key_exists($className, $this->classPayloadCache)) {
            $cached = $this->classPayloadCache[$className];
            unset($this->classPayloadCache[$className]);
            $this->classPayloadCache[$className] = $cached;

            return $cached;
        }

        $jsonlPath = Arr::get($this->metamodel, 'classes_jsonl_path');
        $index = Arr::get($this->metamodel, 'classes_index.'.$className, []);
        $offset = (int) Arr::get($index, 'offset', -1);
        $length = (int) Arr::get($index, 'length', 0);

        if (! is_string($jsonlPath) || $jsonlPath === '' || ! is_file($jsonlPath)) {
            return null;
        }

        if ($offset < 0 || $length <= 0) {
            return null;
        }

        $handle = @fopen($jsonlPath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                return null;
            }

            $line = fread($handle, $length);

            if (! is_string($line) || $line === '') {
                return null;
            }

            $payload = json_decode(trim($line), true);

            if (! is_array($payload)) {
                return null;
            }

            if (count($this->classPayloadCache) >= self::CLASS_PAYLOAD_CACHE_LIMIT) {
                $oldestKey = array_key_first($this->classPayloadCache);

                if (is_string($oldestKey)) {
                    unset($this->classPayloadCache[$oldestKey]);
                }
            }

            $this->classPayloadCache[$className] = $payload;

            return $payload;
        } finally {
            fclose($handle);
        }
    }
}
