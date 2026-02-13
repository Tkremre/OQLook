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
                Log::warning('Impossible de charger les acquittements d’anomalies dans l’index des issues.', [
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
            'issue' => $issue->load(['samples', 'scan.connection']),
        ]);
    }

    public function impactedObjects(Issue $issue): JsonResponse
    {
        $issue->loadMissing(['scan.connection', 'samples']);
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
        $maxRecords = max(
            1,
            (int) config('oqlike.issue_objects_max_fetch', config('oqlike.max_full_records_without_delta', 4000))
        );

        $objects = collect();
        $source = 'live_itop';
        $warning = null;
        $usedOql = null;

        if ($issueClass === null) {
            $objects = $issue->samples
                ->map(fn ($sample) => [
                    'itop_class' => (string) $sample->itop_class,
                    'itop_id' => (string) $sample->itop_id,
                    'name' => (string) ($sample->name ?? ''),
                    'link' => $sample->link,
                ])
                ->values();
            $source = 'stored_samples';
            $warning = 'Classe absente pour cette anomalie: fallback sur les échantillons stockés.';
        } else {
            $usedOql = trim((string) ($issue->suggested_oql ?? ''));

            if ($usedOql === '') {
                $usedOql = sprintf('SELECT %s', $issueClass);
            }

            try {
                $client = new ItopClient($connection);
                $fetched = $client->fetchObjects(
                    $issueClass,
                    $usedOql,
                    ['id', 'friendlyname', 'name', 'finalclass'],
                    $maxRecords
                );

                $objects = collect($fetched)
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
            } catch (\Throwable $exception) {
                return response()->json([
                    'ok' => false,
                    'error' => sprintf('Impossible de charger les objets impactés: %s', $exception->getMessage()),
                    'objects' => [],
                    'acknowledged_map' => [],
                    'source' => 'live_itop_error',
                    'issue_code' => (string) $issue->code,
                    'class' => $issueClass,
                    'used_oql' => $usedOql,
                ], 500);
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
            'max_records' => $maxRecords,
            'capped' => $objects->count() >= $maxRecords,
            'source' => $source,
            'warning' => $warning,
            'issue_code' => (string) $issue->code,
            'class' => $issueClass,
            'used_oql' => $usedOql,
        ]);
    }

    private function resolveIssueClass(Issue $issue): ?string
    {
        $className = data_get($issue->meta_json, 'class');

        if (is_string($className) && $className !== '') {
            return $className;
        }

        $sampleClass = $issue->samples->first()?->itop_class;

        if (is_string($sampleClass) && $sampleClass !== '') {
            return $sampleClass;
        }

        return null;
    }
}
