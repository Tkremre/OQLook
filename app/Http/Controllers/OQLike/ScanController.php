<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use App\Http\Requests\OQLike\TriggerScanRequest;
use App\Jobs\RunScanJob;
use App\Models\Connection;
use App\Models\IssueAcknowledgement;
use App\Models\Scan;
use App\OQLike\Clients\ConnectorClient;
use App\OQLike\Clients\ItopClient;
use App\OQLike\Scanning\ScanRunner;
use App\OQLike\Scanning\ScanWatchdogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ScanController extends Controller
{
    public function store(TriggerScanRequest $request, Connection $connection, ScanRunner $scanRunner)
    {
        $payload = $request->validated();

        $mode = $payload['mode'] ?? 'delta';
        $classes = $payload['classes'] ?? [];
        $thresholdDays = (int) ($payload['thresholdDays'] ?? config('oqlike.default_threshold_days', 365));
        $forceSelectedClasses = (bool) ($payload['forceSelectedClasses'] ?? false);

        if ($this->shouldQueue()) {
            RunScanJob::dispatch($connection->id, $mode, $classes, $thresholdDays, $forceSelectedClasses);

            return redirect()->route('dashboard', ['connection' => $connection->id])->with(
                'status',
                sprintf(
                    'Scan mis en file d’attente sur %s (mode %s, %d filtre(s) de classe, seuil %d jours, forcerClasses=%s).',
                    (string) config('queue.default', 'queue'),
                    $mode,
                    count($classes),
                    $thresholdDays,
                    $forceSelectedClasses ? 'oui' : 'non'
                )
            );
        }

        $this->prepareLongRunningSyncScan();
        $scan = $scanRunner->run($connection, $mode, $classes, $thresholdDays, $forceSelectedClasses);
        $summary = is_array($scan->summary_json) ? $scan->summary_json : [];
        $statusCode = (string) ($summary['status'] ?? 'ok');
        $issueCount = (int) ($summary['issue_count'] ?? 0);
        $classCount = (int) ($summary['classes_count'] ?? count(($summary['classes_scanned'] ?? [])));
        $durationMs = (int) ($summary['duration_ms'] ?? 0);

        if ($statusCode === 'failed') {
            $status = sprintf(
                'Scan échoué en mode synchrone: %s',
                (string) ($summary['error'] ?? 'erreur inconnue')
            );
        } else {
            $status = sprintf(
                'Scan terminé en mode synchrone: %d anomalie(s), %d classe(s), %d ms.',
                $issueCount,
                $classCount,
                $durationMs
            );
        }

        return redirect()->route('issues.index', ['scan' => $scan->id])->with('status', $status);
    }

    public function resume(
        Scan $scan,
        ScanRunner $scanRunner,
        ScanWatchdogService $watchdogService
    ) {
        $scan->loadMissing('connection');
        $connection = $scan->connection;

        if (! $connection instanceof Connection) {
            return redirect()->back()->with('status', 'Impossible de reprendre: connexion du scan introuvable.');
        }

        if ($scan->finished_at === null) {
            $wasMarkedFailed = $watchdogService->markStaleAsFailed($scan);

            if (! $wasMarkedFailed) {
                $ageSeconds = $watchdogService->heartbeatAgeSeconds($scan);
                $ageText = is_int($ageSeconds) ? sprintf('%d s', $ageSeconds) : 'N/D';

                return redirect()->back()->with(
                    'status',
                    sprintf(
                        'Le scan #%d semble encore actif (dernier heartbeat: %s). Reprise refusée pour éviter un doublon.',
                        $scan->id,
                        $ageText
                    )
                );
            }

            $scan = $scan->fresh() ?? $scan;
        }

        $resumeState = $watchdogService->resolveResumeState($scan);
        $remainingClasses = array_values((array) Arr::get($resumeState, 'remaining_classes', []));

        if ($remainingClasses === []) {
            return redirect()->back()->with(
                'status',
                sprintf('Aucune classe restante à reprendre pour le scan #%d.', $scan->id)
            );
        }

        $summary = is_array($scan->summary_json) ? $scan->summary_json : [];
        $mode = (string) Arr::get($summary, 'mode', $scan->mode ?? 'delta');
        $mode = in_array($mode, ['delta', 'full'], true) ? $mode : 'delta';
        $thresholdDays = max(
            1,
            min(3650, (int) Arr::get($summary, 'scan_parameters.threshold_days', config('oqlike.default_threshold_days', 365)))
        );
        $forceSelectedClasses = (bool) Arr::get($summary, 'scan_parameters.force_selected_classes', false);

        if ($this->shouldQueue()) {
            RunScanJob::dispatch(
                $connection->id,
                $mode,
                $remainingClasses,
                $thresholdDays,
                $forceSelectedClasses
            );

            return redirect()->back()->with(
                'status',
                sprintf(
                    'Reprise du scan #%d mise en file: %d classe(s) restante(s).',
                    $scan->id,
                    count($remainingClasses)
                )
            );
        }

        $this->prepareLongRunningSyncScan();
        $resumedScan = $scanRunner->run(
            $connection,
            $mode,
            $remainingClasses,
            $thresholdDays,
            $forceSelectedClasses
        );

        return redirect()->route('issues.index', ['scan' => $resumedScan->id])->with(
            'status',
            sprintf(
                'Scan repris depuis #%d: %d classe(s) relancées, nouveau scan #%d.',
                $scan->id,
                count($remainingClasses),
                $resumedScan->id
            )
        );
    }

    public function destroy(Request $request, Scan $scan)
    {
        $connectionId = (int) $scan->connection_id;
        $scanId = (int) $scan->id;
        $scan->delete();

        $latestFinishedAt = Scan::query()
            ->where('connection_id', $connectionId)
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->value('finished_at');

        Connection::query()
            ->whereKey($connectionId)
            ->update([
                'last_scan_time' => $latestFinishedAt,
            ]);

        if ((string) $request->input('from') === 'dashboard') {
            $dashboardConnection = is_numeric($request->input('connection'))
                ? (int) $request->input('connection')
                : $connectionId;

            return redirect()->route('dashboard', ['connection' => $dashboardConnection])
                ->with('status', sprintf('Scan #%d supprimé.', $scanId));
        }

        return redirect()->route('issues.index')
            ->with('status', sprintf('Scan #%d supprimé.', $scanId));
    }

    public function scanLog(Request $request, Connection $connection): JsonResponse
    {
        $limit = max(20, min((int) $request->integer('limit', 80), 400));
        $tail = max(120, min((int) $request->integer('tail', 800), 5000));
        $requestedScanId = $request->filled('scan_id') ? (int) $request->integer('scan_id') : null;

        $targetScan = null;
        $runningScan = null;

        if ($requestedScanId !== null && $requestedScanId > 0) {
            $targetScan = Scan::query()
                ->where('connection_id', $connection->id)
                ->whereKey($requestedScanId)
                ->first();
        }

        if ($targetScan === null) {
            $runningScan = Scan::query()
                ->where('connection_id', $connection->id)
                ->whereNull('finished_at')
                ->latest('id')
                ->first();

            $targetScan = $runningScan
                ?? Scan::query()
                    ->where('connection_id', $connection->id)
                    ->latest('id')
                    ->first();
        }

        $lines = $this->tailFileLines(storage_path('logs/laravel.log'), $tail);
        $filtered = $this->filterScanLogLines($lines, $connection->id, $targetScan?->id);

        if (count($filtered) > $limit) {
            $filtered = array_slice($filtered, -$limit);
        }

        return response()->json([
            'ok' => true,
            'connection_id' => $connection->id,
            'scan_id' => $targetScan?->id,
            'running' => $runningScan !== null,
            'running_scan' => $runningScan ? [
                'id' => (int) $runningScan->id,
                'mode' => (string) $runningScan->mode,
                'started_at' => optional($runningScan->started_at)?->toIso8601String(),
            ] : null,
            'line_count' => count($filtered),
            'lines' => $filtered,
        ]);
    }

    public function discoverClasses(Request $request, Connection $connection)
    {
        try {
            $this->prepareLongRunningSyncScan();

            $targetClasses = $this->normalizeClassFilters($request->input('classes'));
            $connectorClient = new ConnectorClient($connection);
            $itopClient = new ItopClient($connection);

            $discoveredClasses = [];
            $source = 'none';
            $warnings = [];
            $connectorConfigured = $connectorClient->isConfigured();

            try {
                if ($connectorConfigured) {
                    $discoveredClasses = $connectorClient->fetchPersistentClasses([
                        'timeout' => 15,
                        'retries' => 1,
                        'retry_ms' => 0,
                    ]);
                    $source = 'connector_class_list';
                }
            } catch (Throwable $exception) {
                $warnings[] = 'Échec de récupération de la liste des classes via le connecteur: '.$exception->getMessage();
            }

            if ($discoveredClasses === []) {
                $configuredFallback = Arr::get($connection->fallback_config_json ?? [], 'classes', []);

                if (is_array($configuredFallback) && $configuredFallback !== []) {
                    $discoveredClasses = array_values(array_filter($configuredFallback, static fn (mixed $v): bool => is_string($v) && $v !== ''));
                    $source = $connectorConfigured ? 'fallback_config_after_connector' : 'fallback_config';
                }
            }

            if ($discoveredClasses === [] && ! $connectorConfigured) {
                try {
                    $scanLimit = max(100, min((int) config('oqlike.discovery_scan_limit', 400), 2500));
                    $discoveredClasses = $itopClient->discoverClassesFromData($scanLimit);
                    $source = 'itop_fallback_discovery';
                } catch (Throwable $exception) {
                    $warnings[] = 'Échec de la découverte de secours iTop: '.$exception->getMessage();
                }
            }

            $discoveredClasses = array_values(array_unique(array_filter(
                $discoveredClasses,
                static fn (mixed $className): bool => is_string($className) && $className !== ''
            )));

            if ($targetClasses !== []) {
                $discoveredClasses = array_values(array_intersect($discoveredClasses, $targetClasses));
            }

            sort($discoveredClasses);

            $fallbackConfig = $connection->fallback_config_json ?? [];
            $fallbackConfig['discovered_classes'] = $discoveredClasses;
            $fallbackConfig['discovered_at'] = now()->toIso8601String();
            $fallbackConfig['discovered_source'] = $source;
            $fallbackConfig['discovery_warning'] = $warnings !== [] ? implode(' | ', array_values(array_unique($warnings))) : null;
            $connection->fallback_config_json = $fallbackConfig;
            $connection->save();

            $status = sprintf('Découverte terminée: %d classe(s) [source=%s].', count($discoveredClasses), $source);

            if ($warnings !== []) {
                $status .= ' Avertissement: '.implode(' | ', array_values(array_unique($warnings)));
            }

            return redirect()->route('dashboard', ['connection' => $connection->id])->with('status', $status);
        } catch (Throwable $exception) {
            return redirect()->route('dashboard', ['connection' => $connection->id])->with(
                'status',
                'Échec de la découverte: '.$exception->getMessage()
            );
        }
    }

    public function syncAuditRules(Request $request, Connection $connection)
    {
        try {
            $this->prepareLongRunningSyncScan();

            $itopClient = new ItopClient($connection);
            $rules = $itopClient->discoverAuditRules();
            $rules = array_values(array_map(function (array $rule): array {
                $ruleId = trim((string) ($rule['rule_id'] ?? ''));
                $targetClass = trim((string) ($rule['target_class'] ?? ''));

                return [
                    'rule_id' => $ruleId,
                    'issue_code' => $this->auditRuleIssueCode($ruleId),
                    'name' => trim((string) ($rule['name'] ?? ('AuditRule #'.$ruleId))),
                    'status' => trim((string) ($rule['status'] ?? '')),
                    'target_class' => $targetClass !== '' ? $targetClass : null,
                    'oql' => trim((string) ($rule['oql'] ?? '')) ?: null,
                    'executable' => (bool) ($rule['executable'] ?? false),
                ];
            }, $rules));

            $ackByCode = $this->loadAuditRuleAcksByIssueCode($connection->id);

            foreach ($rules as &$rule) {
                $rule['acknowledged'] = (bool) ($ackByCode[$rule['issue_code']] ?? false);
            }
            unset($rule);

            $fallbackConfig = is_array($connection->fallback_config_json) ? $connection->fallback_config_json : [];
            $fallbackConfig['itop_audit_rules'] = $rules;
            $fallbackConfig['itop_audit_rules_synced_at'] = now()->toIso8601String();
            $fallbackConfig['itop_audit_rules_source'] = 'itop_AuditRule';
            $connection->fallback_config_json = $fallbackConfig;
            $connection->save();

            $status = sprintf('Règles Audit iTop synchronisées: %d règle(s).', count($rules));

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'status' => $status,
                    'rules' => $rules,
                ]);
            }

            return redirect()->route('dashboard', ['connection' => $connection->id])->with('status', $status);
        } catch (Throwable $exception) {
            $message = 'Échec de la synchronisation des règles Audit iTop: '.$exception->getMessage();

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'status' => $message,
                ], 422);
            }

            return redirect()->route('dashboard', ['connection' => $connection->id])->with('status', $message);
        }
    }

    public function acknowledgeAuditRule(Request $request, Connection $connection): JsonResponse
    {
        if (! Schema::hasTable('issue_acknowledgements')) {
            return response()->json([
                'ok' => false,
                'status' => 'Acquittement indisponible: migration issue_acknowledgements manquante.',
            ], 422);
        }

        $validated = $request->validate([
            'rule_id' => ['required', 'string', 'max:120'],
            'target_class' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $ruleId = trim((string) $validated['rule_id']);
        $targetClass = trim((string) $validated['target_class']);
        $issueCode = $this->auditRuleIssueCode($ruleId);
        $title = trim((string) ($validated['name'] ?? ('AuditRule #'.$ruleId)));

        IssueAcknowledgement::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'itop_class' => $targetClass,
                'issue_code' => $issueCode,
            ],
            [
                'domain' => 'audit',
                'title' => $title,
                'note' => 'Acquittement règle Audit iTop',
            ]
        );

        return response()->json([
            'ok' => true,
            'status' => sprintf('Règle Audit acquittée: %s (%s).', $title, $issueCode),
            'issue_code' => $issueCode,
        ]);
    }

    public function deacknowledgeAuditRule(Request $request, Connection $connection): JsonResponse
    {
        if (! Schema::hasTable('issue_acknowledgements')) {
            return response()->json([
                'ok' => false,
                'status' => 'Désacquittement indisponible: migration issue_acknowledgements manquante.',
            ], 422);
        }

        $validated = $request->validate([
            'rule_id' => ['required', 'string', 'max:120'],
            'target_class' => ['required', 'string', 'max:255'],
        ]);

        $ruleId = trim((string) $validated['rule_id']);
        $targetClass = trim((string) $validated['target_class']);
        $issueCode = $this->auditRuleIssueCode($ruleId);

        IssueAcknowledgement::query()
            ->where('connection_id', $connection->id)
            ->where('itop_class', $targetClass)
            ->where('issue_code', $issueCode)
            ->delete();

        return response()->json([
            'ok' => true,
            'status' => sprintf('Acquittement retiré: %s.', $issueCode),
            'issue_code' => $issueCode,
        ]);
    }

    private function shouldQueue(): bool
    {
        if (! (bool) config('oqlike.use_queue', false)) {
            return false;
        }

        $queueDriver = (string) config('queue.default', 'sync');

        if ($queueDriver === 'sync') {
            return false;
        }

        if ($queueDriver === 'redis') {
            return extension_loaded('redis') || class_exists(\Predis\Client::class);
        }

        // database / sqs / etc are considered available if configured.
        return true;
    }

    private function prepareLongRunningSyncScan(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);
    }

    private function normalizeClassFilters(mixed $rawClasses): array
    {
        if (is_string($rawClasses)) {
            $rawClasses = explode(',', $rawClasses);
        }

        if (! is_array($rawClasses)) {
            return [];
        }

        $classes = [];

        foreach ($rawClasses as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $classes[$candidate] = true;
        }

        return array_keys($classes);
    }

    /**
     * @return array<string, bool>
     */
    private function loadAuditRuleAcksByIssueCode(int $connectionId): array
    {
        if (! Schema::hasTable('issue_acknowledgements')) {
            return [];
        }

        try {
            $rows = IssueAcknowledgement::query()
                ->where('connection_id', $connectionId)
                ->where('issue_code', 'like', 'ITOP_AUDIT_RULE_%')
                ->get(['issue_code']);
        } catch (Throwable) {
            return [];
        }

        $index = [];

        foreach ($rows as $row) {
            $code = trim((string) $row->issue_code);
            if ($code === '') {
                continue;
            }
            $index[$code] = true;
        }

        return $index;
    }

    private function auditRuleIssueCode(string $ruleId): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '_', strtoupper(trim($ruleId))) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            $normalized = 'UNKNOWN';
        }

        return 'ITOP_AUDIT_RULE_'.$normalized;
    }

    /**
     * @return array<int, string>
     */
    private function tailFileLines(string $filePath, int $lineCount): array
    {
        if (! is_file($filePath) || $lineCount <= 0) {
            return [];
        }

        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            if (fseek($handle, 0, SEEK_END) !== 0) {
                return [];
            }

            $position = ftell($handle);

            if (! is_int($position) || $position <= 0) {
                return [];
            }

            $chunkSize = 8192;
            $buffer = '';
            $lineBreakCount = 0;

            while ($position > 0 && $lineBreakCount <= $lineCount + 20) {
                $readSize = min($chunkSize, $position);
                $position -= $readSize;

                if (fseek($handle, $position, SEEK_SET) !== 0) {
                    break;
                }

                $chunk = fread($handle, $readSize);

                if ($chunk === false) {
                    break;
                }

                $buffer = $chunk.$buffer;
                $lineBreakCount = substr_count($buffer, "\n");
            }
        } finally {
            fclose($handle);
        }

        $allLines = preg_split('/\r\n|\n|\r/', trim($buffer));

        if (! is_array($allLines) || $allLines === []) {
            return [];
        }

        return array_values(array_slice($allLines, -$lineCount));
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function filterScanLogLines(array $lines, int $connectionId, ?int $scanId): array
    {
        if ($lines === []) {
            return [];
        }

        $connectionToken = '"connection_id":'.$connectionId;
        $scanToken = $scanId ? '"scan_id":'.$scanId : null;
        $fallbackKeywords = ['Scan ', 'scan_id', 'Discovery warning', 'Avertissement de découverte', 'Check execution failed', 'Échec d’exécution du contrôle'];

        $filtered = [];

        foreach ($lines as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            if ($scanToken && str_contains($line, $scanToken)) {
                $filtered[] = $line;
                continue;
            }

            if (! str_contains($line, $connectionToken)) {
                continue;
            }

            foreach ($fallbackKeywords as $keyword) {
                if (stripos($line, $keyword) !== false) {
                    $filtered[] = $line;
                    break;
                }
            }
        }

        return $filtered;
    }
}
