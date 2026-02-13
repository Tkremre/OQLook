<?php

namespace App\Console\Commands;

use App\Models\Scan;
use App\OQLike\Scanning\ScanWatchdogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OQLikeWatchdogCommand extends Command
{
    protected $signature = 'oqlike:watchdog {--stale=} {--limit=100} {--dry-run}';

    protected $description = 'Marque les scans bloqués comme échoués pour permettre la reprise.';

    public function handle(ScanWatchdogService $watchdogService): int
    {
        if (! (bool) config('oqlike.watchdog_enabled', true)) {
            $this->line('Watchdog désactivé par configuration (OQLIKE_WATCHDOG_ENABLED=false).');

            return self::SUCCESS;
        }

        $threshold = $watchdogService->staleThresholdSeconds(
            $this->option('stale') !== null ? (int) $this->option('stale') : null
        );
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');

        $runningScans = Scan::query()
            ->whereNull('finished_at')
            ->orderBy('started_at')
            ->limit($limit)
            ->get();

        $scannedCount = 0;
        $staleCount = 0;
        $markedCount = 0;

        foreach ($runningScans as $scan) {
            $scannedCount++;

            if (! $watchdogService->isStale($scan, $threshold)) {
                continue;
            }

            $staleCount++;
            $ageSeconds = $watchdogService->heartbeatAgeSeconds($scan) ?? -1;

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] scan #%d stale (%ds >= %ds)',
                    $scan->id,
                    $ageSeconds,
                    $threshold
                ));
                continue;
            }

            if ($watchdogService->markStaleAsFailed($scan, $threshold)) {
                $markedCount++;
                Log::warning('Watchdog marked scan as failed.', [
                    'scan_id' => $scan->id,
                    'connection_id' => $scan->connection_id,
                    'heartbeat_age_s' => $ageSeconds,
                    'threshold_s' => $threshold,
                ]);
            }
        }

        $this->info(sprintf(
            'Watchdog: %d scan(s) inspecté(s), %d stale, %d marqué(s) failed.',
            $scannedCount,
            $staleCount,
            $markedCount
        ));

        return self::SUCCESS;
    }
}

