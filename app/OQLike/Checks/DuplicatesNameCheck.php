<?php

namespace App\OQLike\Checks;

use App\OQLike\Scanning\ScanContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

class DuplicatesNameCheck extends AbstractCheck
{
    public function issueCode(): string
    {
        return 'DUPLICATES_NAME';
    }

    public function appliesTo(string $className, array $classMeta, ScanContext $context): bool
    {
        $skippedClasses = (array) config('oqlike.duplicates_skip_classes', []);

        if (in_array($className, $skippedClasses, true)) {
            return false;
        }

        return $this->resolveNameField($context, $className) !== null;
    }

    public function run(string $className, array $classMeta, ScanContext $context): ?array
    {
        $nameField = $this->resolveNameField($context, $className);

        if ($nameField === null) {
            return null;
        }

        $groupFields = array_values(array_filter([
            $context->attributeByCode($className, 'org_id') ? 'org_id' : null,
            $context->attributeByCode($className, 'location_id') ? 'location_id' : null,
        ]));

        $scope = $context->classScopeKey($className);
        $hardCap = max(1, (int) config('oqlike.max_duplicate_scan_records', 10000));
        $maxRecords = (int) ($scope['max_records'] ?? $hardCap);
        $maxRecords = min($maxRecords, $hardCap);
        $globalPageSize = max(1, (int) config('oqlike.page_size', 250));
        $duplicatesPageSize = max(1, (int) config('oqlike.duplicates_page_size', 25));
        $pageSize = min($globalPageSize, $duplicatesPageSize);
        // Keep payload small: duplicates check only needs id + grouping fields.
        $outputFields = array_values(array_unique(array_merge(['id', $nameField], $groupFields)));
        $memoryLimitBytes = $this->memoryLimitBytes();
        $memoryGuardRatio = 0.80;
        $memoryGuardTriggered = false;
        $store = null;
        $evaluatedCount = 0;
        $offset = 0;
        $affectedCount = 0;
        $duplicateGroupCount = 0;
        $samples = [];

        try {
            $store = $this->openDiskStore();
        } catch (Throwable $exception) {
            Log::warning('DuplicatesNameCheck failed to open disk store.', [
                'class' => $className,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }

        try {
            while ($evaluatedCount < $maxRecords) {
                $limit = min($pageSize, $maxRecords - $evaluatedCount);
                $objects = $context->itopClient->coreGet($className, (string) $scope['key'], $outputFields, $limit, $offset);

                if ($objects === []) {
                    break;
                }

                $evaluatedCount += count($objects);

                $store['pdo']->beginTransaction();

                foreach ($objects as $object) {
                    $fields = Arr::get($object, 'fields', []);

                    if (! is_array($fields)) {
                        continue;
                    }

                    $rawName = trim((string) Arr::get($fields, $nameField, ''));

                    if ($rawName === '') {
                        continue;
                    }

                    $groupKeySource = mb_strtolower($rawName);

                    foreach ($groupFields as $field) {
                        $groupKeySource .= '|'.(string) Arr::get($fields, $field, '');
                    }

                    // Hash long grouping keys to avoid retaining very large strings in memory.
                    $groupKey = hash('sha256', $groupKeySource);
                    $id = (string) Arr::get($object, 'id', Arr::get($object, 'fields.id'));
                    $displayName = Str::limit((string) (Arr::get($fields, $nameField, '') ?: sprintf('%s::%s', $className, $id)), 200);

                    $store['upsert']->execute([
                        ':k' => $groupKey,
                        ':sid' => $id,
                        ':sname' => $displayName,
                    ]);
                }

                $store['pdo']->commit();
                $objectCount = count($objects);
                unset($objects);

                if ($objectCount < $limit) {
                    break;
                }

                $offset += $limit;

                if (
                    $memoryLimitBytes !== null &&
                    memory_get_usage(true) >= (int) floor($memoryLimitBytes * $memoryGuardRatio)
                ) {
                    $memoryGuardTriggered = true;
                    break;
                }
            }

            $summaryRow = $store['pdo']
                ->query('SELECT COALESCE(SUM(cnt), 0) AS affected_count, COUNT(*) AS duplicate_group_count FROM dup_groups WHERE cnt > 1')
                ?->fetch(PDO::FETCH_ASSOC);

            $affectedCount = (int) ($summaryRow['affected_count'] ?? 0);
            $duplicateGroupCount = (int) ($summaryRow['duplicate_group_count'] ?? 0);

            if ($affectedCount > 0) {
                $stmt = $store['pdo']->prepare(
                    'SELECT sample1_id, sample1_name, sample2_id, sample2_name
                     FROM dup_groups
                     WHERE cnt > 1
                     LIMIT :limit'
                );
                $stmt->bindValue(':limit', max(1, $context->maxSamples), PDO::PARAM_INT);
                $stmt->execute();

                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $sample1Id = (string) ($row['sample1_id'] ?? '');
                    $sample2Id = (string) ($row['sample2_id'] ?? '');

                    if ($sample1Id !== '' && count($samples) < $context->maxSamples) {
                        $samples[] = [
                            'class' => $className,
                            'id' => $sample1Id,
                            'name' => (string) ($row['sample1_name'] ?? sprintf('%s::%s', $className, $sample1Id)),
                            'link' => $context->itopClient->itopObjectUrl($className, $sample1Id),
                        ];
                    }

                    if ($sample2Id !== '' && count($samples) < $context->maxSamples) {
                        $samples[] = [
                            'class' => $className,
                            'id' => $sample2Id,
                            'name' => (string) ($row['sample2_name'] ?? sprintf('%s::%s', $className, $sample2Id)),
                            'link' => $context->itopClient->itopObjectUrl($className, $sample2Id),
                        ];
                    }

                    if (count($samples) >= $context->maxSamples) {
                        break;
                    }
                }
            }
        } catch (Throwable $exception) {
            if ($store !== null && $store['pdo']->inTransaction()) {
                $store['pdo']->rollBack();
            }
            Log::warning('DuplicatesNameCheck failed while evaluating duplicates.', [
                'class' => $className,
                'error' => $exception->getMessage(),
                'evaluated_count' => $evaluatedCount,
                'scan_cap' => $maxRecords,
            ]);
            return null;
        } finally {
            $this->closeDiskStore($store);
        }

        $condition = sprintf("%s != ''", $nameField);

        return $this->buildIssue(
            code: 'DUPLICATES_NAME',
            title: sprintf('%s: valeurs dupliquées pour %s', $className, $nameField),
            domain: 'consistency',
            severity: 'warn',
            impact: 3,
            affectedCount: $affectedCount,
            samples: $samples,
            recommendation: 'Fusionner les doublons ou imposer l’unicité via des conventions de nommage et des règles de réconciliation.',
            suggestedOql: $this->oqlClassScope($className, $condition),
            meta: [
                'class' => $className,
                'name_field' => $nameField,
                'grouped_by' => $groupFields,
                'duplicate_groups' => $duplicateGroupCount,
                'delta_applied' => (bool) $scope['delta_applied'],
                'warning' => $scope['warning'],
                'evaluated_count' => $evaluatedCount,
                'scan_cap' => $maxRecords,
                'memory_guard_triggered' => $memoryGuardTriggered,
                'memory_guard_ratio' => $memoryGuardRatio,
                'disk_store' => true,
            ],
        );
    }

    private function resolveNameField(ScanContext $context, string $className): ?string
    {
        if ($context->attributeByCode($className, 'name') !== null) {
            return 'name';
        }

        if ($context->attributeByCode($className, 'friendlyname') !== null) {
            return 'friendlyname';
        }

        return null;
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = ini_get('memory_limit');

        if (! is_string($raw) || $raw === '' || $raw === '-1') {
            return null;
        }

        $raw = trim($raw);
        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $raw,
        };
    }

