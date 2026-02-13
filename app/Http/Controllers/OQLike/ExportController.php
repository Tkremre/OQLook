<?php

namespace App\Http\Controllers\OQLike;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function json(Scan $scan)
    {
        return response()->json($scan->load(['connection', 'issues.samples']));
    }

    public function csv(Scan $scan): StreamedResponse
    {
        $filename = sprintf('oqlike-scan-%d-issues.csv', $scan->id);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->stream(function () use ($scan): void {
            $stream = fopen('php://output', 'wb');

            fputcsv($stream, ['code', 'domaine', 'sévérité', 'impact', 'nb_affectés', 'titre']);

            foreach ($scan->issues()->orderByDesc('affected_count')->get() as $issue) {
                fputcsv($stream, [
                    $issue->code,
                    $issue->domain,
                    $issue->severity,
                    $issue->impact,
                    $issue->affected_count,
                    $issue->title,
                ]);
            }

            fclose($stream);
        }, 200, $headers);
    }

    public function pdf(Scan $scan)
    {
        $payload = $scan->load(['connection', 'issues.samples']);
        $issues = $payload->issues->sortByDesc('affected_count')->values();
        $summary = is_array($payload->summary_json) ? $payload->summary_json : [];
        $scores = is_array($payload->scores_json) ? $payload->scores_json : ['global' => 0, 'domains' => []];

        $issuesByDomain = $issues
            ->groupBy('domain')
            ->map(function ($domainIssues) {
                return [
                    'issues' => $domainIssues->count(),
                    'affected' => (int) $domainIssues->sum('affected_count'),
                    'crit' => $domainIssues->where('severity', 'crit')->count(),
                    'warn' => $domainIssues->where('severity', 'warn')->count(),
                    'info' => $domainIssues->where('severity', 'info')->count(),
                ];
            })
            ->sortByDesc('affected');

        $issuesBySeverity = [
            'crit' => $issues->where('severity', 'crit')->count(),
            'warn' => $issues->where('severity', 'warn')->count(),
            'info' => $issues->where('severity', 'info')->count(),
        ];

        $pdf = Pdf::loadView('pdf.scan-report', [
            'scan' => $payload,
            'summary' => $summary,
            'scores' => $scores,
            'issuesByDomain' => $issuesByDomain,
            'issuesBySeverity' => $issuesBySeverity,
            'topIssues' => $issues->take(20),
            'detailedIssues' => $issues->take(45),
        ])->setPaper('a4', 'landscape');

        return $pdf->download(sprintf('oqlike-scan-%d.pdf', $scan->id));
    }
}
