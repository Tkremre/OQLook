<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class OwnershipMissingCheck extends AbstractCheck
{
    private const DEFAULT_FIELDS = [
        'owner_id',
        'team_id',
        'agent_id',
        'manager_id',
        'contact_id',
        'caller_id',
    ];

    public function issueCode(): string
    {
        return 'OWNERSHIP_MISSING';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        if (! (bool) config('oqlike.admin_pack_enabled', true)) {
            return false;
        }

        if ($this->isClassExcluded($className)) {
            return false;
        }

        return $this->ownershipAttributes($context, $className) !== [];
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $ownershipAttributes = $this->ownershipAttributes($context, $className);

        if ($ownershipAttributes === []) {
            return null;
        }

        $conditions = [];

        foreach ($ownershipAttributes as $fieldCode => $attribute) {
            $conditions[] = $this->missingFieldCondition($fieldCode, $attribute);
        }

        $missingCondition = implode(' AND ', $conditions);
        $scope = $context->classScopeKey($className);
        $key = $scope['key'] === '1=1'
            ? $missingCondition
            : sprintf('(%s) AND (%s)', $scope['key'], $missingCondition);

        $outputFields = array_values(array_unique(array_merge(
            ['id', 'friendlyname', 'name'],
            array_keys($ownershipAttributes)
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
            Log::warning('OwnershipMissingCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: $this->issueCode(),
            title: sprintf('%s: ownership manquant', $className),
            domain: 'completeness',
            severity: 'warn',
            impact: 3,
            affectedCount: (int) Arr::get($result, 'count', 0),
            samples: Arr::get($result, 'samples', []),
            recommendation: 'Renseigner au moins un attribut de responsabilite (owner/equipe/contact) pour faciliter la gouvernance CMDB.',
            suggestedOql: $this->oqlClassScope($className, $missingCondition),
            meta: [
                'class' => $className,
                'ownership_fields' => array_keys($ownershipAttributes),
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
                'max_records' => $maxRecords,
            ],
        );
    }

    /**
     * @return array<string, array>
     */
    private function ownershipAttributes(ScanContext $context, string $className): array
    {
        $configuredFields = (array) config('oqlike.admin_pack_ownership_fields', self::DEFAULT_FIELDS);
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
