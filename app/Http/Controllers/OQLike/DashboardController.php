<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Issue;
use App\Models\MetamodelCache;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        return Inertia::render('Dashboard/Index', [
            'connections' => $connections,
            'selectedConnectionId' => $selectedConnectionId,
            'latestScan' => $latestScan,
            'latestIssues' => $latestIssues,
            'classCatalogByConnection' => $classCatalogByConnection,
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
