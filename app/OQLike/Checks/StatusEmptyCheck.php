<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class StatusEmptyCheck extends AbstractCheck
{
    public function issueCode(): string
    {
        return 'STATUS_EMPTY';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        if (! (bool) config('oqlike.admin_pack_enabled', true)) {
            return false;
        }

        if ($this->isClassExcluded($className)) {
            return false;
        }

        return $context->attributeByCode($className, 'status') !== null;
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $statusAttribute = $context->attributeByCode($className, 'status');

        if (! is_array($statusAttribute)) {
            return null;
        }

        $missingCondition = $this->missingFieldCondition('status', $statusAttribute);
        $scope = $context->classScopeKey($className);
        $key = $scope['key'] === '1=1'
            ? $missingCondition
            : sprintf('(%s) AND (%s)', $scope['key'], $missingCondition);
        $maxRecords = $this->resolveEffectiveMaxRecords($scope, 'oqlike.admin_pack_max_records_per_check', 2000);

        try {
            $result = $context->itopClient->countAndSample(
                $className,
                $key,
                ['id', 'friendlyname', 'name', 'status'],
                $context->maxSamples,
                $maxRecords
            );
        } catch (Throwable $exception) {
            Log::warning('StatusEmptyCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: $this->issueCode(),
            title: sprintf('%s: statut vide ou null', $className),
            domain: 'consistency',
            severity: 'warn',
            impact: 3,
            affectedCount: (int) Arr::get($result, 'count', 0),
            samples: Arr::get($result, 'samples', []),
            recommendation: 'Renseigner un statut valide pour garantir les workflows et les indicateurs de cycle de vie.',
            suggestedOql: $this->oqlClassScope($className, $missingCondition),
            meta: [
                'class' => $className,
                'field' => 'status',
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
                'max_records' => $maxRecords,
            ],
        );
    }
}
