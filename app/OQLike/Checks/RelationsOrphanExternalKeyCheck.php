<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class RelationsOrphanExternalKeyCheck extends AbstractCheck
{
    public function issueCode(): string
    {
        return 'RELATIONS_ORPHAN_EXTERNALKEY';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        if (! (bool) config('oqlike.admin_pack_enabled', true)) {
            return false;
        }

        if (! (bool) config('oqlike.admin_pack_orphan_check_enabled', true)) {
            return false;
        }

        if ($this->isClassExcluded($className)) {
            return false;
        }

        return $this->externalKeyAttributes($context, $className) !== [];
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $externalKeys = $this->externalKeyAttributes($context, $className);

        if ($externalKeys === []) {
            return null;
        }

        $scope = $context->classScopeKey($className);
        $maxRecords = $this->resolveEffectiveMaxRecords($scope, 'oqlike.admin_pack_orphan_max_records', 1200);

        if ($maxRecords === null) {
            $maxRecords = 1200;
        }

        $fields = array_values(array_unique(array_merge(
            ['id', 'friendlyname', 'name'],
            array_map(static fn (array $attr): string => (string) $attr['code'], $externalKeys)
        )));

        try {
            $rows = $context->itopClient->fetchObjects(
                $className,
                (string) ($scope['key'] ?? '1=1'),
                $fields,
                $maxRecords
            );
        } catch (Throwable $exception) {
            Log::warning('RelationsOrphanExternalKeyCheck failed while loading class objects.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        if ($rows === []) {
            return null;
        }

        $affectedRows = [];
        $attributesSummary = [];
        $conditionParts = [];

        foreach ($externalKeys as $attribute) {
            $fieldCode = (string) $attribute['code'];
            $targetClass = (string) $attribute['target_class'];

            if ($fieldCode === '' || $targetClass === '') {
                continue;
            }

            $referencedIds = [];

            foreach ($rows as $row) {
                $value = Arr::get($row, 'fields.'.$fieldCode);
                $numeric = (int) $value;

                if ($numeric > 0) {
                    $referencedIds[(string) $numeric] = true;
                }
            }

            if ($referencedIds === []) {
                continue;
            }

            $existingIds = [];

            foreach (array_chunk(array_keys($referencedIds), 100) as $chunk) {
                $inClause = implode(',', array_map(static fn (string $id): int => (int) $id, $chunk));

                try {
                    $targetRows = $context->itopClient->fetchObjects(
                        $targetClass,
                        sprintf('id IN (%s)', $inClause),
                        ['id'],
                        count($chunk)
                    );
                } catch (Throwable $exception) {
                    Log::warning('RelationsOrphanExternalKeyCheck failed while loading target references.', [
                        'class' => $className,
                        'field' => $fieldCode,
                        'target_class' => $targetClass,
                        'error' => $exception->getMessage(),
                    ]);
                    $targetRows = [];
                }

                foreach ($targetRows as $targetRow) {
                    $id = (string) Arr::get($targetRow, 'id', Arr::get($targetRow, 'fields.id', ''));

                    if ($id !== '') {
                        $existingIds[$id] = true;
                    }
                }
            }

            $orphanIds = array_diff(array_keys($referencedIds), array_keys($existingIds));

            if ($orphanIds === []) {
                continue;
            }

            $orphanSet = array_fill_keys($orphanIds, true);
            $affectedOnAttribute = 0;

            foreach ($rows as $row) {
                $rowId = (string) Arr::get($row, 'id', Arr::get($row, 'fields.id', ''));
                $targetId = (string) (int) Arr::get($row, 'fields.'.$fieldCode, 0);

                if ($rowId === '' || $targetId === '0' || ! isset($orphanSet[$targetId])) {
                    continue;
                }

                $affectedOnAttribute++;

                if (! isset($affectedRows[$rowId])) {
                    $name = (string) (Arr::get($row, 'friendlyname')
                        ?? Arr::get($row, 'fields.friendlyname')
                        ?? Arr::get($row, 'fields.name')
                        ?? sprintf('%s::%s', $className, $rowId));

                    $affectedRows[$rowId] = [
                        'class' => $className,
                        'id' => $rowId,
                        'name' => $name,
                        'link' => $context->itopClient->itopObjectUrl($className, $rowId),
                    ];
                }
            }

            $attributesSummary[] = [
                'field' => $fieldCode,
                'target_class' => $targetClass,
                'orphan_references' => count($orphanIds),
                'affected_rows' => $affectedOnAttribute,
            ];

            $conditionParts[] = sprintf('%s > 0', $fieldCode);
        }

        if ($affectedRows === []) {
            return null;
        }

        return $this->buildIssue(
            code: $this->issueCode(),
            title: sprintf('%s: relations externes orphelines detectees', $className),
            domain: 'relations',
            severity: 'crit',
            impact: 4,
            affectedCount: count($affectedRows),
            samples: array_slice(array_values($affectedRows), 0, $context->maxSamples),
            recommendation: 'Corriger les references externes casses (objets cibles supprimes/inexistants) et renforcer les controles de cycle de vie.',
            suggestedOql: $this->oqlClassScope($className, implode(' OR ', $conditionParts)),
            meta: [
                'class' => $className,
                'attributes_with_orphans' => $attributesSummary,
                'delta_applied' => (bool) ($scope['delta_applied'] ?? false),
                'warning' => $scope['warning'] ?? null,
                'max_records' => $maxRecords,
            ],
        );
    }

    /**
     * @return list<array{code:string,target_class:string}>
     */
    private function externalKeyAttributes(ScanContext $context, string $className): array
    {
        $priorityRaw = (array) config('oqlike.admin_pack_orphan_priority_fields', [
            'org_id',
            'location_id',
            'parent_id',
            'functionalci_id',
            'contact_id',
            'owner_id',
            'team_id',
        ]);
        $priority = [];

        foreach ($priorityRaw as $index => $fieldCode) {
            if (! is_string($fieldCode)) {
                continue;
            }

            $fieldCode = trim($fieldCode);

            if ($fieldCode === '' || isset($priority[$fieldCode])) {
                continue;
            }

            $priority[$fieldCode] = $index;
        }

        $attributes = [];

        foreach ($context->attributes($className) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            if (! (bool) Arr::get($attribute, 'is_external_key', false)) {
                continue;
            }

            $fieldCode = trim((string) Arr::get($attribute, 'code', ''));
            $targetClass = trim((string) Arr::get($attribute, 'target_class', ''));

            if ($fieldCode === '' || $targetClass === '') {
                continue;
            }

            $attributes[] = [
                'code' => $fieldCode,
                'target_class' => $targetClass,
            ];
        }

        usort($attributes, static function (array $a, array $b) use ($priority): int {
            $aRank = $priority[$a['code']] ?? 999;
            $bRank = $priority[$b['code']] ?? 999;

            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            return strcmp($a['code'], $b['code']);
        });

        $maxAttributes = max(1, (int) config('oqlike.admin_pack_orphan_max_attributes', 6));

        return array_slice($attributes, 0, $maxAttributes);
    }
}
