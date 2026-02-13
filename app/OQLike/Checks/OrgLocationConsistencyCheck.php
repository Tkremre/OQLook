<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrgLocationConsistencyCheck extends AbstractCheck
{
    public function issueCode(): string
    {
        return 'CLASSIFICATION_ORG_LOCATION_MISMATCH';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        if (! (bool) config('oqlike.admin_pack_enabled', true)) {
            return false;
        }

        if (! (bool) config('oqlike.admin_pack_classification_drift_enabled', true)) {
            return false;
        }

        if ($this->isClassExcluded($className)) {
            return false;
        }

        return $context->attributeByCode($className, 'org_id') !== null
            && $context->attributeByCode($className, 'location_id') !== null;
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        if ($context->attributeByCode($className, 'org_id') === null
            || $context->attributeByCode($className, 'location_id') === null) {
            return null;
        }

        $condition = implode(' OR ', [
            '((NOT ISNULL(location_id) AND location_id > 0) AND (ISNULL(org_id) OR org_id = 0))',
            '((NOT ISNULL(org_id) AND org_id > 0) AND (ISNULL(location_id) OR location_id = 0))',
        ]);
        $scope = $context->classScopeKey($className);
        $key = $scope['key'] === '1=1'
            ? $condition
            : sprintf('(%s) AND (%s)', $scope['key'], $condition);
        $maxRecords = $this->resolveEffectiveMaxRecords($scope, 'oqlike.admin_pack_max_records_per_check', 2000);

        try {
            $result = $context->itopClient->countAndSample(
                $className,
                $key,
                ['id', 'friendlyname', 'name', 'org_id', 'location_id'],
                $context->maxSamples,
                $maxRecords
            );
        } catch (Throwable $exception) {
            Log::warning('OrgLocationConsistencyCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: $this->issueCode(),
            title: sprintf('%s: coherence org/location a verifier', $className),
            domain: 'consistency',
            severity: 'info',
            impact: 2,
            affectedCount: (int) Arr::get($result, 'count', 0),
            samples: Arr::get($result, 'samples', []),
            recommendation: 'Verifier que org_id et location_id sont renseignes de facon coherente pour garantir un reporting fiable.',
            suggestedOql: $this->oqlClassScope($className, $condition),
            meta: [
                'class' => $className,
                'fields' => ['org_id', 'location_id'],
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
                'max_records' => $maxRecords,
            ],
        );
    }
}
