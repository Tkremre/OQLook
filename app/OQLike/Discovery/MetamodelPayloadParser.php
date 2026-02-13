<?php

namespace App\OQLike\Discovery;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MetamodelPayloadParser
{
    public function parseConnectorPayload(array $rawPayload, array $targetClasses = []): array
    {
        $jsonlPath = Arr::get($rawPayload, 'classes_jsonl_path');

        if (is_string($jsonlPath) && $jsonlPath !== '' && is_file($jsonlPath)) {
            return $this->parseConnectorJsonlPayload($rawPayload, $jsonlPath, $targetClasses);
        }

        $classes = Arr::get($rawPayload, 'classes', []);
        $isNormalizedPayload = (bool) Arr::get($rawPayload, 'normalized', false);
        $connectorErrors = $this->normalizeConnectorErrors(Arr::get($rawPayload, 'errors', []));

        if (! is_array($classes)) {
            return [
                'metamodel_hash' => hash('sha256', 'invalid_payload'),
                'classes' => [],
                'source' => 'connector',
                'connector_errors' => [],
            ];
        }

        $normalized = [];

        foreach ($classes as $className => $classPayload) {
            if (! is_array($classPayload)) {
                continue;
            }

            $resolvedClassName = (string) Arr::get($classPayload, 'name', $className);

            if ($resolvedClassName === '') {
                continue;
            }

            if ($targetClasses !== [] && ! in_array($resolvedClassName, $targetClasses, true)) {
                continue;
            }

            $attributes = $this->normalizeAttributes((array) Arr::get($classPayload, 'attributes', []));

            $normalized[$resolvedClassName] = [
                'name' => $resolvedClassName,
                'label' => (string) Arr::get($classPayload, 'label', Str::headline($resolvedClassName)),
                'is_abstract' => (bool) Arr::get($classPayload, 'is_abstract', false),
                'is_persistent' => (bool) Arr::get($classPayload, 'is_persistent', true),
                'attributes' => $attributes,
                // Current checks do not consume relation payloads; keep compact to preserve memory.
                'relations' => $isNormalizedPayload ? [] : Arr::get($classPayload, 'relations', []),
            ];
        }

        $metamodelHash = (string) Arr::get($rawPayload, 'metamodel_hash', '');

        if ($metamodelHash === '') {
            $hashInput = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $metamodelHash = hash('sha256', (string) $hashInput);
        }

        return [
            'metamodel_hash' => $metamodelHash,
            'classes' => $normalized,
            'source' => 'connector',
            'connector_errors' => $connectorErrors,
        ];
    }

    private function parseConnectorJsonlPayload(array $rawPayload, string $jsonlPath, array $targetClasses = []): array
    {
        $connectorErrors = $this->normalizeConnectorErrors(Arr::get($rawPayload, 'errors', []));
        $normalized = [];
        $classesIndex = [];

        $handle = @fopen($jsonlPath, 'rb');

        if ($handle === false) {
            return [
                'metamodel_hash' => hash('sha256', 'jsonl_open_failed'),
                'classes' => [],
                'source' => 'connector',
                'connector_errors' => $connectorErrors,
                'cache_persistable' => false,
            ];
        }

        $offset = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $length = strlen($line);

                if ($length <= 0) {
                    continue;
                }

                $classPayload = json_decode(trim($line), true);

                if (! is_array($classPayload)) {
                    $offset += $length;
                    continue;
                }

                $resolvedClassName = (string) Arr::get($classPayload, 'name', '');

                if ($resolvedClassName === '') {
                    $offset += $length;
                    continue;
                }

                if ($targetClasses !== [] && ! in_array($resolvedClassName, $targetClasses, true)) {
                    $offset += $length;
                    continue;
                }

                $normalized[$resolvedClassName] = [
                    'name' => $resolvedClassName,
                    'label' => (string) Arr::get($classPayload, 'label', Str::headline($resolvedClassName)),
                    'is_abstract' => (bool) Arr::get($classPayload, 'is_abstract', false),
                    'is_persistent' => (bool) Arr::get($classPayload, 'is_persistent', true),
                    // Keep normalized attributes so connector payload can be persisted and reused quickly.
                    'attributes' => $this->normalizeAttributes((array) Arr::get($classPayload, 'attributes', [])),
                    'relations' => [],
                ];

                $classesIndex[$resolvedClassName] = [
                    'offset' => $offset,
                    'length' => $length,
                ];

                $offset += $length;
            }
        } finally {
            fclose($handle);
        }

        $metamodelHash = (string) Arr::get($rawPayload, 'metamodel_hash', '');

        if ($metamodelHash === '') {
            $metamodelHash = hash_file('sha256', $jsonlPath) ?: hash('sha256', 'jsonl_hash_failed');
        }

        return [
            'metamodel_hash' => $metamodelHash,
            'classes' => $normalized,
            'classes_index' => $classesIndex,
            'classes_jsonl_path' => $jsonlPath,
            'source' => 'connector',
            'connector_errors' => $connectorErrors,
            'cache_persistable' => true,
            'lazy_attributes' => false,
        ];
    }

    private function normalizeAttributes(array $rawAttributes): array
    {
        $attributes = [];

        foreach ($rawAttributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $code = (string) Arr::get($attribute, 'code');

            if ($code === '') {
                continue;
            }

            $mandatory = (bool) Arr::get($attribute, 'mandatory', false);
            $isExternalKey = (bool) Arr::get($attribute, 'is_external_key', false);

            if (! $this->shouldKeepAttribute($code, $mandatory, $isExternalKey)) {
                continue;
            }

            $enumValues = Arr::get($attribute, 'enum_values', []);
            if (! is_array($enumValues)) {
                $enumValues = [];
            }

            $attributes[] = [
                'code' => $code,
                'label' => (string) Arr::get($attribute, 'label', Str::headline($code)),
                'type' => (string) Arr::get($attribute, 'type', 'string'),
                'mandatory' => $mandatory,
                'is_external_key' => $isExternalKey,
                'target_class' => Arr::get($attribute, 'target_class'),
                'enum_values' => $code === 'status' ? $enumValues : [],
                'is_computed' => (bool) Arr::get($attribute, 'is_computed', false),
                'is_readonly' => (bool) Arr::get($attribute, 'is_readonly', false),
            ];
        }

        return $attributes;
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

    private function normalizeConnectorErrors(mixed $rawErrors): array
    {
        $connectorErrors = [];

        if (! is_array($rawErrors)) {
            return $connectorErrors;
        }

        foreach ($rawErrors as $className => $message) {
            if (! is_string($className) || ! is_string($message) || $message === '') {
                continue;
            }

            $connectorErrors[$className] = $message;
        }

        return $connectorErrors;
    }
}
