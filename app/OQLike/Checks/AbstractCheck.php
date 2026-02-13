<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;

abstract class AbstractCheck implements CheckInterface
{
    protected function buildIssue(
        string $code,
        string $title,
        string $domain,
        string $severity,
        int $impact,
        int $affectedCount,
        array $samples,
        string $recommendation,
        string $suggestedOql,
        array $meta = []
    ): ?array {
        if ($affectedCount <= 0) {
            return null;
        }

        return [
            'code' => $code,
            'title' => $title,
            'domain' => $domain,
            'severity' => $severity,
            'impact' => max(1, min(5, $impact)),
            'affected_count' => $affectedCount,
            'samples' => $samples,
            'recommendation' => $recommendation,
            'suggested_oql' => $suggestedOql,
            'meta' => $meta,
        ];
    }

    protected function attributesByFilter(ScanContext $context, string $className, callable $predicate): array
    {
        $selected = [];

        foreach ($context->attributes($className) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            if ($predicate($attribute) === true) {
                $selected[] = $attribute;
            }
        }

        return $selected;
    }

    protected function oqlClassScope(string $className, string $condition): string
    {
        return sprintf('SELECT %s WHERE %s', $className, $condition);
    }

    protected function scalarAttribute(array $attribute): bool
    {
        $type = strtolower((string) Arr::get($attribute, 'type', 'string'));

        return ! in_array($type, ['externalfield', 'attributeblob', 'json', 'html'], true);
    }

    protected function missingFieldCondition(string $fieldCode, ?array $attribute = null): string
    {
        $isExternalKey = is_array($attribute)
            ? (bool) Arr::get($attribute, 'is_external_key', false)
            : false;

        if ($isExternalKey) {
            return sprintf('(ISNULL(%s) OR %s = 0)', $fieldCode, $fieldCode);
        }

        return sprintf("(ISNULL(%s) OR %s = '')", $fieldCode, $fieldCode);
    }

    protected function resolveEffectiveMaxRecords(
        array $scope,
        string $configKey,
        int $defaultCap
    ): ?int {
        $scopeCap = $scope['max_records'] ?? null;
        $scopeCap = is_numeric($scopeCap) ? max(1, (int) $scopeCap) : null;

        $configuredCap = (int) config($configKey, $defaultCap);
        $configuredCap = $configuredCap > 0 ? max(1, $configuredCap) : null;

        if ($scopeCap === null) {
            return $configuredCap;
        }

        if ($configuredCap === null) {
            return $scopeCap;
        }

        return min($scopeCap, $configuredCap);
    }

    protected function isClassExcluded(string $className, string $configKey = 'oqlike.admin_pack_excluded_classes'): bool
    {
        $excluded = (array) config($configKey, []);

        return in_array($className, $excluded, true);
    }
}
