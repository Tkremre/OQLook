<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class StalenessLastUpdateCheck extends AbstractCheck
{
    public function issueCode(): string
    {
        return 'STALENESS_LAST_UPDATE';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        return $context->resolveDeltaField($className) !== null;
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $field = $context->resolveDeltaField($className);

        if ($field === null) {
            return null;
        }

        $staleBefore = CarbonImmutable::now()->subDays($context->thresholdDays)->format('Y-m-d H:i:s');
        $condition = sprintf("%s < '%s'", $field, addslashes($staleBefore));

        $scope = $context->classScopeKey($className);
        $key = $scope['key'] === '1=1'
            ? $condition
            : sprintf('(%s) AND (%s)', $scope['key'], $condition);

        try {
            $result = $context->itopClient->countAndSample(
                $className,
                $key,
                ['id', 'friendlyname', 'name', $field],
                $context->maxSamples,
                $scope['max_records']
            );
        } catch (Throwable $exception) {
            Log::warning('StalenessLastUpdateCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: 'STALENESS_LAST_UPDATE',
            title: sprintf('%s: enregistrements obsolètes (> %d jours)', $className, $context->thresholdDays),
            domain: 'hygiene',
            severity: 'warn',
            impact: 3,
            affectedCount: (int) ($result['count'] ?? 0),
            samples: $result['samples'] ?? [],
            recommendation: 'Revoir les enregistrements obsolètes, archiver les actifs périmés et mettre à jour les champs de cycle de vie/propriété.',
            suggestedOql: $this->oqlClassScope($className, $condition),
            meta: [
                'class' => $className,
                'field' => $field,
                'threshold_days' => $context->thresholdDays,
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
            ],
        );
    }
}
