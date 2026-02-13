<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueAcknowledgement;
use App\Models\IssueObjectAcknowledgement;
use App\Models\Scan;
use App\OQLike\Clients\ItopClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    public function index(?Scan $scan = null): Response
    {
        $scan ??= Scan::query()->with('connection:id,name')->latest('id')->first();
        $scan?->loadMissing('connection:id,name');

        $issues = $scan
            ? Issue::query()
                ->where('scan_id', $scan->id)
                ->withCount('samples')
                ->orderByDesc('affected_count')
                ->get([
                    'id',
                    'scan_id',
                    'code',
                    'title',
                    'domain',
                    'severity',
                    'impact',
                    'affected_count',
                    'recommendation',
                    'suggested_oql',
                    'meta_json',
                ])
            : collect();

        $acknowledgements = collect();
        $acknowledgedMap = [];

        if ($scan !== null && Schema::hasTable('issue_acknowledgements')) {
            try {
                $acknowledgements = IssueAcknowledgement::query()
                    ->where('connection_id', $scan->connection_id)
                    ->orderBy('itop_class')
                    ->orderBy('issue_code')
                    ->get([
                        'id',
                        'connection_id',
                        'itop_class',
                        'issue_code',
                        'domain',
                        'title',
                        'note',
                        'created_at',
                    ]);

                $acknowledgedMap = $acknowledgements
                    ->mapWithKeys(fn (IssueAcknowledgement $ack) => [
                        $ack->itop_class.'|'.$ack->issue_code => true,
                    ])
                    ->all();
            } catch (\Throwable $exception) {
                Log::warning('Impossible de charger les acquittements d\'anomalies dans l\'index des issues.', [
                    'scan_id' => $scan->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return Inertia::render('Issues/Index', [
            'scan' => $scan,
            'issues' => $issues,
            'acknowledgements' => $acknowledgements,
            'acknowledgedMap' => $acknowledgedMap,
            'scans' => Scan::query()
                ->with('connection:id,name')
                ->latest('id')
                ->limit(30)
                ->get(['id', 'connection_id', 'started_at', 'mode', 'scores_json', 'summary_json']),
        ]);
    }

    public function show(Issue $issue): Response
    {
        return Inertia::render('Issues/Show', [
            'issue' => $issue->load(['scan.connection', 'samples']),
        ]);
    }

    public function impactedObjects(Issue $issue): JsonResponse
    {
        $issue->loadMissing(['scan.connection']);
        $connection = $issue->scan?->connection;

        if ($connection === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Connexion introuvable pour cette anomalie.',
                'objects' => [],
                'acknowledged_map' => [],
            ], 422);
        }

        $issueClass = $this->resolveIssueClass($issue);
        $maxRecords = $this->resolveMaxRecords($issue);

        $objects = collect();
        $source = 'live_itop';
        $warning = null;
        $usedOql = null;

        if ($issueClass === null) {
            [$objects, $warning] = $this->fallbackToStoredSamples($issue, 'Classe absente pour cette anomalie: fallback sur les échantillons stockés.');
            $source = 'stored_samples';
        } else {
            $usedOql = trim((string) ($issue->suggested_oql ?? ''));

            if ($usedOql === '') {
                $usedOql = sprintf('SELECT %s', $issueClass);
            }

            try {
                $client = new ItopClient($connection);
                $objects = $this->fetchLiveObjects($client, $issueClass, $usedOql, $maxRecords);
            } catch (\Throwable $exception) {
                Log::warning('Impossible de charger les objets impactés depuis iTop, fallback sur les échantillons.', [
                    'issue_id' => $issue->id,
                    'connection_id' => $connection->id,
                    'issue_code' => $issue->code,
                    'class' => $issueClass,
                    'error' => $exception->getMessage(),
                ]);

                [$objects, $fallbackWarning] = $this->fallbackToStoredSamples(
                    $issue,
                    sprintf('Chargement iTop indisponible: %s', $this->shortError($exception->getMessage()))
                );

                $source = 'stored_samples';
                $warning = $fallbackWarning;
            }
        }

        $acknowledgedMap = [];

        if (Schema::hasTable('issue_object_acknowledgements')) {
            $acknowledgedMap = IssueObjectAcknowledgement::query()
                ->where('connection_id', (int) $connection->id)
                ->where('issue_code', (string) $issue->code)
                ->get(['itop_class', 'itop_id'])
                ->mapWithKeys(fn (IssueObjectAcknowledgement $ack) => [
                    $ack->itop_class.'|'.$ack->itop_id => true,
                ])
                ->all();
        }

        return response()->json([
            'ok' => true,
            'objects' => $objects,
            'acknowledged_map' => $acknowledgedMap,
            'count' => $objects->count(),
            'max_records' => 0,
            'capped' => false,
            'source' => $source,
            'warning' => $warning,
            'issue_code' => (string) $issue->code,
            'class' => $issueClass,
            'used_oql' => $usedOql,
        ]);
    }

    private function fetchLiveObjects(ItopClient $client, string $issueClass, string $usedOql, int $maxRecords)
    {
        $fieldSets = [
            ['id', 'friendlyname', 'finalclass'],
            ['id', 'finalclass'],
            ['id'],
        ];

        $lastException = null;
        $fetched = [];

        foreach ($fieldSets as $index => $fields) {
            try {
                $fetched = $client->fetchObjects($issueClass, $usedOql, $fields, $maxRecords);
                $lastException = null;
                break;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                $isLastFieldSet = $index === array_key_last($fieldSets);

                if ($isLastFieldSet || ! $this->isOutputFieldError($exception->getMessage())) {
                    break;
                }
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        return collect($fetched)
            ->map(function (array $object) use ($client, $issueClass): array {
                $itopClass = (string) (Arr::get($object, 'class') ?: $issueClass);
                $itopId = (string) Arr::get($object, 'id', Arr::get($object, 'fields.id', ''));
                $name = (string) (
                    Arr::get($object, 'friendlyname')
                    ?? Arr::get($object, 'fields.friendlyname')
                    ?? Arr::get($object, 'fields.name')
                    ?? sprintf('%s#%s', $itopClass, $itopId)
                );

                return [
                    'itop_class' => $itopClass,
                    'itop_id' => $itopId,
                    'name' => $name,
                    'link' => $itopId !== '' ? $client->itopObjectUrl($itopClass, $itopId) : null,
                ];
            })
            ->filter(fn (array $object): bool => $object['itop_class'] !== '' && $object['itop_id'] !== '')
            ->unique(fn (array $object): string => $object['itop_class'].'|'.$object['itop_id'])
            ->sort(fn (array $a, array $b): int => (
                strcmp($a['itop_class'], $b['itop_class'])
                ?: strcmp($a['name'], $b['name'])
                ?: strcmp($a['itop_id'], $b['itop_id'])
            ))
            ->values();
    }

    private function fallbackToStoredSamples(Issue $issue, ?string $contextWarning = null): array
    {
        $objects = $issue->samples()
            ->orderBy('id')
            ->get(['itop_class', 'itop_id', 'name', 'link'])
            ->map(fn ($sample) => [
                'itop_class' => (string) $sample->itop_class,
                'itop_id' => (string) $sample->itop_id,
                'name' => (string) ($sample->name ?? ''),
                'link' => $sample->link,
            ])
            ->filter(fn (array $sample): bool => $sample['itop_class'] !== '' && $sample['itop_id'] !== '')
            ->unique(fn (array $sample): string => $sample['itop_class'].'|'.$sample['itop_id'])
            ->values();

        if ($objects->isEmpty()) {
            $warning = trim(($contextWarning ? $contextWarning.' ' : '').'Aucun échantillon stocké disponible pour cette anomalie.');
            return [collect(), $warning];
        }

        $warning = trim(($contextWarning ? $contextWarning.' ' : '').'Affichage des échantillons stockés.');
        return [$objects, $warning];
    }

    private function resolveMaxRecords(Issue $issue): int
    {
        $configuredCap = (int) config('oqlike.issue_objects_max_fetch', 0);
        $affectedCount = max(0, (int) $issue->affected_count);

        if ($affectedCount > 0) {
            return max($configuredCap, $affectedCount);
        }

        if ($configuredCap > 0) {
            return $configuredCap;
        }

        return PHP_INT_MAX;
    }

    private function shortError(string $message): string
    {
        $normalized = trim($message);

        if ($normalized === '') {
            return 'Erreur iTop non détaillée';
        }

        if (mb_strlen($normalized) <= 220) {
            return $normalized;
        }

        return mb_substr($normalized, 0, 217).'...';
    }

    private function isOutputFieldError(string $message): bool
    {
        $lower = mb_strtolower($message);

        return str_contains($lower, 'output_fields') || str_contains($lower, 'invalid attribute code');
    }

    private function resolveIssueClass(Issue $issue): ?string
    {
        $className = data_get($issue->meta_json, 'class');

        if (is_string($className) && $className !== '') {
            return $className;
        }

        $sampleClass = $issue->relationLoaded('samples')
            ? $issue->samples->first()?->itop_class
            : $issue->samples()->value('itop_class');

        if (is_string($sampleClass) && $sampleClass !== '') {
            return $sampleClass;
        }

        return null;
    }
}
