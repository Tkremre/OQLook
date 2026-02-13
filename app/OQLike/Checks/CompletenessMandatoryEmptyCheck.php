<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompletenessMandatoryEmptyCheck extends AbstractCheck
{
    public function issueCode(): string
    {
        return 'COMPLETENESS_MANDATORY_EMPTY';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        return $this->mandatoryScalarAttributes($context, $className) !== [];
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $mandatoryAttributes = $this->mandatoryScalarAttributes($context, $className);

        if ($mandatoryAttributes === []) {
            return null;
        }

        $conditions = [];

        foreach ($mandatoryAttributes as $attribute) {
            $code = (string) Arr::get($attribute, 'code');

            if ((bool) Arr::get($attribute, 'is_external_key', false)) {
                $conditions[] = sprintf('(ISNULL(%s) OR %s = 0)', $code, $code);
                continue;
            }

            $conditions[] = sprintf("(ISNULL(%s) OR %s = '')", $code, $code);
        }

        $scope = $context->classScopeKey($className);
        $missingCondition = implode(' OR ', $conditions);
        $key = $scope['key'] === '1=1'
            ? $missingCondition
            : sprintf('(%s) AND (%s)', $scope['key'], $missingCondition);

        try {
            $result = $context->itopClient->countAndSample(
                $className,
                $key,
                ['id', 'friendlyname', 'name'],
                $context->maxSamples,
                $scope['max_records']
            );
        } catch (Throwable $exception) {
            Log::warning('CompletenessMandatoryEmptyCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: 'COMPLETENESS_MANDATORY_EMPTY',
            title: sprintf('%s: champs obligatoires vides', $className),
            domain: 'completeness',
            severity: 'crit',
            impact: 5,
            affectedCount: (int) Arr::get($result, 'count', 0),
            samples: Arr::get($result, 'samples', []),
            recommendation: 'Renseigner les attributs obligatoires ou ajuster les contraintes du métamodèle si elles ne sont plus pertinentes.',
            suggestedOql: $this->oqlClassScope($className, $missingCondition),
            meta: [
                'class' => $className,
                'fields' => array_map(fn ($attr) => Arr::get($attr, 'code'), $mandatoryAttributes),
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
            ],
        );
    }

    private function mandatoryScalarAttributes(ScanContext $context, string $className): array
    {
        return $this->attributesByFilter($context, $className, function (array $attribute): bool {
            return (bool) Arr::get($attribute, 'mandatory', false)
                && ! (bool) Arr::get($attribute, 'is_computed', false)
                && ! (bool) Arr::get($attribute, 'is_readonly', false)
                && $this->scalarAttribute($attribute);
        });
    }
}
