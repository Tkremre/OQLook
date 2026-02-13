<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class StaleWithoutOwnerCheck extends AbstractCheck
{
    private const DEFAULT_OWNER_FIELDS = [
        'owner_id',
        'team_id',
        'agent_id',
        'manager_id',
        'contact_id',
        'caller_id',
    ];

    public function issueCode(): string
    {
        return 'STALE_WITHOUT_OWNER';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        if (! (bool) config('oqlike.admin_pack_enabled', true)) {
            return false;
        }

        if (! (bool) config('oqlike.admin_pack_stale_without_owner_enabled', true)) {
            return false;
        }

        if ($this->isClassExcluded($className)) {
            return false;
        }

        return $context->resolveDeltaField($className) !== null
            && $this->ownerFields($context, $className) !== [];
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $deltaField = $context->resolveDeltaField($className);
        $ownerFields = $this->ownerFields($context, $className);

        if ($deltaField === null || $ownerFields === []) {
            return null;
        }

        $staleBefore = CarbonImmutable::now()->subDays($context->thresholdDays)->format('Y-m-d H:i:s');
        $staleCondition = sprintf("%s < '%s'", $deltaField, addslashes($staleBefore));
        $ownerMissingParts = [];

        foreach ($ownerFields as $fieldCode => $attribute) {
            $ownerMissingParts[] = $this->missingFieldCondition($fieldCode, $attribute);
        }

        $ownerMissingCondition = implode(' AND ', $ownerMissingParts);
        $combinedCondition = sprintf('(%s) AND (%s)', $staleCondition, $ownerMissingCondition);
        $scope = $context->classScopeKey($className);
        $key = $scope['key'] === '1=1'
            ? $combinedCondition
            : sprintf('(%s) AND (%s)', $scope['key'], $combinedCondition);
        $maxRecords = $this->resolveEffectiveMaxRecords($scope, 'oqlike.admin_pack_max_records_per_check', 2000);
        $sampleFields = array_values(array_unique(array_merge(['id', 'friendlyname', 'name', $deltaField], array_keys($ownerFields))));

        try {
            $result = $context->itopClient->countAndSample(
                $className,
                $key,
                $sampleFields,
                $context->maxSamples,
                $maxRecords
            );
        } catch (Throwable $exception) {
            Log::warning('StaleWithoutOwnerCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: $this->issueCode(),
            title: sprintf('%s: objets obsoletes sans ownership', $className),
            domain: 'hygiene',
            severity: 'crit',
            impact: 4,
            affectedCount: (int) Arr::get($result, 'count', 0),
            samples: Arr::get($result, 'samples', []),
            recommendation: 'Assigner un responsable sur les objets obsoletes pour planifier correction, archivage ou suppression.',
            suggestedOql: $this->oqlClassScope($className, $combinedCondition),
            meta: [
                'class' => $className,
                'staleness_field' => $deltaField,
                'threshold_days' => $context->thresholdDays,
                'owner_fields' => array_keys($ownerFields),
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
                'max_records' => $maxRecords,
            ],
        );
    }

    /**
     * @return array<string, array>
     */
    private function ownerFields(ScanContext $context, string $className): array
    {
        $configuredFields = (array) config('oqlike.admin_pack_ownership_fields', self::DEFAULT_OWNER_FIELDS);
        $fields = [];

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
                $fields[$fieldCode] = $attribute;
            }
        }

        return $fields;
    }
}
