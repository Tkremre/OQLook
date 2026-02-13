<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class NamePlaceholderCheck extends AbstractCheck
{
    private const DEFAULT_TERMS = [
        'test',
        'tmp',
        'todo',
        'tbd',
        'sample',
        'dummy',
        'unknown',
        'n/a',
        'na',
        'xxx',
        'to define',
    ];

    public function issueCode(): string
    {
        return 'NAME_PLACEHOLDER';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        if (! (bool) config('oqlike.admin_pack_enabled', true)) {
            return false;
        }

        if ($this->isClassExcluded($className)) {
            return false;
        }

        return $context->attributeByCode($className, 'name') !== null
            && $this->placeholderTerms() !== [];
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        if ($context->attributeByCode($className, 'name') === null) {
            return null;
        }

        $terms = $this->placeholderTerms();

        if ($terms === []) {
            return null;
        }

        $condition = $this->placeholderCondition('name', $terms);
        $scope = $context->classScopeKey($className);
        $key = $scope['key'] === '1=1'
            ? $condition
            : sprintf('(%s) AND (%s)', $scope['key'], $condition);
        $maxRecords = $this->resolveEffectiveMaxRecords($scope, 'oqlike.admin_pack_max_records_per_check', 2000);

        try {
            $result = $context->itopClient->countAndSample(
                $className,
                $key,
                ['id', 'friendlyname', 'name'],
                $context->maxSamples,
                $maxRecords
            );
        } catch (Throwable $exception) {
            Log::warning('NamePlaceholderCheck failed.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        return $this->buildIssue(
            code: $this->issueCode(),
            title: sprintf('%s: noms placeholder detectes', $className),
            domain: 'hygiene',
            severity: 'info',
            impact: 2,
            affectedCount: (int) Arr::get($result, 'count', 0),
            samples: Arr::get($result, 'samples', []),
            recommendation: 'Remplacer les valeurs generiques (test/tmp/todo...) par des noms metier stables.',
            suggestedOql: $this->oqlClassScope($className, $condition),
            meta: [
                'class' => $className,
                'field' => 'name',
                'placeholder_terms' => $terms,
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
                'max_records' => $maxRecords,
            ],
        );
    }

    /**
     * @param list<string> $terms
     */
    private function placeholderCondition(string $fieldCode, array $terms): string
    {
        $conditions = [];

        foreach ($terms as $term) {
            foreach ($this->termVariants($term) as $variant) {
                $escaped = addslashes($variant);
                $conditions[sprintf("%s = '%s'", $fieldCode, $escaped)] = true;
                $conditions[sprintf("%s LIKE '%s%%'", $fieldCode, $escaped)] = true;
            }
        }

        if ($conditions === []) {
            return sprintf("%s = '__never__'", $fieldCode);
        }

        return implode(' OR ', array_keys($conditions));
    }

    /**
     * @return list<string>
     */
    private function placeholderTerms(): array
    {
        $configured = (array) config('oqlike.admin_pack_placeholder_terms', self::DEFAULT_TERMS);
        $minLength = max(1, (int) config('oqlike.admin_pack_placeholder_min_term_length', 2));
        $terms = [];

        foreach ($configured as $term) {
            if (! is_string($term)) {
                continue;
            }

            $term = trim($term);

            if ($term === '') {
                continue;
            }

            if (mb_strlen($term) < $minLength) {
                continue;
            }

            $terms[$term] = true;
        }

        return array_keys($terms);
    }

    /**
     * @return list<string>
     */
    private function termVariants(string $term): array
    {
        $lower = mb_strtolower($term);
        $variants = [
            $term,
            $lower,
            mb_strtoupper($term),
            mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8'),
        ];
        $unique = [];

        foreach ($variants as $variant) {
            $variant = trim($variant);

            if ($variant === '') {
                continue;
            }

            $unique[$variant] = true;
        }

        return array_keys($unique);
    }
}
