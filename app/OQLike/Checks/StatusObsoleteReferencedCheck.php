<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StatusObsoleteReferencedCheck extends AbstractCheck
{
    private const OBSOLETE_MATCHERS = ['obsolete', 'deprecated', 'retired', 'decommission'];

    public function issueCode(): string
    {
        return 'STATUS_OBSOLETE_REFERENCED';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        return $context->attributeByCode($className, 'status') !== null;
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $statusAttribute = $context->attributeByCode($className, 'status');

        if (! is_array($statusAttribute)) {
            return null;
        }

        $obsoleteValues = $this->resolveObsoleteValues($statusAttribute);

        if ($obsoleteValues === []) {
            return null;
        }

        $obsoleteCondition = implode(' OR ', array_map(
            fn (string $value) => sprintf("status = '%s'", addslashes($value)),
            $obsoleteValues
        ));

        $scope = $context->classScopeKey($className);
        $key = $scope['key'] === '1=1'
            ? $obsoleteCondition
            : sprintf('(%s) AND (%s)', $scope['key'], $obsoleteCondition);

        try {
            $obsoleteObjects = $context->itopClient->fetchObjects(
                $className,
                $key,
                ['id', 'friendlyname', 'name', 'status'],
                $scope['max_records'] ?? null
            );
        } catch (Throwable $exception) {
            Log::warning('StatusObsoleteReferencedCheck failed while loading obsolete objects.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        if ($obsoleteObjects === []) {
            return null;
        }

        $obsoleteMap = [];

        foreach ($obsoleteObjects as $object) {
            $id = (string) Arr::get($object, 'id', Arr::get($object, 'fields.id'));

            if ($id !== '') {
                $obsoleteMap[$id] = $object;
            }
        }

        $referencedIds = $this->findReferencedIds($context, $className, array_keys($obsoleteMap));

        if ($referencedIds === []) {
            return $this->buildIssue(
                code: 'STATUS_OBSOLETE_REFERENCED',
                title: sprintf('%s: statuts obsolètes détectés', $className),
                domain: 'obsolescence',
                severity: 'info',
                impact: 2,
                affectedCount: count($obsoleteMap),
                samples: array_slice(array_map(fn ($obj) => [
                    'class' => $className,
                    'id' => (string) Arr::get($obj, 'id', Arr::get($obj, 'fields.id')),
                    'name' => (string) (Arr::get($obj, 'friendlyname') ?? Arr::get($obj, 'fields.friendlyname') ?? Arr::get($obj, 'fields.name') ?? 'inconnu'),
                    'link' => $context->itopClient->itopObjectUrl($className, (string) Arr::get($obj, 'id', Arr::get($obj, 'fields.id'))),
                ], $obsoleteMap), 0, $context->maxSamples),
                recommendation: 'Revoir les enregistrements obsolètes et vérifier s’ils peuvent être archivés ou supprimés.',
                suggestedOql: $this->oqlClassScope($className, $obsoleteCondition),
                meta: [
                    'class' => $className,
                    'reference_check' => 'best_effort_no_relations',
                    'obsolete_values' => $obsoleteValues,
                ],
            );
        }

        $samples = [];

        foreach ($referencedIds as $id) {
            if (count($samples) >= $context->maxSamples) {
                break;
            }

            $object = $obsoleteMap[$id] ?? null;

            if (! is_array($object)) {
                continue;
            }

            $samples[] = [
                'class' => $className,
                'id' => $id,
                'name' => (string) (Arr::get($object, 'friendlyname')
                    ?? Arr::get($object, 'fields.friendlyname')
                    ?? Arr::get($object, 'fields.name')
                    ?? sprintf('%s::%s', $className, $id)),
                'link' => $context->itopClient->itopObjectUrl($className, $id),
            ];
        }

        return $this->buildIssue(
            code: 'STATUS_OBSOLETE_REFERENCED',
            title: sprintf('%s: enregistrements obsolètes encore référencés', $className),
            domain: 'obsolescence',
            severity: 'warn',
            impact: 4,
            affectedCount: count($referencedIds),
            samples: $samples,
            recommendation: 'Remplacer les dépendances vers les objets obsolètes avant le retrait définitif.',
            suggestedOql: $this->oqlClassScope($className, $obsoleteCondition),
            meta: [
                'class' => $className,
                'obsolete_values' => $obsoleteValues,
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
            ],
        );
    }

    private function resolveObsoleteValues(array $statusAttribute): array
    {
        $enumValues = Arr::get($statusAttribute, 'enum_values', []);

        if (! is_array($enumValues) || $enumValues === []) {
            return ['obsolete', 'deprecated', 'retired'];
        }

        $selected = [];

        foreach ($enumValues as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = Str::lower($value);

            foreach (self::OBSOLETE_MATCHERS as $matcher) {
                if (Str::contains($normalized, $matcher)) {
                    $selected[] = $value;
                    break;
                }
            }
        }

        return array_values(array_unique($selected));
    }

    private function findReferencedIds(ScanContext $context, string $targetClass, array $obsoleteIds): array
    {
        if ($obsoleteIds === []) {
            return [];
        }

        $relations = [];

        foreach ($context->classes() as $className => $classMeta) {
            foreach ($context->attributes((string) $className) as $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }

                if (! (bool) Arr::get($attribute, 'is_external_key', false)) {
                    continue;
                }

                if ((string) Arr::get($attribute, 'target_class', '') !== $targetClass) {
                    continue;
                }

                $relations[] = [
                    'class' => (string) $className,
                    'attribute' => (string) Arr::get($attribute, 'code'),
                ];
            }
        }

        if ($relations === []) {
            return [];
        }

        $referenced = [];
        $chunks = array_chunk($obsoleteIds, 100);

        foreach ($relations as $relation) {
            foreach ($chunks as $chunk) {
                $inClause = implode(',', array_map(static fn (string $id) => (int) $id, $chunk));
                $key = sprintf('%s IN (%s)', $relation['attribute'], $inClause);

                try {
                    $rows = $context->itopClient->fetchObjects(
                        $relation['class'],
                        $key,
                        ['id', $relation['attribute']],
                        5000
                    );
                } catch (Throwable $exception) {
                    Log::warning('StatusObsoleteReferencedCheck relation fetch failed.', [
                        'target_class' => $targetClass,
                        'relation_class' => $relation['class'],
                        'relation_attribute' => $relation['attribute'],
                        'error' => $exception->getMessage(),
                    ]);
                    continue;
                }

                foreach ($rows as $row) {
                    $targetId = (string) Arr::get($row, 'fields.'.$relation['attribute'], '');

                    if ($targetId !== '' && in_array($targetId, $chunk, true)) {
                        $referenced[$targetId] = true;
                    }
                }
            }
        }

        return array_keys($referenced);
    }
}