    /**
     * @return array{pdo: PDO, upsert: \PDOStatement, path: string}
     */
    private function openDiskStore(): array
    {
        $directory = trim((string) config('oqlike.connector_temp_dir', ''));

        if ($directory === '') {
            throw new RuntimeException('Le répertoire temporaire du stockage des doublons est vide.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Impossible de créer le répertoire temporaire: %s', $directory));
        }

        $path = tempnam($directory, 'oqlike_dup_');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException(sprintf('Impossible d’allouer un fichier temporaire dans: %s', $directory));
        }

        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = OFF;');
        $pdo->exec('PRAGMA synchronous = OFF;');
        $pdo->exec('PRAGMA temp_store = FILE;');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS dup_groups (
                k TEXT PRIMARY KEY,
                cnt INTEGER NOT NULL DEFAULT 0,
                sample1_id TEXT NULL,
                sample1_name TEXT NULL,
                sample2_id TEXT NULL,
                sample2_name TEXT NULL
            );'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dup_groups_cnt ON dup_groups (cnt);');

        $upsert = $pdo->prepare(
            'INSERT INTO dup_groups (k, cnt, sample1_id, sample1_name)
             VALUES (:k, 1, :sid, :sname)
             ON CONFLICT(k) DO UPDATE SET
                 cnt = dup_groups.cnt + 1,
                 sample2_id = CASE
                     WHEN dup_groups.sample2_id IS NULL
                          AND dup_groups.sample1_id IS NOT NULL
                          AND dup_groups.sample1_id <> excluded.sample1_id
                     THEN excluded.sample1_id
                     ELSE dup_groups.sample2_id
                 END,
                 sample2_name = CASE
                     WHEN dup_groups.sample2_id IS NULL
                          AND dup_groups.sample1_id IS NOT NULL
                          AND dup_groups.sample1_id <> excluded.sample1_id
                     THEN excluded.sample1_name
                     ELSE dup_groups.sample2_name
                 END'
        );

        return [
            'pdo' => $pdo,
            'upsert' => $upsert,
            'path' => $path,
        ];
    }

    /**
     * @param array{pdo: PDO, upsert: \PDOStatement, path: string}|null $store
     */
    private function closeDiskStore(?array $store): void
    {
        if ($store === null) {
            return;
        }

        $path = (string) ($store['path'] ?? '');

        unset($store['upsert'], $store['pdo']);

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
