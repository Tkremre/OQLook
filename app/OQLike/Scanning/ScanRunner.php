<?php

namespace App\OQLike\Scanning;

use App\Models\Connection;
use App\Models\Issue;
use App\Models\IssueAcknowledgement;
use App\Models\IssueObjectAcknowledgement;
use App\Models\Scan;
use App\OQLike\Checks\ClassificationMissingCheck;
use App\OQLike\Checks\CompletenessMandatoryEmptyCheck;
use App\OQLike\Checks\DuplicatesNameCheck;
use App\OQLike\Checks\NamePlaceholderCheck;
use App\OQLike\Checks\OrgLocationConsistencyCheck;
use App\OQLike\Checks\OwnershipMissingCheck;
use App\OQLike\Checks\RelationsOrphanExternalKeyCheck;
use App\OQLike\Checks\RelationsMissingExternalKeyCheck;
use App\OQLike\Checks\StaleWithoutOwnerCheck;
use App\OQLike\Checks\StatusEmptyCheck;
use App\OQLike\Checks\StatusObsoleteReferencedCheck;
use App\OQLike\Checks\StalenessLastUpdateCheck;
use App\OQLike\Clients\ItopClient;
use App\OQLike\Discovery\MetamodelDiscoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ScanRunner
{
    public function __construct(
        private readonly MetamodelDiscoveryService $metamodelDiscoveryService,
        private readonly ScoringService $scoringService,
    ) {
    }

    public function run(
        Connection $connection,
        string $mode = 'delta',
        array $classes = [],
        ?int $thresholdDays = null,
        bool $forceSelectedClasses = false
    ): Scan
    {
        $runStartedAt = microtime(true);
        $mode = in_array($mode, ['delta', 'full'], true) ? $mode : 'delta';
        $thresholdDays ??= (int) config('oqlike.default_threshold_days', 365);

        $scan = DB::transaction(function () use ($connection, $mode): Scan {
            return $connection->scans()->create([
                'started_at' => now(),
                'mode' => $mode,
            ]);
        });

        Log::info('Scan démarré', [
            'scan_id' => $scan->id,
            'connection_id' => $connection->id,
            'mode' => $mode,
            'selected_classes_count' => count($classes),
            'force_selected_classes' => $forceSelectedClasses,
            'threshold_days' => $thresholdDays,
        ]);

        $scanWarnings = [];
        $classSummaries = [];
        $checkSummaries = [];
        $issueWarnings = [];
        $issuesByDomain = [];
        $issuesBySeverity = [];
        $issuesByCode = [];
        $topIssueRecords = [];
        $scoreByDomain = [];
        $issueCount = 0;
        $totalAffected = 0;
        $ackSkippedCount = 0;
        $acknowledgedIssueCodes = $this->loadAcknowledgedIssueCodes($connection);
        $acknowledgedIssueObjects = $this->loadAcknowledgedIssueObjects($connection);
        $acknowledgedIssueObjectRulesCount = $this->countAcknowledgedIssueObjectRules($acknowledgedIssueObjects);
        $ackSkippedObjectsCount = 0;
        $acknowledgedObjectMatchCache = [];
        $temporaryMetamodelFile = null;
        $classNames = [];
        $totalClassCount = 0;
        $completedClassNames = [];
        $heartbeatIntervalMs = max(1, (int) config('oqlike.scan_heartbeat_interval_s', 10)) * 1000;
        $lastHeartbeatWriteAtMs = 0;
        $runningSummary = [
            'status' => 'running',
            'mode' => $mode,
            'issue_count' => 0,
            'total_affected' => 0,
            'classes_count' => 0,
            'classes_scanned' => [],
            'scan_parameters' => [
                'threshold_days' => $thresholdDays,
                'selected_classes' => $classes,
                'force_selected_classes' => $forceSelectedClasses,
                'last_scan_at' => $mode === 'delta' ? $connection->last_scan_time?->toImmutable()?->toIso8601String() : null,
            ],
            'progress' => [
                'phase' => 'initializing',
                'class_index' => 0,
                'classes_total' => 0,
                'classes_completed' => 0,
                'current_class' => null,
                'current_check' => null,
                'last_completed_class' => null,
                'last_update_at' => now()->toIso8601String(),
            ],
            'resume' => [
                'planned_classes' => [],
                'completed_classes' => [],
                'current_class' => null,
                'next_class' => null,
                'remaining_count' => 0,
                'remaining_classes' => [],
            ],
            'watchdog' => [
                'last_heartbeat_at' => now()->toIso8601String(),
                'last_event' => 'scan_started',
                'stale_after_seconds' => max(60, (int) config('oqlike.watchdog_stale_seconds', 900)),
            ],
        ];
        $this->persistRunningSummary($scan, $runningSummary);
        $lastHeartbeatWriteAtMs = (int) round(microtime(true) * 1000);

        try {
            $discoveryStartedAt = microtime(true);
            Log::info('Découverte du métamodèle démarrée', [
                'scan_id' => $scan->id,
                'connection_id' => $connection->id,
                'target_classes_count' => count($classes),
            ]);
            $metamodel = $this->metamodelDiscoveryService->discover($connection, $classes);
            Log::info('Découverte du métamodèle terminée', [
                'scan_id' => $scan->id,
                'connection_id' => $connection->id,
                'duration_ms' => (int) round((microtime(true) - $discoveryStartedAt) * 1000),
                'source' => Arr::get($metamodel, 'source'),
                'source_detail' => Arr::get($metamodel, 'source_detail'),
                'classes_count' => count((array) Arr::get($metamodel, 'classes', [])),
                'discovery_error' => Arr::get($metamodel, 'discovery_error'),
            ]);
            $temporaryMetamodelFile = Arr::get($metamodel, 'classes_jsonl_path');
            $discoveredClassNames = array_keys((array) Arr::get($metamodel, 'classes', []));
            $context = new ScanContext(
                connection: $connection,
                itopClient: new ItopClient($connection),
                metamodel: $metamodel,
                mode: $mode,
                thresholdDays: $thresholdDays,
                lastScanAt: $mode === 'delta' ? $connection->last_scan_time?->toImmutable() : null,
                selectedClasses: $classes,
                forceSelectedClasses: $forceSelectedClasses,
                acknowledgedChecks: $acknowledgedIssueCodes
            );

            $classesToScan = $context->classes();
            $classNames = array_keys($classesToScan);
            $totalClassCount = count($classNames);
            $maxClassDurationMs = max(0, (int) config('oqlike.max_class_duration_s', 0)) * 1000;
            $maxCheckDurationMs = max(0, (int) config('oqlike.max_check_duration_s', 0)) * 1000;
            $this->heartbeatRunningSummary(
                $scan,
                $runningSummary,
                [
                    'classes_count' => $totalClassCount,
                    'classes_scanned' => $classNames,
                    'progress.phase' => 'scanning',
                    'progress.class_index' => 0,
                    'progress.classes_total' => $totalClassCount,
                    'progress.classes_completed' => 0,
                    'resume.planned_classes' => $classNames,
                    'resume.completed_classes' => [],
                    'resume.current_class' => null,
                    'resume.next_class' => $classNames[0] ?? null,
                    'resume.remaining_count' => $totalClassCount,
                    'resume.remaining_classes' => $classNames,
                ],
                'scan_ready',
                $lastHeartbeatWriteAtMs,
                $heartbeatIntervalMs,
                true
            );

            if ($classesToScan === []) {
                $scanWarnings[] = 'Aucune classe persistante découverte depuis le métamodèle/repli. Vérifiez le connecteur, les droits iTop ou les filtres de classes.';
            }

            $classIndex = 0;
            $deltaStrictSkippedClasses = [];

            foreach ($classesToScan as $className => $classMeta) {
                $classIndex++;
                $classStartedAt = microtime(true);
                $scope = $context->classScopeKey($className);

                Log::info('Classe de scan démarrée', [
                    'scan_id' => $scan->id,
                    'connection_id' => $connection->id,
                    'class' => $className,
                    'class_index' => $classIndex,
                    'classes_total' => $totalClassCount,
                    'mode' => $mode,
                ]);

                $this->heartbeatRunningSummary(
                    $scan,
                    $runningSummary,
                    [
                        'progress.class_index' => $classIndex,
                        'progress.classes_total' => $totalClassCount,
                        'progress.current_class' => $className,
                        'progress.current_check' => null,
                        'resume.current_class' => $className,
                        'resume.next_class' => $className,
                        'resume.remaining_count' => max(0, $totalClassCount - count($completedClassNames)),
                    ],
                    'class_started',
                    $lastHeartbeatWriteAtMs,
                    $heartbeatIntervalMs
                );

                $classSummary = [
                    'class' => $className,
                    'delta_applied' => (bool) ($scope['delta_applied'] ?? false),
                    'delta_field' => $context->resolveDeltaField($className),
                    'scope_key' => (string) ($scope['key'] ?? '1=1'),
                    'max_records' => $scope['max_records'] ?? null,
                    'checks_applicable' => 0,
                    'checks_executed' => 0,
                    'checks_skipped_ack' => 0,
                    'issues_found' => 0,
                    'issue_codes' => [],
                    'acknowledged_issue_codes' => [],
                    'warnings' => [],
                    'errors' => [],
                    'skipped' => false,
                    'skip_reason' => null,
                    'duration_ms' => 0,
                ];

                if ((bool) ($scope['skip_class'] ?? false)) {
                    $classSummary['skipped'] = true;
                    $classSummary['skip_reason'] = (string) ($scope['skip_reason'] ?? 'Classe ignorée.');

                    if ($context->mode === 'delta') {
                        $deltaStrictSkippedClasses[] = $className;
                    } else {
                        $classSummary['warnings'][] = $classSummary['skip_reason'];
                        $scanWarnings[] = $classSummary['skip_reason'];
                    }

                    $classSummary['duration_ms'] = (int) round((microtime(true) - $classStartedAt) * 1000);
                    $classSummaries[] = $classSummary;

                    Log::info('Classe de scan ignorée', [
                        'scan_id' => $scan->id,
                        'connection_id' => $connection->id,
                        'class' => $className,
                        'class_index' => $classIndex,
                        'classes_total' => $totalClassCount,
                        'reason' => $classSummary['skip_reason'],
                    ]);

                    $completedClassNames[] = $className;
                    $nextClass = $classNames[$classIndex] ?? null;
                    $this->heartbeatRunningSummary(
                        $scan,
                        $runningSummary,
                        [
                            'issue_count' => $issueCount,
                            'total_affected' => $totalAffected,
                            'progress.classes_completed' => count($completedClassNames),
                            'progress.last_completed_class' => $className,
                            'progress.current_class' => $nextClass,
                            'progress.current_check' => null,
                            'resume.completed_classes' => array_values(array_unique($completedClassNames)),
                            'resume.current_class' => $nextClass,
                            'resume.next_class' => $nextClass,
                            'resume.remaining_count' => max(0, $totalClassCount - count($completedClassNames)),
                            'resume.remaining_classes' => array_values(array_slice($classNames, $classIndex)),
                        ],
                        'class_skipped',
                        $lastHeartbeatWriteAtMs,
                        $heartbeatIntervalMs
                    );

                    continue;
                }

                if (is_string($scope['warning']) && $scope['warning'] !== '') {
                    $scanWarnings[] = $scope['warning'];
                    $classSummary['warnings'][] = $scope['warning'];
                }

                foreach ($this->checks() as $check) {
                    $checkName = class_basename($check);
                    $classElapsedMs = (int) round((microtime(true) - $classStartedAt) * 1000);

                    if ($maxClassDurationMs > 0 && $classElapsedMs >= $maxClassDurationMs) {
                        $warning = sprintf(
                            'Temps limite souple atteint pour la classe %s après %d ms, contrôles restants ignorés.',
                            $className,
                            $classElapsedMs
                        );
                        $classSummary['warnings'][] = $warning;
                        $scanWarnings[] = $warning;

                        Log::warning('Temps limite souple atteint pour une classe de scan', [
                            'scan_id' => $scan->id,
                            'connection_id' => $connection->id,
                            'class' => $className,
                            'class_index' => $classIndex,
                            'classes_total' => $totalClassCount,
                            'elapsed_ms' => $classElapsedMs,
                            'max_class_duration_ms' => $maxClassDurationMs,
                        ]);

                        break;
                    }

                    if (! array_key_exists($checkName, $checkSummaries)) {
                        $checkSummaries[$checkName] = [
                            'check' => $checkName,
                            'applicable_count' => 0,
                            'executed_count' => 0,
                            'ack_skipped_count' => 0,
                            'issues_found' => 0,
                            'issue_codes' => [],
                            'error_count' => 0,
                        ];
                    }

                    $issueCode = $check->issueCode();

                    if ($context->isIssueAcknowledged($className, $issueCode)) {
                        $classSummary['checks_skipped_ack']++;
                        $classSummary['acknowledged_issue_codes'][$issueCode] = true;
                        $checkSummaries[$checkName]['ack_skipped_count']++;
                        $ackSkippedCount++;
                        continue;
                    }

                    if (! $check->appliesTo($className, $classMeta, $context)) {
                        continue;
                    }

                    $classSummary['checks_applicable']++;
                    $checkSummaries[$checkName]['applicable_count']++;
                    $checkStartedAt = microtime(true);
                    $checkStatus = 'ok';
                    $checkProducedIssue = false;
                    $checkError = null;

                    Log::info('Contrôle de scan démarré', [
                        'scan_id' => $scan->id,
                        'connection_id' => $connection->id,
                        'class' => $className,
                        'check' => $checkName,
                        'class_index' => $classIndex,
                        'classes_total' => $totalClassCount,
                    ]);

                    $this->heartbeatRunningSummary(
                        $scan,
                        $runningSummary,
                        [
                            'issue_count' => $issueCount,
                            'total_affected' => $totalAffected,
                            'progress.current_class' => $className,
                            'progress.current_check' => $checkName,
                            'resume.current_class' => $className,
                        ],
                        'check_started',
                        $lastHeartbeatWriteAtMs,
                        $heartbeatIntervalMs
                    );

                    try {
                        $classSummary['checks_executed']++;
                        $checkSummaries[$checkName]['executed_count']++;

                        $issue = $check->run($className, $classMeta, $context);

                        if (is_array($issue)) {
                            $issue = $this->applyAcknowledgedObjectFilter(
                                $issue,
                                $context,
                                $acknowledgedIssueObjects,
                                $ackSkippedObjectsCount,
                                $issueWarnings,
                                $acknowledgedObjectMatchCache
                            );
                        }

                        if (is_array($issue)) {
                            $this->persistIssue($scan, $issue);
                            $this->accumulateIssueStats(
                                $issue,
                                $issuesByDomain,
                                $issuesBySeverity,
                                $issuesByCode,
                                $topIssueRecords,
                                $scoreByDomain,
                                $issueWarnings,
                                $issueCount,
                                $totalAffected
                            );

                            $classSummary['issues_found']++;

                            $issueCode = (string) Arr::get($issue, 'code', '');
                            if ($issueCode !== '') {
                                $classSummary['issue_codes'][$issueCode] = true;
                                $checkSummaries[$checkName]['issue_codes'][$issueCode] = true;
                            }

                            $checkSummaries[$checkName]['issues_found']++;
                            $checkProducedIssue = true;
                        }
                    } catch (Throwable $exception) {
                        $checkStatus = 'error';
                        $checkError = $exception->getMessage();
                        Log::warning('Échec d’exécution du contrôle', [
                            'check' => get_class($check),
                            'class' => $className,
                            'scan_id' => $scan->id,
                            'error' => $exception->getMessage(),
                        ]);

                        $classSummary['errors'][] = [
                            'check' => $checkName,
                            'message' => Str::limit($exception->getMessage(), 500),
                        ];

                        $checkSummaries[$checkName]['error_count']++;
                    } finally {
                        $checkDurationMs = (int) round((microtime(true) - $checkStartedAt) * 1000);

                        if ($maxCheckDurationMs > 0 && $checkDurationMs > $maxCheckDurationMs) {
                            $warning = sprintf(
                                'Le contrôle %s sur %s a pris %d ms (limite souple %d ms).',
                                $checkName,
                                $className,
                                $checkDurationMs,
                                $maxCheckDurationMs
                            );
                            $classSummary['warnings'][] = $warning;
                            $scanWarnings[] = $warning;
                        }

                        Log::info('Contrôle de scan terminé', [
                            'scan_id' => $scan->id,
                            'connection_id' => $connection->id,
                            'class' => $className,
                            'check' => $checkName,
                            'status' => $checkStatus,
                            'issue_found' => $checkProducedIssue,
                            'duration_ms' => $checkDurationMs,
                            'error' => $checkError,
                        ]);
                    }
                }

                $classSummary['issue_codes'] = array_values(array_keys($classSummary['issue_codes']));
                $classSummary['acknowledged_issue_codes'] = array_values(array_keys($classSummary['acknowledged_issue_codes']));
                $classSummary['duration_ms'] = (int) round((microtime(true) - $classStartedAt) * 1000);
                $classSummaries[] = $classSummary;
                $completedClassNames[] = $className;
                $nextClass = $classNames[$classIndex] ?? null;

                Log::info('Classe de scan terminée', [
                    'scan_id' => $scan->id,
                    'connection_id' => $connection->id,
                    'class' => $className,
                    'class_index' => $classIndex,
                    'classes_total' => $totalClassCount,
                    'issues_found' => (int) $classSummary['issues_found'],
                    'checks_executed' => (int) $classSummary['checks_executed'],
                    'checks_applicable' => (int) $classSummary['checks_applicable'],
                    'checks_skipped_ack' => (int) $classSummary['checks_skipped_ack'],
                    'duration_ms' => (int) $classSummary['duration_ms'],
                ]);
                $this->heartbeatRunningSummary(
                    $scan,
                    $runningSummary,
                    [
                        'issue_count' => $issueCount,
                        'total_affected' => $totalAffected,
                        'progress.classes_completed' => count($completedClassNames),
                        'progress.last_completed_class' => $className,
                        'progress.current_class' => $nextClass,
                        'progress.current_check' => null,
                        'resume.completed_classes' => array_values(array_unique($completedClassNames)),
                        'resume.current_class' => $nextClass,
                        'resume.next_class' => $nextClass,
                        'resume.remaining_count' => max(0, $totalClassCount - count($completedClassNames)),
                        'resume.remaining_classes' => array_values(array_slice($classNames, $classIndex)),
                    ],
                    'class_completed',
                    $lastHeartbeatWriteAtMs,
                    $heartbeatIntervalMs
                );
            }

            if ($deltaStrictSkippedClasses !== []) {
                $scanWarnings[] = sprintf(
                    'Mode delta strict: %d classe(s) ignorée(s) faute de champ last_update-like: %s. Désactivez OQLIKE_DELTA_STRICT_MODE pour fallback complet limité.',
                    count($deltaStrictSkippedClasses),
                    implode(', ', array_slice($deltaStrictSkippedClasses, 0, 15))
                );
            }

            $scores = $this->scoringService->computeFromDomainStats($scoreByDomain);
            $summary = $this->buildSummary(
                $context,
                $metamodel,
                $scanWarnings,
                $classSummaries,
                $checkSummaries,
                (int) round((microtime(true) - $runStartedAt) * 1000),
                $issueCount,
                $totalAffected,
                $issuesByDomain,
                $issuesBySeverity,
                $issuesByCode,
                $topIssueRecords,
                $issueWarnings,
                count($acknowledgedIssueCodes),
                $ackSkippedCount,
                $acknowledgedIssueObjectRulesCount,
                $ackSkippedObjectsCount,
                $forceSelectedClasses
            );
            $summary['resume'] = [
                'planned_classes' => $classNames,
                'completed_classes' => array_values(array_unique($completedClassNames)),
                'current_class' => null,
                'next_class' => null,
                'remaining_count' => 0,
                'remaining_classes' => [],
            ];
            $summary['progress'] = [
                'phase' => 'completed',
                'class_index' => $totalClassCount,
                'classes_total' => $totalClassCount,
                'classes_completed' => count($completedClassNames),
                'current_class' => null,
                'current_check' => null,
                'last_completed_class' => $totalClassCount > 0 ? ($classNames[$totalClassCount - 1] ?? null) : null,
                'last_update_at' => now()->toIso8601String(),
            ];
            $summary['watchdog'] = [
                'last_heartbeat_at' => now()->toIso8601String(),
                'last_event' => 'scan_completed',
                'stale_after_seconds' => max(60, (int) config('oqlike.watchdog_stale_seconds', 900)),
            ];

            DB::transaction(function () use ($scan, $scores, $summary, $connection, $discoveredClassNames): void {
                $fallbackConfig = is_array($connection->fallback_config_json) ? $connection->fallback_config_json : [];

                $selectedClasses = Arr::get($summary, 'scan_parameters.selected_classes', []);
                if (is_array($selectedClasses) && $selectedClasses === [] && $discoveredClassNames !== []) {
                    $existingDiscovered = [];

                    foreach ((array) Arr::get($fallbackConfig, 'discovered_classes', []) as $className) {
                        if (! is_string($className)) {
                            continue;
                        }

                        $className = trim($className);
                        if ($className === '') {
                            continue;
                        }

                        $existingDiscovered[$className] = true;
                    }

                    foreach ($discoveredClassNames as $className) {
                        if (! is_string($className)) {
                            continue;
                        }

                        $className = trim($className);
                        if ($className === '') {
                            continue;
                        }

                        $existingDiscovered[$className] = true;
                    }

                    $mergedDiscovered = array_keys($existingDiscovered);
                    sort($mergedDiscovered);

                    $currentDiscovered = [];
                    foreach ((array) Arr::get($fallbackConfig, 'discovered_classes', []) as $className) {
                        if (! is_string($className)) {
                            continue;
                        }

                        $className = trim($className);
                        if ($className === '') {
                            continue;
                        }

                        $currentDiscovered[$className] = true;
                    }
                    $currentDiscovered = array_keys($currentDiscovered);
                    sort($currentDiscovered);

                    if ($mergedDiscovered !== $currentDiscovered) {
                        $fallbackConfig['discovered_classes'] = $mergedDiscovered;
                        $fallbackConfig['discovered_at'] = now()->toIso8601String();
                        $fallbackConfig['discovered_source'] = (string) Arr::get($summary, 'metamodel_source_detail', Arr::get($summary, 'metamodel_source', 'scan'));
                    }
                }

                $scan->update([
                    'finished_at' => now(),
                    'summary_json' => $summary,
                    'scores_json' => $scores,
                ]);

                $connection->update([
                    'last_scan_time' => CarbonImmutable::now(),
                    'fallback_config_json' => $fallbackConfig,
                ]);
            });

            Log::info('Scan terminé', [
                'scan_id' => $scan->id,
                'connection_id' => $connection->id,
                'status' => (string) Arr::get($summary, 'status', 'ok'),
                'classes_count' => (int) Arr::get($summary, 'classes_count', 0),
                'issues_count' => (int) Arr::get($summary, 'issue_count', 0),
                'duration_ms' => (int) Arr::get($summary, 'duration_ms', 0),
                    'metamodel_source' => Arr::get($summary, 'metamodel_source'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Scan en échec', [
                'scan_id' => $scan->id,
                'connection_id' => $connection->id,
                'error' => $exception->getMessage(),
            ]);

            $failedSummary = $runningSummary;
            $failedSummary['status'] = 'failed';
            $failedSummary['error'] = $exception->getMessage();
            $failedSummary['duration_ms'] = (int) round((microtime(true) - $runStartedAt) * 1000);
            $failedSummary['issue_count'] = $issueCount;
            $failedSummary['total_affected'] = $totalAffected;
            Arr::set($failedSummary, 'progress.phase', 'failed');
            Arr::set($failedSummary, 'progress.current_check', null);
            Arr::set($failedSummary, 'progress.last_update_at', now()->toIso8601String());
            Arr::set($failedSummary, 'resume.planned_classes', $classNames);
            Arr::set($failedSummary, 'resume.completed_classes', array_values(array_unique($completedClassNames)));
            Arr::set($failedSummary, 'resume.remaining_classes', array_values(array_diff($classNames, array_unique($completedClassNames))));
            Arr::set($failedSummary, 'resume.remaining_count', count((array) Arr::get($failedSummary, 'resume.remaining_classes', [])));
            Arr::set($failedSummary, 'watchdog.last_heartbeat_at', now()->toIso8601String());
            Arr::set($failedSummary, 'watchdog.last_event', 'scan_failed_exception');
            Arr::set($failedSummary, 'watchdog.stale_after_seconds', max(60, (int) config('oqlike.watchdog_stale_seconds', 900)));

            $scan->update([
                'finished_at' => now(),
                'summary_json' => $failedSummary,
                'scores_json' => [
                    'global' => 0,
                    'domains' => [],
                ],
            ]);
        } finally {
            $this->cleanupTemporaryMetamodelFile($temporaryMetamodelFile);
        }

        return $scan->fresh() ?? $scan;
    }

    private function checks(): array
    {
        return [
            new CompletenessMandatoryEmptyCheck(),
            new RelationsMissingExternalKeyCheck(),
            new StalenessLastUpdateCheck(),
            new StaleWithoutOwnerCheck(),
            new OwnershipMissingCheck(),
            new ClassificationMissingCheck(),
            new OrgLocationConsistencyCheck(),
            new StatusEmptyCheck(),
            new NamePlaceholderCheck(),
            new DuplicatesNameCheck(),
            new RelationsOrphanExternalKeyCheck(),
            new StatusObsoleteReferencedCheck(),
        ];
    }

    private function buildSummary(
        ScanContext $context,
        array $metamodel,
        array $scanWarnings = [],
        array $classSummaries = [],
        array $checkSummaries = [],
        int $durationMs = 0,
        int $issueCount = 0,
        int $totalAffected = 0,
        array $issuesByDomain = [],
        array $issuesBySeverity = [],
        array $issuesByCode = [],
        array $topIssueRecords = [],
        array $issueWarnings = [],
        int $activeAcknowledgeRules = 0,
        int $ackSkippedCount = 0,
        int $activeAcknowledgeObjectRules = 0,
        int $ackSkippedObjectsCount = 0,
        bool $forceSelectedClasses = false,
    ): array {
        foreach ($checkSummaries as $checkName => $checkSummary) {
            $checkSummaries[$checkName]['issue_codes'] = array_values(array_keys((array) Arr::get($checkSummary, 'issue_codes', [])));
        }

        usort($topIssueRecords, fn (array $a, array $b) => $b['affected_count'] <=> $a['affected_count']);

        return [
            'status' => 'ok',
            'mode' => $context->mode,
            'duration_ms' => $durationMs,
            'issue_count' => $issueCount,
            'total_affected' => $totalAffected,
            'classes_scanned' => array_keys($context->classes()),
            'classes_count' => count($classSummaries),
            'class_summaries' => $classSummaries,
            'check_summaries' => array_values($checkSummaries),
            'issues_by_domain' => $issuesByDomain,
            'issues_by_severity' => $issuesBySeverity,
            'issues_by_code' => $issuesByCode,
            'top_issues' => array_slice($topIssueRecords, 0, 15),
            'metamodel_hash' => Arr::get($metamodel, 'metamodel_hash'),
            'metamodel_source' => Arr::get($metamodel, 'source'),
            'metamodel_source_detail' => Arr::get($metamodel, 'source_detail'),
            'discovery_error' => Arr::get($metamodel, 'discovery_error'),
            'scan_parameters' => [
                'threshold_days' => $context->thresholdDays,
                'selected_classes' => $context->selectedClasses,
                'force_selected_classes' => $forceSelectedClasses,
                'last_scan_at' => $context->lastScanAt?->toIso8601String(),
            ],
            'acknowledgements' => [
                'active_rules' => $activeAcknowledgeRules,
                'skipped_checks' => $ackSkippedCount,
                'active_object_rules' => $activeAcknowledgeObjectRules,
                'skipped_objects' => $ackSkippedObjectsCount,
                'force_selected_classes' => $forceSelectedClasses,
            ],
            'warnings' => array_values(array_unique(array_merge($issueWarnings, $scanWarnings))),
        ];
    }

    private function persistIssue(Scan $scan, array $payload): void
    {
        /** @var Issue $issueModel */
        $issueModel = $scan->issues()->create([
            'code' => (string) ($payload['code'] ?? ''),
            'title' => (string) ($payload['title'] ?? ''),
            'domain' => (string) ($payload['domain'] ?? ''),
            'severity' => (string) ($payload['severity'] ?? 'info'),
            'impact' => (int) ($payload['impact'] ?? 1),
            'affected_count' => (int) ($payload['affected_count'] ?? 0),
            'recommendation' => (string) ($payload['recommendation'] ?? ''),
            'suggested_oql' => (string) ($payload['suggested_oql'] ?? ''),
            'meta_json' => Arr::get($payload, 'meta', []),
        ]);

        foreach ((array) Arr::get($payload, 'samples', []) as $sample) {
            if (! is_array($sample)) {
                continue;
            }

            $issueModel->samples()->create([
                'itop_class' => (string) Arr::get($sample, 'class', ''),
                'itop_id' => (string) Arr::get($sample, 'id', ''),
                'name' => (string) Arr::get($sample, 'name', ''),
                'link' => Arr::get($sample, 'link'),
            ]);
        }
    }

    private function accumulateIssueStats(
        array $issue,
        array &$issuesByDomain,
        array &$issuesBySeverity,
        array &$issuesByCode,
        array &$topIssueRecords,
        array &$scoreByDomain,
        array &$issueWarnings,
        int &$issueCount,
        int &$totalAffected
    ): void {
        $domain = (string) Arr::get($issue, 'domain', 'inconnu');
        $severity = (string) Arr::get($issue, 'severity', 'inconnu');
        $code = (string) Arr::get($issue, 'code', 'inconnu');
        $affected = max(0, (int) Arr::get($issue, 'affected_count', 0));

        $issueCount++;
        $totalAffected += $affected;
        $issuesByDomain[$domain] = ($issuesByDomain[$domain] ?? 0) + 1;
        $issuesBySeverity[$severity] = ($issuesBySeverity[$severity] ?? 0) + 1;
        $issuesByCode[$code] = ($issuesByCode[$code] ?? 0) + 1;

        $impact = max(1, min(5, (int) Arr::get($issue, 'impact', 1)));
        $scoreByDomain[$domain]['penalty'] = ($scoreByDomain[$domain]['penalty'] ?? 0.0)
            + ($impact * log(1 + $affected));
        $scoreByDomain[$domain]['issue_count'] = ($scoreByDomain[$domain]['issue_count'] ?? 0) + 1;

        $warning = Arr::get($issue, 'meta.warning');
        if (is_string($warning) && $warning !== '') {
            $issueWarnings[] = $warning;
        }

        $topIssueRecords[] = [
            'code' => $code,
            'title' => (string) Arr::get($issue, 'title', $code),
            'domain' => $domain,
            'severity' => $severity,
            'affected_count' => $affected,
            'class' => Arr::get($issue, 'meta.class'),
        ];

        if (count($topIssueRecords) > 80) {
            usort($topIssueRecords, fn (array $a, array $b) => $b['affected_count'] <=> $a['affected_count']);
            $topIssueRecords = array_slice($topIssueRecords, 0, 20);
        }
    }

    /**
     * @param array<string, mixed> $issue
     * @param array<string, array<int, string>> $acknowledgedIssueObjects
     * @param array<string, bool> $matchCache
     * @param array<int, string> $issueWarnings
     * @return array<string, mixed>|null
     */
    private function applyAcknowledgedObjectFilter(
        array $issue,
        ScanContext $context,
        array $acknowledgedIssueObjects,
        int &$ackSkippedObjectsCount,
        array &$issueWarnings,
        array &$matchCache
    ): ?array {
        if (! (bool) config('oqlike.object_ack_enabled', true)) {
            return $issue;
        }

        $issueCode = trim((string) Arr::get($issue, 'code', ''));
        $issueClass = $this->resolveIssueClassFromPayload($issue);

        if ($issueCode === '' || $issueClass === null) {
            return $issue;
        }

        $ackKey = $issueClass.'|'.$issueCode;
        $acknowledgedIds = array_values(array_unique((array) ($acknowledgedIssueObjects[$ackKey] ?? [])));

        if ($acknowledgedIds === []) {
            return $issue;
        }

        $verificationCap = max(1, (int) config('oqlike.object_ack_max_verifications_per_issue', 250));

        if (count($acknowledgedIds) > $verificationCap) {
            $issueWarnings[] = sprintf(
                'Acquittement objet plafonne a %d verifications pour %s/%s.',
                $verificationCap,
                $issueClass,
                $issueCode
            );
            $acknowledgedIds = array_slice($acknowledgedIds, 0, $verificationCap);
        }

        $suggestedOql = trim((string) Arr::get($issue, 'suggested_oql', ''));
        $matchedIds = [];

        foreach ($acknowledgedIds as $itopId) {
            $itopId = trim((string) $itopId);

            if ($itopId === '') {
                continue;
            }

            $cacheKey = md5($ackKey.'|'.$itopId.'|'.$suggestedOql);

            if (! array_key_exists($cacheKey, $matchCache)) {
                $matchCache[$cacheKey] = $this->isAcknowledgedObjectMatchingIssue(
                    $context,
                    $issueClass,
                    $itopId,
                    $suggestedOql
                );
            }

            if ($matchCache[$cacheKey] === true) {
                $matchedIds[] = $itopId;
            }
        }

        if ($matchedIds === []) {
            return $issue;
        }

        $matchedIds = array_values(array_unique($matchedIds));
        $matchedLookup = array_fill_keys($matchedIds, true);
        $affectedCount = max(0, (int) Arr::get($issue, 'affected_count', 0));
        $filteredCount = min($affectedCount, count($matchedIds));

        if ($filteredCount <= 0) {
            return $issue;
        }

        $ackSkippedObjectsCount += $filteredCount;
        $issue['affected_count'] = max(0, $affectedCount - $filteredCount);

        $samples = (array) Arr::get($issue, 'samples', []);
        $filteredSamples = [];

        foreach ($samples as $sample) {
            if (! is_array($sample)) {
                continue;
            }

            $sampleClass = (string) Arr::get($sample, 'class', '');
            $sampleId = trim((string) Arr::get($sample, 'id', ''));

            if ($sampleClass === $issueClass && $sampleId !== '' && isset($matchedLookup[$sampleId])) {
                continue;
            }

            $filteredSamples[] = $sample;
        }

        $issue['samples'] = array_slice($filteredSamples, 0, max(1, $context->maxSamples));
        $meta = Arr::get($issue, 'meta', []);
        $meta = is_array($meta) ? $meta : [];
        $meta['object_acknowledgements'] = [
            'class' => $issueClass,
            'configured_ids' => count($acknowledgedIds),
            'matched_ids' => count($matchedIds),
            'filtered_count' => $filteredCount,
        ];
        $issue['meta'] = $meta;

        if ((int) $issue['affected_count'] <= 0) {
            return null;
        }

        return $issue;
    }

    private function isAcknowledgedObjectMatchingIssue(
        ScanContext $context,
        string $issueClass,
        string $itopId,
        string $suggestedOql
    ): bool {
        $idCondition = $this->buildIdCondition($itopId);
        $baseCondition = $this->extractWhereCondition($suggestedOql);

        if ($baseCondition !== null && $baseCondition !== '') {
            $key = sprintf('%s AND (%s)', $idCondition, $baseCondition);
        } else {
            $key = $idCondition;
        }

        try {
            $objects = $context->itopClient->coreGet($issueClass, $key, ['id'], 1, 0);
            return $objects !== [];
        } catch (Throwable $exception) {
            Log::warning('Object acknowledgement verification failed, fallback to id probe.', [
                'class' => $issueClass,
                'id' => $itopId,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $objects = $context->itopClient->coreGet($issueClass, $idCondition, ['id'], 1, 0);
            return $objects !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function extractWhereCondition(string $suggestedOql): ?string
    {
        $oql = trim($suggestedOql);

        if ($oql === '') {
            return null;
        }

        if (preg_match('/^SELECT\s+[^\s]+(?:\s+WHERE\s+(.+))?$/is', $oql, $matches) !== 1) {
            return null;
        }

        $where = trim((string) ($matches[1] ?? ''));
        if ($where === '') {
            return null;
        }

        return $where;
    }

    private function buildIdCondition(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            return 'id = 0';
        }

        if (ctype_digit($id)) {
            return 'id = '.$id;
        }

        return sprintf("id = '%s'", addslashes($id));
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function resolveIssueClassFromPayload(array $issue): ?string
    {
        $className = trim((string) Arr::get($issue, 'meta.class', ''));

        if ($className !== '') {
            return $className;
        }

        $sampleClass = trim((string) Arr::get($issue, 'samples.0.class', ''));

        return $sampleClass !== '' ? $sampleClass : null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function loadAcknowledgedIssueObjects(Connection $connection): array
    {
        if (! Schema::hasTable('issue_object_acknowledgements')) {
            return [];
        }

        try {
            $rows = IssueObjectAcknowledgement::query()
                ->where('connection_id', $connection->id)
                ->get(['itop_class', 'issue_code', 'itop_id']);
        } catch (Throwable $exception) {
            Log::warning('Failed to load object acknowledgements, continue without object filtering.', [
                'connection_id' => $connection->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $index = [];

        foreach ($rows as $row) {
            $className = trim((string) $row->itop_class);
            $issueCode = trim((string) $row->issue_code);
            $itopId = trim((string) $row->itop_id);

            if ($className === '' || $issueCode === '' || $itopId === '') {
                continue;
            }

            $key = $className.'|'.$issueCode;
            $index[$key][$itopId] = true;
        }

        $normalized = [];

        foreach ($index as $key => $ids) {
            $normalized[$key] = array_keys($ids);
        }

        return $normalized;
    }

    /**
     * @param array<string, array<int, string>> $acknowledgedIssueObjects
     */
    private function countAcknowledgedIssueObjectRules(array $acknowledgedIssueObjects): int
    {
        $count = 0;

        foreach ($acknowledgedIssueObjects as $ids) {
            $count += count($ids);
        }

        return $count;
    }

    private function loadAcknowledgedIssueCodes(Connection $connection): array
    {
        if (! Schema::hasTable('issue_acknowledgements')) {
            return [];
        }

        try {
            $rows = IssueAcknowledgement::query()
                ->where('connection_id', $connection->id)
                ->get(['itop_class', 'issue_code']);
        } catch (Throwable $exception) {
            Log::warning('Impossible de charger les acquittements d’anomalies, poursuite sans ces données.', [
                'connection_id' => $connection->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $index = [];

        foreach ($rows as $row) {
            $className = trim((string) $row->itop_class);
            $issueCode = trim((string) $row->issue_code);

            if ($className === '' || $issueCode === '') {
                continue;
            }

            $index[$className.'|'.$issueCode] = true;
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function persistRunningSummary(Scan $scan, array $summary): void
    {
        $scan->forceFill([
            'summary_json' => $summary,
        ])->saveQuietly();
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $updates
     */
    private function heartbeatRunningSummary(
        Scan $scan,
        array &$summary,
        array $updates,
        string $event,
        int &$lastWriteAtMs,
        int $intervalMs,
        bool $force = false
    ): void {
        foreach ($updates as $path => $value) {
            Arr::set($summary, $path, $value);
        }

        $nowIso = now()->toIso8601String();
        Arr::set($summary, 'status', 'running');
        Arr::set($summary, 'duration_ms', max(0, (int) $scan->started_at?->diffInMilliseconds(now())));
        Arr::set($summary, 'progress.last_update_at', $nowIso);
        Arr::set($summary, 'watchdog.last_heartbeat_at', $nowIso);
        Arr::set($summary, 'watchdog.last_event', $event);
        Arr::set($summary, 'watchdog.stale_after_seconds', max(60, (int) config('oqlike.watchdog_stale_seconds', 900)));

        $nowMs = (int) round(microtime(true) * 1000);

        if (! $force && $intervalMs > 0 && ($nowMs - $lastWriteAtMs) < $intervalMs) {
            return;
        }

        $this->persistRunningSummary($scan, $summary);
        $lastWriteAtMs = $nowMs;
    }

    private function cleanupTemporaryMetamodelFile(mixed $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        if (! is_file($path)) {
            return;
        }

        @unlink($path);
    }
}
