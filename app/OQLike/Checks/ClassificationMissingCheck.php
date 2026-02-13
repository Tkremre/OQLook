<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassificationMissingCheck extends AbstractCheck
{
    private const DEFAULT_FIELDS = [
        'org_id',
        'location_id',
    ];

    public function issueCode(): string
    {
        return 'CLASSIFICATION_MISSING';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        if (! (bool) config('oqlike.admin_pack_enabled', true)) {
            return false;
        }

        if ($this->isClassExcluded($className)) {
            return false;
        }

        return $this->classificationAttributes($context, $className) !== [];
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $classificationAttributes = $this->classificationAttributes($context, $className);

        if ($classificationAttributes === []) {
            return null;
        }

        $conditions = [];

        foreach ($classificationAttributes as $fieldCode => $attribute) {
            $conditions[] = $this->missingFieldCondition($fieldCode, $attribute);
        }

        $missingCondition = implode(' OR ', $conditions);
        $scope = $context->classScopeKey($className);
        $key = $scope['key'] === '1=1'
            ? $missingCondition
            : sprintf('(%s) AND (%s)', $scope['key'], $missingCondition);

        $outputFields = array_values(array_unique(array_merge(
            ['id', 'friendlyname', 'name'],
            array_keys($classificationAttributes)
        )));
        $maxRecords = $this->resolveEffectiveMaxRecords($scope, 'oqlike.admin_pack_max_records_per_check', 2000);

        try {
            $result = $context->itopClient->countAndSample(
                $className,
                $key,
                $outputFields,
                $context->maxSamples,
                $maxRecords
            );
        } catch (Throwable $exception) {
            Log::warning('ClassificationMissingCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: $this->issueCode(),
            title: sprintf('%s: classification org/location incomplete', $className),
            domain: 'completeness',
            severity: 'warn',
            impact: 3,
            affectedCount: (int) Arr::get($result, 'count', 0),
            samples: Arr::get($result, 'samples', []),
            recommendation: 'Completer les champs de classification (organisation, localisation) pour fiabiliser le pilotage CMDB.',
            suggestedOql: $this->oqlClassScope($className, $missingCondition),
            meta: [
                'class' => $className,
                'classification_fields' => array_keys($classificationAttributes),
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
                'max_records' => $maxRecords,
            ],
        );
    }

    /**
     * @return array<string, array>
     */
    private function classificationAttributes(ScanContext $context, string $className): array
    {
        $configuredFields = (array) config('oqlike.admin_pack_classification_fields', self::DEFAULT_FIELDS);
        $attributes = [];

        foreach ($configuredFields as $fieldCode) {
            if (! is_string($fieldCode)) {
                continue;
            }

            $fieldCode = trim($fieldCode);

            if ($fieldCode === '') {
                continue;
            }

            $attribute = $context->attributeByCode($className, $fieldCode);

            if (is_array($attribute)) {
                $attributes[$fieldCode] = $attribute;
            }
        }

        return $attributes;
    }
}
