<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 12mm 10mm; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10px; }
        h1, h2, h3 { margin: 0 0 8px; }
        .muted { color: #475569; }
        .card { border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; margin: 10px 0; }
        .kpi-grid { width: 100%; border-collapse: collapse; }
        .kpi-grid td { border: 1px solid #e2e8f0; padding: 6px; vertical-align: top; }
        .kpi-title { color: #64748b; font-size: 10px; text-transform: uppercase; }
        .kpi-value { font-size: 15px; font-weight: 700; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; table-layout: fixed; }
        thead { display: table-header-group; }
        th, td {
            border: 1px solid #e2e8f0;
            padding: 6px;
            text-align: left;
            vertical-align: top;
            line-height: 1.25;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        th { background: #f1f5f9; }
        .small { font-size: 9px; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .pre-wrap { white-space: pre-wrap; word-break: break-word; }
        .table-top th:nth-child(1), .table-top td:nth-child(1) { width: 11%; }
        .table-top th:nth-child(2), .table-top td:nth-child(2) { width: 20%; }
        .table-top th:nth-child(3), .table-top td:nth-child(3) { width: 11%; }
        .table-top th:nth-child(4), .table-top td:nth-child(4) { width: 9%; }
        .table-top th:nth-child(5), .table-top td:nth-child(5) { width: 10%; }
        .table-top th:nth-child(6), .table-top td:nth-child(6) { width: 6%; }
        .table-top th:nth-child(7), .table-top td:nth-child(7) { width: 7%; }
        .table-top th:nth-child(8), .table-top td:nth-child(8) { width: 26%; }
        .table-detailed th:nth-child(1), .table-detailed td:nth-child(1) { width: 11%; }
        .table-detailed th:nth-child(2), .table-detailed td:nth-child(2) { width: 10%; }
        .table-detailed th:nth-child(3), .table-detailed td:nth-child(3) { width: 8%; }
        .table-detailed th:nth-child(4), .table-detailed td:nth-child(4) { width: 7%; }
        .table-detailed th:nth-child(5), .table-detailed td:nth-child(5) { width: 22%; }
        .table-detailed th:nth-child(6), .table-detailed td:nth-child(6) { width: 19%; }
        .table-detailed th:nth-child(7), .table-detailed td:nth-child(7) { width: 23%; }
        .table-detailed th, .table-detailed td { padding: 4px; }
        .table-detailed td { font-size: 8.5px; line-height: 1.2; }
        .table-detailed td .badge { font-size: 8px; }
        .badge {
            display: inline-block;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            padding: 1px 6px;
            font-size: 9px;
            font-weight: 700;
        }
        .badge-crit { background: #ffe4e6; color: #be123c; border-color: #fecdd3; }
        .badge-warn { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .badge-info { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    @php
        $labelMode = static function (?string $mode): string {
            return match ($mode) {
                'full' => 'Complet',
                'delta' => 'Delta',
                default => $mode ?: 'N/D',
            };
        };

        $labelStatus = static function (?string $status): string {
            return match ($status) {
                'ok' => 'Terminé',
                'running' => 'En cours',
                'failed' => 'Échec',
                default => $status ?: 'N/D',
            };
        };

        $labelSeverity = static function (?string $severity): string {
            return match ($severity) {
                'crit' => 'Critique',
                'warn' => 'Avertissement',
                'info' => 'Info',
                default => $severity ?: 'N/D',
            };
        };
    @endphp

    <h1>OQLook - Rapport de santé CMDB</h1>
    <p class="muted">
        Scan #{{ $scan->id }} |
        Connexion: {{ $scan->connection->name ?? 'N/D' }} |
        Mode: {{ $labelMode(data_get($summary, 'mode', $scan->mode)) }} |
        Généré le: {{ now()->format('Y-m-d H:i:s') }}
    </p>

    <div class="card">
        <h2>Contexte d'exécution</h2>
        <table class="kpi-grid">
            <tr>
                <td>
                    <div class="kpi-title">Score global</div>
                    <div class="kpi-value">{{ data_get($scores, 'global', 0) }}/100</div>
                </td>
                <td>
                    <div class="kpi-title">Statut</div>
                    <div class="kpi-value">{{ $labelStatus(data_get($summary, 'status', 'ok')) }}</div>
                </td>
                <td>
                    <div class="kpi-title">Durée</div>
                    <div class="kpi-value">{{ round((int) data_get($summary, 'duration_ms', 0) / 1000, 2) }} s</div>
                </td>
                <td>
                    <div class="kpi-title">Anomalies</div>
                    <div class="kpi-value">{{ (int) data_get($summary, 'issue_count', 0) }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="kpi-title">Classes scannées</div>
                    <div class="kpi-value">{{ (int) data_get($summary, 'classes_count', 0) }}</div>
                </td>
                <td>
                    <div class="kpi-title">Total affecté</div>
                    <div class="kpi-value">{{ (int) data_get($summary, 'total_affected', 0) }}</div>
                </td>
                <td>
                    <div class="kpi-title">Source du métamodèle</div>
                    <div class="kpi-value small">{{ data_get($summary, 'metamodel_source', 'N/D') }}</div>
                </td>
                <td>
                    <div class="kpi-title">Démarré</div>
                    <div class="kpi-value small">{{ optional($scan->started_at)->format('Y-m-d H:i:s') }}</div>
                </td>
            </tr>
        </table>
    </div>

    @if(data_get($summary, 'discovery_error'))
        <div class="card">
            <h2>Avertissement de découverte</h2>
            <p class="pre-wrap">{{ data_get($summary, 'discovery_error') }}</p>
        </div>
    @endif

    @if(count((array) data_get($summary, 'warnings', [])) > 0)
        <div class="card">
            <h2>Avertissements</h2>
            <ul>
                @foreach(array_slice((array) data_get($summary, 'warnings', []), 0, 30) as $warning)
                    <li class="small pre-wrap">{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <h2>Répartition par sévérité</h2>
        <table>
            <thead>
                <tr>
                    <th>critique</th>
                    <th>avertissement</th>
                    <th>info</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ (int) data_get($issuesBySeverity, 'crit', 0) }}</td>
                    <td>{{ (int) data_get($issuesBySeverity, 'warn', 0) }}</td>
                    <td>{{ (int) data_get($issuesBySeverity, 'info', 0) }}</td>
                    <td>{{ array_sum((array) $issuesBySeverity) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Scores par domaine</h2>
        <table>
            <thead>
                <tr>
                    <th>Domaine</th>
                    <th>Score</th>
                    <th>Anomalies</th>
                    <th>Pénalité</th>
                </tr>
            </thead>
            <tbody>
                @foreach(data_get($scores, 'domains', []) as $domain => $payload)
                    <tr>
                        <td>{{ $domain }}</td>
                        <td>{{ round((float) data_get($payload, 'score', 0), 2) }}</td>
                        <td>{{ data_get($payload, 'issue_count', 0) }}</td>
                        <td>{{ round((float) data_get($payload, 'penalty', 0), 4) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Détail par domaine (à partir des anomalies)</h2>
        <table>
            <thead>
                <tr>
                    <th>Domaine</th>
                    <th>Anomalies</th>
                    <th>Affecté</th>
                    <th>critique</th>
                    <th>avertissement</th>
                    <th>info</th>
                </tr>
            </thead>
            <tbody>
                @foreach($issuesByDomain as $domain => $payload)
                    <tr>
                        <td>{{ $domain }}</td>
                        <td>{{ (int) data_get($payload, 'issues', 0) }}</td>
                        <td>{{ (int) data_get($payload, 'affected', 0) }}</td>
                        <td>{{ (int) data_get($payload, 'crit', 0) }}</td>
                        <td>{{ (int) data_get($payload, 'warn', 0) }}</td>
                        <td>{{ (int) data_get($payload, 'info', 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card page-break">
        <h2>Top anomalies (les plus impactées)</h2>
        <table class="table-top">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Titre</th>
                    <th>Classe</th>
                    <th>Domaine</th>
                    <th>Sévérité</th>
                    <th>Impact</th>
                    <th>Affecté</th>
                    <th>Recommandation</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topIssues as $issue)
                    <tr>
                        <td>{{ $issue->code }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $issue->title, 80) }}</td>
                        <td>{{ data_get($issue->meta_json, 'class', 'N/D') }}</td>
                        <td>{{ $issue->domain }}</td>
                        <td>
                            <span class="badge badge-{{ $issue->severity }}">
                                {{ $labelSeverity($issue->severity) }}
                            </span>
                        </td>
                        <td>{{ $issue->impact }}</td>
                        <td>{{ $issue->affected_count }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $issue->recommendation, 120) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card page-break">
        <h2>Détail des anomalies (jusqu'à 45 lignes)</h2>
        <table class="table-detailed">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Classe</th>
                    <th>Sévérité</th>
                    <th>Affecté</th>
                    <th>Titre</th>
                    <th>OQL suggérée</th>
                    <th>Objets échantillons</th>
                </tr>
            </thead>
            <tbody>
                @foreach($detailedIssues as $issue)
                    <tr>
                        <td class="mono">{{ $issue->code }}</td>
                        <td>{{ data_get($issue->meta_json, 'class', 'N/D') }}</td>
                        <td>
                            <span class="badge badge-{{ $issue->severity }}">
                                {{ $labelSeverity($issue->severity) }}
                            </span>
                        </td>
                        <td>{{ $issue->affected_count }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $issue->title, 80) }}</td>
                        <td class="small mono pre-wrap">{{ \Illuminate\Support\Str::limit((string) ($issue->suggested_oql ?? ''), 110) }}</td>
                        <td class="small pre-wrap">
                            @php($previewSample = $issue->samples->first())
                            @if(!$previewSample)
                                N/D
                            @else
                                [{{ $previewSample->itop_class }}#{{ $previewSample->itop_id }}]
                                {{ \Illuminate\Support\Str::limit((string) $previewSample->name, 30) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
