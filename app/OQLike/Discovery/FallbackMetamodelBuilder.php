<?php

namespace App\OQLike\Discovery;

use App\OQLike\Clients\ItopClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class FallbackMetamodelBuilder
{
    public function build(ItopClient $itopClient, array $classes, array $fallbackConfig = []): array
    {
        $mandatoryHints = Arr::get($fallbackConfig, 'mandatory_fields', ['name']);
        $externalKeyTargets = Arr::get($fallbackConfig, 'external_key_targets', []);
        $enumHints = Arr::get($fallbackConfig, 'enum_values', []);

        $normalizedClasses = [];

        foreach ($classes as $className) {
            if (! is_string($className) || $className === '') {
                continue;
            }

            try {
                $sampleObject = $itopClient->coreGet($className, '1=1', ['*'], 1, 0);
                $fields = Arr::get($sampleObject, '0.fields', []);
            } catch (Throwable $exception) {
                $fields = [];
            }

            if (! is_array($fields)) {
                $fields = [];
            }

            $attributes = [];
            $relations = [];

            foreach ($fields as $code => $value) {
                if (! is_string($code) || Str::startsWith($code, '_')) {
                    continue;
                }

                $isExternalKey = Str::endsWith($code, '_id') || Str::endsWith($code, '_key');
                $targetClass = Arr::get($externalKeyTargets, $className.'.'.$code);

                if ($isExternalKey) {
                    $relations[] = [
                        'attribute' => $code,
                        'target_class' => is_string($targetClass) ? $targetClass : null,
                    ];
                }

                $attributes[] = [
                    'code' => $code,
                    'label' => Str::headline($code),
                    'type' => $this->guessType($value, $code),
                    'mandatory' => in_array($code, $mandatoryHints, true),
                    'is_external_key' => $isExternalKey,
                    'target_class' => is_string($targetClass) ? $targetClass : null,
                    'enum_values' => Arr::get($enumHints, $className.'.'.$code, []),
                    'is_computed' => false,
                    'is_readonly' => false,
                ];
            }

            $normalizedClasses[$className] = [
                'name' => $className,
                'label' => Str::headline($className),
                'is_abstract' => false,
                'is_persistent' => true,
                'attributes' => $attributes,
                'relations' => $relations,
            ];
        }

        $hashInput = json_encode($normalizedClasses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'metamodel_hash' => hash('sha256', (string) $hashInput),
            'classes' => $normalizedClasses,
            'source' => 'fallback',
        ];
    }

    private function guessType(mixed $value, string $code): string
    {
        if (Str::contains($code, ['date', 'time', 'updated'])) {
            return 'datetime';
        }

        if (is_int($value)) {
            return 'integer';
        }

        if (is_float($value)) {
            return 'float';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_array($value)) {
            return 'json';
        }

        return 'string';
    }
}
