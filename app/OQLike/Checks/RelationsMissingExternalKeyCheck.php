<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class RelationsMissingExternalKeyCheck extends AbstractCheck
{
    public function issueCode(): string
    {
        return 'RELATIONS_MISSING_EXTERNALKEY';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        return $this->mandatoryExternalKeys($context, $className) !== [];
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $externalKeys = $this->mandatoryExternalKeys($context, $className);

        if ($externalKeys === []) {
            return null;
        }

        $conditions = [];

        foreach ($externalKeys as $attribute) {
            $code = (string) Arr::get($attribute, 'code');
            $conditions[] = sprintf('(ISNULL(%s) OR %s = 0)', $code, $code);
        }

        $scope = $context->classScopeKey($className);
        $condition = implode(' OR ', $conditions);
        $key = $scope['key'] === '1=1'
            ? $condition
            : sprintf('(%s) AND (%s)', $scope['key'], $condition);

        try {
            $result = $context->itopClient->countAndSample(
                $className,
                $key,
                ['id', 'friendlyname', 'name'],
                $context->maxSamples,
                $scope['max_records']
            );
        } catch (Throwable $exception) {
            Log::warning('RelationsMissingExternalKeyCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: 'RELATIONS_MISSING_EXTERNALKEY',
            title: sprintf('%s: relations obligatoires manquantes', $className),
            domain: 'relations',
            severity: 'crit',
            impact: 4,
            affectedCount: (int) Arr::get($result, 'count', 0),
            samples: Arr::get($result, 'samples', []),
            recommendation: 'Corriger les clés externes obligatoires et imposer des workflows de création qui garantissent les enregistrements liés.',
            suggestedOql: $this->oqlClassScope($className, $condition),
            meta: [
                'class' => $className,
                'external_keys' => array_map(fn ($attr) => Arr::get($attr, 'code'), $externalKeys),
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
            ],
        );
    }

    private function mandatoryExternalKeys(ScanContext $context, string $className): array
    {
        return $this->attributesByFilter($context, $className, function (array $attribute): bool {
            return (bool) Arr::get($attribute, 'mandatory', false)
                && (bool) Arr::get($attribute, 'is_external_key', false)
                && ! (bool) Arr::get($attribute, 'is_computed', false)
                && ! (bool) Arr::get($attribute, 'is_readonly', false);
        });
    }
}
