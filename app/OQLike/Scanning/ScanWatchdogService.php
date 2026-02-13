<?php

namespace App\OQLike\Scanning;

use App\Models\Scan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class ScanWatchdogService
{
    public function staleThresholdSeconds(?int $override = null): int
    {
        $threshold = $override ?? (int) config('oqlike.watchdog_stale_seconds', 900);

        return max(60, $threshold);
    }

    public function heartbeatAt(Scan $scan): ?CarbonImmutable
    {
        $summary = $this->summary($scan);
        $candidates = [
            Arr::get($summary, 'watchdog.last_heartbeat_at'),
            Arr::get($summary, 'watchdog.marked_failed_at'),
            Arr::get($summary, 'progress.last_update_at'),
            optional($scan->updated_at)->toIso8601String(),
            optional($scan->started_at)->toIso8601String(),
        ];

        foreach ($candidates as $candidate) {
            $parsed = $this->parseIsoDate($candidate);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    public function heartbeatAgeSeconds(Scan $scan): ?int
    {
        $heartbeatAt = $this->heartbeatAt($scan);

        if ($heartbeatAt === null) {
            return null;
        }

        return max(0, $heartbeatAt->diffInSeconds(CarbonImmutable::now()));
    }

    public function isStale(Scan $scan, ?int $overrideThreshold = null): bool
    {
        if ($scan->finished_at !== null) {
            return false;
        }

        $ageSeconds = $this->heartbeatAgeSeconds($scan);

        if ($ageSeconds === null) {
            return false;
        }

        return $ageSeconds >= $this->staleThresholdSeconds($overrideThreshold);
    }

    public function markStaleAsFailed(Scan $scan, ?int $overrideThreshold = null): bool
    {
        if (! $this->isStale($scan, $overrideThreshold)) {
            return false;
        }

        $summary = $this->summary($scan);
        $ageSeconds = $this->heartbeatAgeSeconds($scan) ?? 0;
        $threshold = $this->staleThresholdSeconds($overrideThreshold);
        $nowIso = now()->toIso8601String();

        $summary['status'] = 'failed';
        $summary['error'] = sprintf(
            'Watchdog: scan bloqué (aucun heartbeat depuis %d s, seuil %d s).',
            $ageSeconds,
            $threshold
        );
        $summary['duration_ms'] = max(
            (int) Arr::get($summary, 'duration_ms', 0),
            max(0, (int) $scan->started_at?->diffInMilliseconds(now()))
        );

        Arr::set($summary, 'watchdog.last_heartbeat_at', $nowIso);
        Arr::set($summary, 'watchdog.marked_failed_at', $nowIso);
        Arr::set($summary, 'watchdog.failure_reason', 'stale_heartbeat');
        Arr::set($summary, 'watchdog.heartbeat_age_s', $ageSeconds);
        Arr::set($summary, 'watchdog.stale_threshold_s', $threshold);
        Arr::set($summary, 'watchdog.marked_by', 'oqlike:watchdog');

        $resume = $this->resolveResumeState($scan, $summary);
        Arr::set($summary, 'resume', $resume);

        $scan->update([
            'finished_at' => now(),
            'summary_json' => $summary,
            'scores_json' => is_array($scan->scores_json) ? $scan->scores_json : ['global' => 0, 'domains' => []],
        ]);

        return true;
    }

    /**
     * @param array<string, mixed>|null $summaryOverride
     * @return array{
     *   planned_classes: array<int, string>,
     *   completed_classes: array<int, string>,
     *   current_class: ?string,
     *   next_class: ?string,
     *   remaining_classes: array<int, string>,
     *   remaining_count: int
     * }
     */
    public function resolveResumeState(Scan $scan, ?array $summaryOverride = null): array
    {
        $summary = is_array($summaryOverride) ? $summaryOverride : $this->summary($scan);

        $plannedClasses = $this->normalizeClassList(
            Arr::get($summary, 'resume.planned_classes', Arr::get($summary, 'classes_scanned', []))
        );

        $completedClasses = $this->normalizeClassList(Arr::get($summary, 'resume.completed_classes', []));

        if ($completedClasses === []) {
            $classSummaries = Arr::get($summary, 'class_summaries', []);

            if (is_array($classSummaries)) {
                foreach ($classSummaries as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $className = trim((string) Arr::get($item, 'class', ''));

                    if ($className === '') {
                        continue;
                    }

                    $completedClasses[] = $className;
                }
            }
        }

        $completedClasses = $this->normalizeClassList($completedClasses);
        $completedSet = array_fill_keys($completedClasses, true);
        $currentClass = trim((string) Arr::get($summary, 'resume.current_class', ''));
        $currentClass = $currentClass !== '' ? $currentClass : null;

        $startIndex = 0;

        if ($currentClass !== null) {
            $index = array_search($currentClass, $plannedClasses, true);

            if (is_int($index) && ! isset($completedSet[$currentClass])) {
                $startIndex = $index;
            }
        }

        $remaining = [];

        for ($i = $startIndex; $i < count($plannedClasses); $i++) {
            $className = $plannedClasses[$i];

            if (! isset($completedSet[$className])) {
                $remaining[] = $className;
            }
        }

        $nextClass = $remaining[0] ?? null;

        return [
            'planned_classes' => $plannedClasses,
            'completed_classes' => $completedClasses,
            'current_class' => $currentClass,
            'next_class' => $nextClass,
            'remaining_classes' => $remaining,
            'remaining_count' => count($remaining),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Scan $scan): array
    {
        return is_array($scan->summary_json) ? $scan->summary_json : [];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeClassList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $unique = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $className = trim($item);

            if ($className === '') {
                continue;
            }

            $unique[$className] = true;
        }

        return array_keys($unique);
    }

    private function parseIsoDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

