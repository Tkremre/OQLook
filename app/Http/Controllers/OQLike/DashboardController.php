<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\IssueAcknowledgement;
use App\Models\MetamodelCache;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $connections = Connection::query()->orderBy('name')->get(['id', 'name', 'itop_url', 'auth_mode', 'last_scan_time']);
        $requestedConnectionId = is_numeric($request->query('connection')) ? (int) $request->query('connection') : null;
        $selectedConnectionId = null;

        if ($requestedConnectionId !== null && $connections->contains(fn (Connection $connection): bool => (int) $connection->id === $requestedConnectionId)) {
            $selectedConnectionId = $requestedConnectionId;
        } elseif ($connections->isNotEmpty()) {
            $selectedConnectionId = (int) $connections->first()->id;
        }

        $latestScan = Scan::query()
            ->with('connection:id,name')
            ->when($selectedConnectionId !== null, fn ($query) => $query->where('connection_id', $selectedConnectionId))
            ->latest('id')
            ->first();
        $latestIssues = collect();

        if ($latestScan !== null) {
            $latestIssues = Issue::query()
                ->where('scan_id', $latestScan->id)
                ->orderByDesc('affected_count')
                ->limit(20)
                ->get([
                    'id',
                    'scan_id',
                    'code',
                    'title',
                    'domain',
                    'severity',
                    'impact',
                    'affected_count',
                ]);
        }

        $classCatalogByConnection = $this->classCatalogByConnection($connections->pluck('id')->all());
        $auditRulesByConnection = $this->auditRulesByConnection($connections->pluck('id')->all());

        return Inertia::render('Dashboard/Index', [
            'connections' => $connections,
            'selectedConnectionId' => $selectedConnectionId,
            'latestScan' => $latestScan,
            'latestIssues' => $latestIssues,
            'classCatalogByConnection' => $classCatalogByConnection,
            'auditRulesByConnection' => $auditRulesByConnection,
            'recentScans' => Scan::query()
                ->with('connection:id,name')
                ->when($selectedConnectionId !== null, fn ($query) => $query->where('connection_id', $selectedConnectionId))
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    private function classCatalogByConnection(array $connectionIds): array
    {
        if ($connectionIds === []) {
            return [];
        }

        $catalog = [];
        $connectionsById = Connection::query()
            ->whereIn('id', $connectionIds)
            ->get(['id', 'fallback_config_json'])
            ->keyBy('id')
            ->all();

        foreach ($connectionIds as $connectionId) {
            $connection = $connectionsById[(int) $connectionId] ?? null;
            $fallbackDiscovered = $this->normalizeClassList(
                Arr::get($connection?->fallback_config_json ?? [], 'discovered_classes', [])
            );

            $caches = MetamodelCache::query()
                ->where('connection_id', $connectionId)
                ->latest('id')
                ->limit(10)
                ->get();

            $cacheClasses = [];

            foreach ($caches as $cache) {
                $candidate = $this->normalizeClassList(
                    array_keys((array) data_get($cache->payload_json, 'classes', []))
                );

                if ($candidate !== []) {
                    $cacheClasses = $candidate;
                    break;
                }
            }

            $classes = match (true) {
                $fallbackDiscovered !== [] && $cacheClasses !== [] => count($fallbackDiscovered) >= count($cacheClasses)
                    ? $fallbackDiscovered
                    : $cacheClasses,
                $fallbackDiscovered !== [] => $fallbackDiscovered,
                default => $cacheClasses,
            };

            $catalog[(string) $connectionId] = $classes;
        }

        return $catalog;
    }

    private function auditRulesByConnection(array $connectionIds): array
    {
        if ($connectionIds === []) {
            return [];
        }

        $connections = Connection::query()
            ->whereIn('id', $connectionIds)
            ->get(['id', 'fallback_config_json'])
            ->keyBy('id');

        $ackIndexByConnection = $this->auditAckIndexByConnection($connectionIds);
        $result = [];

        foreach ($connectionIds as $connectionId) {
            $connection = $connections->get((int) $connectionId);
            $fallbackConfig = is_array($connection?->fallback_config_json) ? $connection->fallback_config_json : [];
            $rawRules = Arr::get($fallbackConfig, 'itop_audit_rules', []);
            $rules = [];

            foreach (is_array($rawRules) ? $rawRules : [] as $rawRule) {
                if (! is_array($rawRule)) {
                    continue;
                }

                $ruleId = trim((string) ($rawRule['rule_id'] ?? ''));
                $targetClass = trim((string) ($rawRule['target_class'] ?? ''));
                $issueCode = trim((string) ($rawRule['issue_code'] ?? $this->auditRuleIssueCode($ruleId)));

                if ($ruleId === '' || $targetClass === '') {
                    continue;
                }

                $rules[] = [
                    'rule_id' => $ruleId,
                    'issue_code' => $issueCode,
                    'name' => trim((string) ($rawRule['name'] ?? ('AuditRule #'.$ruleId))),
                    'status' => trim((string) ($rawRule['status'] ?? '')),
                    'target_class' => $targetClass,
                    'oql' => is_string($rawRule['oql'] ?? null) ? trim((string) $rawRule['oql']) : null,
                    'executable' => (bool) ($rawRule['executable'] ?? false),
                    'acknowledged' => (bool) (($ackIndexByConnection[(int) $connectionId][$issueCode] ?? false)),
                ];
            }

            usort($rules, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            $result[(string) $connectionId] = [
                'rules' => $rules,
                'synced_at' => Arr::get($fallbackConfig, 'itop_audit_rules_synced_at'),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, bool>>
     */
    private function auditAckIndexByConnection(array $connectionIds): array
    {
        $index = [];

        foreach ($connectionIds as $connectionId) {
            $index[(int) $connectionId] = [];
        }

        if (! Schema::hasTable('issue_acknowledgements')) {
            return $index;
        }

        try {
            $rows = IssueAcknowledgement::query()
                ->whereIn('connection_id', $connectionIds)
                ->where('issue_code', 'like', 'ITOP_AUDIT_RULE_%')
                ->get(['connection_id', 'issue_code']);
        } catch (\Throwable) {
            return $index;
        }

        foreach ($rows as $row) {
            $connectionId = (int) $row->connection_id;
            $issueCode = trim((string) $row->issue_code);

            if ($issueCode === '') {
                continue;
            }

            $index[$connectionId][$issueCode] = true;
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
     * @param  mixed  $rawClasses
     * @return array<int, string>
     */
    private function normalizeClassList(mixed $rawClasses): array
    {
        if (! is_array($rawClasses)) {
            return [];
        }

        $normalized = [];

        foreach ($rawClasses as $className) {
            if (! is_string($className)) {
                continue;
            }

            $className = trim($className);

            if ($className === '') {
                continue;
            }

            $normalized[$className] = true;
        }

        $result = array_keys($normalized);
        sort($result);

        return $result;
    }
}
