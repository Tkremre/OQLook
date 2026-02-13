import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import { Card, CardDescription, CardTitle } from '../../components/ui/card';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input, Select } from '../../components/ui/input';
import { Link, router, useForm } from '@inertiajs/react';
import { appPath } from '../../lib/app-path';
import { useI18n } from '../../i18n';
import {
  BarChart3,
  CheckCircle2,
  CircleAlert,
  Clock3,
  Filter,
  FolderTree,
  PlayCircle,
  SearchCode,
  ShieldCheck,
  Sparkles,
} from 'lucide-react';

function severityTone(severity) {
  if (severity === 'crit') return 'crit';
  if (severity === 'warn') return 'warn';
  if (severity === 'info') return 'info';
  return 'slate';
}

function formatDuration(ms) {
  if (!ms || Number.isNaN(Number(ms))) return 'N/D';
  if (ms < 1000) return `${ms} ms`;
  return `${(ms / 1000).toFixed(2)} s`;
}

function scanStageFromElapsed(elapsedSeconds) {
  if (elapsedSeconds < 5) return 'Initialisation du scan';
  if (elapsedSeconds < 20) return 'Interrogation iTop / connecteur';
  if (elapsedSeconds < 60) return 'Exécution des contrôles auto-adaptatifs';
  return 'Finalisation et écriture des résultats';
}

function scanStatusLabel(status, t) {
  if (status === 'failed') return t('common.status.failed');
  if (status === 'running') return t('common.status.running');
  return t('common.status.completed');
}

function modeLabel(mode, t) {
  if (mode === 'full') return t('common.mode.full');
  if (mode === 'delta') return t('common.mode.delta');
  return mode ?? t('common.nd');
}

const LEAVE_SCAN_MESSAGE = "Si vous quittez cette page, le scan va s'arrêter. Voulez-vous quand même ouvrir cette page dans un nouvel onglet ?";

export default function DashboardIndex({
  connections = [],
  selectedConnectionId = null,
  latestScan,
  latestIssues = [],
  recentScans = [],
  classCatalogByConnection = {},
}) {
  const { t } = useI18n();
  const [selectedConnection, setSelectedConnection] = useState(selectedConnectionId ?? connections[0]?.id ?? null);
  const [catalogSearch, setCatalogSearch] = useState('');
  const [exportMenuOpen, setExportMenuOpen] = useState(false);
  const exportMenuRef = useRef(null);
  const [scanProgress, setScanProgress] = useState({
    active: false,
    elapsedSeconds: 0,
  });
  const [showScanLogs, setShowScanLogs] = useState(false);
  const [scanLogState, setScanLogState] = useState({
    loading: false,
    error: null,
    lines: [],
    scanId: null,
    running: false,
    updatedAt: null,
  });

  const form = useForm({
    mode: 'delta',
    classes: '',
    thresholdDays: 365,
    forceSelectedClasses: false,
  });
  const discoverForm = useForm({
    classes: '',
  });

  const scanSummary = latestScan?.summary_json ?? {};
  const scanStatus = scanSummary?.status ?? 'ok';
  const classSummaries = scanSummary?.class_summaries ?? [];
  const checkSummaries = scanSummary?.check_summaries ?? [];

  const selectedClasses = useMemo(() => {
    return String(form.data.classes ?? '')
      .split(',')
      .map((entry) => entry.trim())
      .filter(Boolean);
  }, [form.data.classes]);

  const discoveredClasses = useMemo(() => {
    return classCatalogByConnection[String(selectedConnection)] ?? [];
  }, [classCatalogByConnection, selectedConnection]);

  const filteredDiscoveredClasses = useMemo(() => {
    if (!catalogSearch) return discoveredClasses;
    const q = catalogSearch.toLowerCase();
    return discoveredClasses.filter((className) => className.toLowerCase().includes(q));
  }, [discoveredClasses, catalogSearch]);

  const topIssues = useMemo(() => {
    if (!Array.isArray(latestIssues)) return [];
    return [...latestIssues]
      .sort((a, b) => (b.affected_count ?? 0) - (a.affected_count ?? 0))
      .slice(0, 8);
  }, [latestIssues]);

  const classBreakdown = useMemo(() => {
    return [...classSummaries]
      .sort((a, b) => (b.issues_found ?? 0) - (a.issues_found ?? 0) || (b.duration_ms ?? 0) - (a.duration_ms ?? 0))
      .slice(0, 12);
  }, [classSummaries]);

  const checkBreakdown = useMemo(() => {
    return [...checkSummaries]
      .sort((a, b) => (b.issues_found ?? 0) - (a.issues_found ?? 0) || (b.executed_count ?? 0) - (a.executed_count ?? 0));
  }, [checkSummaries]);

  const toggleClassSelection = (className) => {
    const next = new Set(selectedClasses);

    if (next.has(className)) {
      next.delete(className);
    } else {
      next.add(className);
    }

    form.setData('classes', Array.from(next).sort().join(','));
  };

  const submitScan = (event) => {
    event.preventDefault();
    if (!selectedConnection) return;

    setScanProgress({
      active: true,
      elapsedSeconds: 0,
    });
    setShowScanLogs(true);
    setScanLogState({
      loading: true,
      error: null,
      lines: [],
      scanId: null,
      running: true,
      updatedAt: null,
    });

    form.post(appPath(`connections/${selectedConnection}/scan`), {
      preserveScroll: true,
      onFinish: () => {
        setScanProgress({
          active: false,
          elapsedSeconds: 0,
        });
      },
    });
  };

  const fetchScanLogs = useCallback(async (silent = false) => {
    if (!selectedConnection) return;

    if (!silent) {
      setScanLogState((previous) => ({ ...previous, loading: true, error: null }));
    }

    try {
      const response = await fetch(appPath(`connections/${selectedConnection}/scan-log?limit=120&tail=1800`), {
        method: 'GET',
        headers: {
          Accept: 'application/json',
        },
      });

      if (!response.ok) {
        throw new Error(`Erreur HTTP ${response.status}`);
      }

      const payload = await response.json();

      setScanLogState({
        loading: false,
        error: null,
        lines: Array.isArray(payload?.lines) ? payload.lines : [],
        scanId: payload?.scan_id ?? null,
        running: Boolean(payload?.running),
        updatedAt: new Date().toISOString(),
      });
    } catch (error) {
      setScanLogState((previous) => ({
        ...previous,
        loading: false,
        error: error instanceof Error ? error.message : 'Erreur de logs inconnue',
      }));
    }
  }, [selectedConnection]);

  const deleteScanHistory = (scanId) => {
    if (!window.confirm(`Supprimer le scan #${scanId} ? Cette action est irréversible.`)) {
      return;
    }

    router.delete(appPath(`scans/${scanId}`), {
      preserveScroll: true,
      data: {
        from: 'dashboard',
        connection: selectedConnection,
      },
    });
  };

  const discoverClasses = () => {
    if (!selectedConnection) return;

    discoverForm.setData('classes', form.data.classes);
    discoverForm.post(appPath(`connections/${selectedConnection}/discover-classes`), {
      preserveScroll: true,
    });
  };

  const scoreDomains = latestScan?.scores_json?.domains ?? {};
  const exportBasePath = latestScan ? appPath(`scans/${latestScan.id}/export`) : '#';

  useEffect(() => {
    const fallbackConnectionId = connections[0]?.id ?? null;
    setSelectedConnection(selectedConnectionId ?? fallbackConnectionId);
  }, [selectedConnectionId, connections]);

  useEffect(() => {
    setShowScanLogs(false);
    setScanLogState({
      loading: false,
      error: null,
      lines: [],
      scanId: null,
      running: false,
      updatedAt: null,
    });
  }, [selectedConnection]);

  useEffect(() => {
    if (!scanProgress.active) return undefined;

    const startedAt = Date.now();
    const timer = window.setInterval(() => {
      const elapsed = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
      setScanProgress((previous) => ({ ...previous, elapsedSeconds: elapsed }));
    }, 1000);

    return () => window.clearInterval(timer);
  }, [scanProgress.active]);

  useEffect(() => {
    if (!showScanLogs || !selectedConnection) return undefined;

    void fetchScanLogs(false);

    const intervalMs = scanProgress.active ? 2500 : 7000;
    const timer = window.setInterval(() => {
      void fetchScanLogs(true);
    }, intervalMs);

    return () => window.clearInterval(timer);
  }, [showScanLogs, selectedConnection, scanProgress.active, fetchScanLogs]);

  useEffect(() => {
    if (!exportMenuOpen) return undefined;

    const handleClickOutside = (event) => {
      if (exportMenuRef.current && !exportMenuRef.current.contains(event.target)) {
        setExportMenuOpen(false);
      }
    };

    const handleEscape = (event) => {
      if (event.key === 'Escape') {
        setExportMenuOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleEscape);

    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleEscape);
    };
  }, [exportMenuOpen]);

  useEffect(() => {
    if (!scanProgress.active) return undefined;

    const handleAnchorNavigation = (event) => {
      if (event.defaultPrevented) return;
      if (event.button !== 0) return;
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

      const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;

      if (!anchor) return;
      if (anchor.hasAttribute('download')) return;
      if ((anchor.getAttribute('target') ?? '').toLowerCase() === '_blank') return;

      const href = anchor.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

      const targetUrl = new URL(anchor.href, window.location.href);
      const currentUrl = new URL(window.location.href);
      const isSamePage =
        targetUrl.origin === currentUrl.origin &&
        targetUrl.pathname === currentUrl.pathname &&
        targetUrl.search === currentUrl.search &&
        targetUrl.hash === currentUrl.hash;

      if (isSamePage) return;

      event.preventDefault();
      event.stopPropagation();

      const confirmed = window.confirm(LEAVE_SCAN_MESSAGE);
      if (!confirmed) return;

      window.open(targetUrl.toString(), '_blank', 'noopener,noreferrer');
    };

    const handleBeforeUnload = (event) => {
      event.preventDefault();
      event.returnValue = '';
    };

    document.addEventListener('click', handleAnchorNavigation, true);
    window.addEventListener('beforeunload', handleBeforeUnload);

    return () => {
      document.removeEventListener('click', handleAnchorNavigation, true);
      window.removeEventListener('beforeunload', handleBeforeUnload);
    };
  }, [scanProgress.active]);

  return (
    <AppLayout
      title={t('pages.dashboard.title')}
      subtitle={t('pages.dashboard.subtitle')}
      fullWidth
    >
      <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
        <Card className="oq-appear">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <CardTitle className="inline-flex items-center gap-2">
                <ShieldCheck className="h-5 w-5 text-teal-700" />
                Score global
              </CardTitle>
              <CardDescription>État de santé CMDB du dernier scan</CardDescription>
            </div>
            <Badge tone={scanStatus === 'failed' ? 'crit' : 'good'}>{scanStatusLabel(scanStatus, t)}</Badge>
          </div>
          <div className="mt-4 flex flex-wrap items-end justify-between gap-5">
            <div>
              <p className="text-6xl font-bold text-teal-700">{latestScan?.scores_json?.global ?? 'N/D'}</p>
              <p className="mt-1 text-sm text-slate-500">/100</p>
            </div>
            <div ref={exportMenuRef} className="relative">
              <Button
                type="button"
                variant="secondary"
                disabled={!latestScan}
                onClick={() => setExportMenuOpen((open) => !open)}
              >
                Exporter
              </Button>
              {latestScan && exportMenuOpen ? (
                <div className="absolute right-0 z-20 mt-2 w-44 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                  <a
                    href={`${exportBasePath}/pdf`}
                    className="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
                    onClick={() => setExportMenuOpen(false)}
                  >
                    Exporter en PDF
                  </a>
                  <a
                    href={`${exportBasePath}/csv`}
                    className="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
                    onClick={() => setExportMenuOpen(false)}
                  >
                    Exporter en CSV
                  </a>
                  <a
                    href={`${exportBasePath}/json`}
                    className="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
                    onClick={() => setExportMenuOpen(false)}
                  >
                    Exporter en JSON
                  </a>
                </div>
              ) : null}
            </div>
          </div>

          <div className="mt-5 grid gap-3 md:grid-cols-2">
            {Object.entries(scoreDomains).map(([domain, payload]) => (
              <div key={domain} className="rounded-xl border border-slate-200 bg-white/70 p-3">
                <p className="text-sm uppercase tracking-wide text-slate-500">{domain}</p>
                <p className="text-2xl font-bold">{payload.score}</p>
                <p className="text-xs text-slate-500">Anomalies : {payload.issue_count} | Pénalité : {payload.penalty}</p>
              </div>
            ))}
          </div>

          {latestScan ? (
            <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
              <p className="inline-flex items-center gap-2 text-sm font-semibold">
                <BarChart3 className="h-4 w-4 text-slate-500" />
                Exécution du dernier scan
              </p>
              <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                  <p className="text-[11px] uppercase tracking-wide text-slate-500">Anomalies</p>
                  <p className="text-xl font-semibold text-slate-900">{scanSummary.issue_count ?? topIssues.length}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                  <p className="text-[11px] uppercase tracking-wide text-slate-500">Classes</p>
                  <p className="text-xl font-semibold text-slate-900">{scanSummary.classes_count ?? classSummaries.length}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                  <p className="text-[11px] uppercase tracking-wide text-slate-500">Durée</p>
                  <p className="text-xl font-semibold text-slate-900">{formatDuration(scanSummary.duration_ms)}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                  <p className="text-[11px] uppercase tracking-wide text-slate-500">Impact total</p>
                  <p className="text-xl font-semibold text-slate-900">{scanSummary.total_affected ?? 0}</p>
                </div>
              </div>
              <div className="mt-2 grid gap-2 text-sm md:grid-cols-2">
                <p>Statut : <span className="font-semibold">{scanStatusLabel(scanStatus, t)}</span></p>
                <p>Mode : <span className="font-semibold">{modeLabel(scanSummary.mode ?? latestScan.mode, t)}</span></p>
                <p>Durée : <span className="font-semibold">{formatDuration(scanSummary.duration_ms)}</span></p>
                <p>Classes analysées : <span className="font-semibold">{scanSummary.classes_count ?? classSummaries.length}</span></p>
                <p>Anomalies : <span className="font-semibold">{scanSummary.issue_count ?? topIssues.length}</span></p>
                <p>Total affecté : <span className="font-semibold">{scanSummary.total_affected ?? 0}</span></p>
                <p>Avertissements : <span className="font-semibold">{(scanSummary.warnings ?? []).length}</span></p>
                <p>Métamodèle : <span className="font-semibold">{scanSummary.metamodel_source ?? 'N/D'}</span></p>
                <p>Détail source : <span className="font-semibold">{scanSummary.metamodel_source_detail ?? 'N/D'}</span></p>
                <p>Règles d&apos;acquittement : <span className="font-semibold">{scanSummary.acknowledgements?.active_rules ?? 0}</span></p>
                <p>Contrôles ignorés (acquittements) : <span className="font-semibold">{scanSummary.acknowledgements?.skipped_checks ?? 0}</span></p>
              </div>
              {scanStatus === 'failed' ? (
                <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                  <p className="font-semibold">Erreur de scan</p>
                  <p className="mt-1">{scanSummary.error ?? 'Erreur inconnue'}</p>
                </div>
              ) : null}
              {scanSummary.discovery_error ? (
                <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
                  <p className="font-semibold">Avertissement de découverte</p>
                  <p className="mt-1">{scanSummary.discovery_error}</p>
                </div>
              ) : null}
              {(scanSummary.warnings ?? []).length > 0 ? (
                <div className="mt-3 space-y-1 text-xs text-amber-700">
                  {(scanSummary.warnings ?? []).slice(0, 5).map((warning) => (
                    <p key={warning}>- {warning}</p>
                  ))}
                </div>
              ) : null}
            </div>
          ) : null}
        </Card>

        <Card className="oq-appear h-fit xl:sticky xl:top-24">
          <CardTitle className="inline-flex items-center gap-2">
            <PlayCircle className="h-5 w-5 text-teal-700" />
            Lancer un scan
          </CardTitle>
          <CardDescription>Mode delta par défaut, complet ou classes ciblées</CardDescription>
          <form className="mt-4 space-y-3" onSubmit={submitScan}>
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Connexion</label>
              <Select
                value={selectedConnection ?? ''}
                onChange={(e) => {
                  const nextConnection = e.target.value ? Number(e.target.value) : null;

                  setSelectedConnection(nextConnection);
                  setCatalogSearch('');
                  form.setData('classes', '');

                  router.get(
                    appPath(''),
                    nextConnection ? { connection: nextConnection } : {},
                    {
                      preserveScroll: true,
                      preserveState: true,
                      replace: true,
                    }
                  );
                }}
              >
                {connections.map((connection) => (
                  <option key={connection.id} value={connection.id}>{connection.name}</option>
                ))}
              </Select>
            </div>
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Mode</label>
              <Select value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)}>
                <option value="delta">Delta</option>
                <option value="full">Complet</option>
              </Select>
            </div>

            <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div className="flex items-center justify-between gap-2">
                <label className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                  <SearchCode className="h-3.5 w-3.5" />
                  Classes détectées ({discoveredClasses.length})
                </label>
                <div className="flex items-center gap-2">
                  {selectedClasses.length > 0 ? <Badge tone="info">{selectedClasses.length} sélectionnée(s)</Badge> : null}
                  <Button
                    type="button"
                    variant="outline"
                    onClick={discoverClasses}
                    disabled={discoverForm.processing || !selectedConnection}
                  >
                    {discoverForm.processing ? 'Découverte...' : 'Découvrir les classes'}
                  </Button>
                </div>
              </div>
              <Input
                className="mt-2"
                placeholder="Filtrer les classes détectées"
                value={catalogSearch}
                onChange={(e) => setCatalogSearch(e.target.value)}
              />
              <div className="mt-2 flex max-h-32 flex-wrap gap-2 overflow-auto">
                {filteredDiscoveredClasses.map((className) => {
                  const selected = selectedClasses.includes(className);
                  return (
                    <button
                      key={className}
                      type="button"
                      onClick={() => toggleClassSelection(className)}
                      className={`rounded-full border px-2 py-1 text-xs ${selected ? 'border-teal-600 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white text-slate-700'}`}
                    >
                      {className}
                    </button>
                  );
                })}
                {filteredDiscoveredClasses.length === 0 ? (
                  <p className="text-xs text-slate-500">
                    Aucune classe détectée pour cette connexion. Lance d&apos;abord un scan complet.
                  </p>
                ) : null}
              </div>
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Classes (CSV, optionnel)</label>
              <Input placeholder="Server,Person,Ticket" value={form.data.classes} onChange={(e) => form.setData('classes', e.target.value)} />
            </div>
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Seuil d&apos;obsolescence (jours)</label>
              <Input type="number" min="1" max="3650" value={form.data.thresholdDays} onChange={(e) => form.setData('thresholdDays', Number(e.target.value))} />
            </div>
            <div className="rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
              <p className="inline-flex items-center gap-1">
                <Filter className="h-3.5 w-3.5" />
                Plan : {modeLabel(form.data.mode, t)} | classes ciblées : {selectedClasses.length} | seuil : {form.data.thresholdDays} jours | forcer classes : {form.data.forceSelectedClasses ? 'oui' : 'non'}
              </p>
              <p className="mt-1">Le scan est exécuté immédiatement si Redis n&apos;est pas actif.</p>
            </div>
            <label className="flex items-center gap-2 text-xs text-slate-700">
              <input
                type="checkbox"
                checked={Boolean(form.data.forceSelectedClasses)}
                onChange={(e) => form.setData('forceSelectedClasses', e.target.checked)}
              />
              Forcer les contrôles pour les classes ciblées (ignore les acquittements sur ces classes)
            </label>
            <Button type="submit" className="w-full" disabled={form.processing || !selectedConnection}>
              {form.processing ? 'Exécution en cours…' : 'Exécuter'}
            </Button>
            {scanProgress.active ? (
              <div className="rounded-xl border border-teal-200 bg-teal-50 p-3 text-xs text-teal-900">
                <div className="flex items-center gap-2">
                  <span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-teal-600 border-t-transparent" />
                  <p className="inline-flex items-center gap-1 font-semibold">
                    <Sparkles className="h-3.5 w-3.5" />
                    Scan en cours...
                  </p>
                </div>
                <p className="mt-1">Temps écoulé : {scanProgress.elapsedSeconds} s</p>
                <p className="mt-1">Étape estimée : {scanStageFromElapsed(scanProgress.elapsedSeconds)}</p>
                <p className="mt-1 text-[11px] text-teal-800">
                  Le scan peut prendre du temps selon le volume iTop. Ne ferme pas cette page.
                </p>
              </div>
            ) : null}
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                disabled={!selectedConnection}
                onClick={() => setShowScanLogs((open) => !open)}
              >
                {showScanLogs ? 'Masquer les logs du scan' : 'Voir les logs du scan'}
              </Button>
              {showScanLogs ? (
                <Button
                  type="button"
                  variant="outline"
                  disabled={!selectedConnection || scanLogState.loading}
                  onClick={() => void fetchScanLogs(false)}
                >
                  {scanLogState.loading ? 'Chargement...' : 'Actualiser'}
                </Button>
              ) : null}
            </div>
            {showScanLogs ? (
              <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-semibold">
                    Logs scan {scanLogState.scanId ? `#${scanLogState.scanId}` : '(connexion)'}
                  </p>
                  <p className="text-[11px] text-slate-500">
                    {scanLogState.running ? 'en cours' : 'inactif'} | {scanLogState.updatedAt ? `Mise à jour ${new Date(scanLogState.updatedAt).toLocaleTimeString()}` : 'pas encore chargé'}
                  </p>
                </div>
                {scanLogState.error ? (
                  <p className="mt-2 rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-rose-700">
                    Erreur des logs : {scanLogState.error}
                  </p>
                ) : null}
                <pre className="mt-2 max-h-56 overflow-auto rounded-lg bg-slate-900 p-2 text-[11px] leading-4 text-slate-100">
                  {scanLogState.lines.length > 0
                    ? scanLogState.lines.join('\n')
                    : 'Aucune ligne de scan récente. Lance un scan ou clique sur "Actualiser".'}
                </pre>
              </div>
            ) : null}
          </form>
        </Card>
      </div>

      <div className="mt-5 grid gap-5 xl:grid-cols-2">
        <Card className="oq-appear">
          <CardTitle className="inline-flex items-center gap-2">
            <CircleAlert className="h-5 w-5 text-amber-600" />
            Top anomalies actionnables
          </CardTitle>
          <div className="mt-3 space-y-2">
            {topIssues.length === 0 ? <p className="text-sm text-slate-500">Aucune anomalie sur le dernier scan.</p> : null}
            {topIssues.map((issue) => (
              <div key={issue.id} className="rounded-xl border border-slate-200 p-3">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold">{issue.title}</p>
                    <p className="text-xs text-slate-500">{issue.code} | {issue.domain}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={severityTone(issue.severity)}>{issue.severity}</Badge>
                    <p className="text-sm font-semibold">{issue.affected_count}</p>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card className="oq-appear">
          <CardTitle className="inline-flex items-center gap-2">
            <Clock3 className="h-5 w-5 text-slate-500" />
            Historique récent
          </CardTitle>
          <div className="mt-3 oq-table-wrap">
            <table className="min-w-[620px] w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-slate-500">
                  <th className="py-2">Scan</th>
                  <th className="py-2">Mode</th>
                  <th className="py-2">Score</th>
                  <th className="py-2">Anomalies</th>
                  <th className="py-2">Actions</th>
                </tr>
              </thead>
              <tbody>
                {recentScans.map((scan) => (
                  <tr key={scan.id} className="border-b border-slate-100">
                    <td className="py-2">#{scan.id}</td>
                    <td className="py-2">{modeLabel(scan.mode, t)}</td>
                    <td className="py-2">{scan?.scores_json?.global ?? 'N/D'}</td>
                    <td className="py-2">
                      <Link className="text-teal-700 hover:underline" href={appPath(`issues/${scan.id}`)}>Voir</Link>
                    </td>
                    <td className="py-2">
                      <button
                        type="button"
                        className="text-rose-700 hover:underline"
                        onClick={() => deleteScanHistory(scan.id)}
                      >
                        Supprimer
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      </div>

      <div className="mt-5 grid gap-5 xl:grid-cols-2">
        <Card className="oq-appear">
          <CardTitle className="inline-flex items-center gap-2">
            <FolderTree className="h-5 w-5 text-slate-500" />
            Détail d&apos;exécution par classe
          </CardTitle>
          <CardDescription>Classes les plus coûteuses / les plus problématiques</CardDescription>
          <div className="mt-3 oq-table-wrap">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-slate-500">
                  <th className="py-2">Classe</th>
                  <th className="py-2">Delta</th>
                  <th className="py-2">Contrôles</th>
                  <th className="py-2">Ignorés (acquittements)</th>
                  <th className="py-2">Anomalies</th>
                  <th className="py-2">Durée</th>
                </tr>
              </thead>
              <tbody>
                {classBreakdown.length === 0 ? (
                  <tr>
                    <td className="py-3 text-slate-500" colSpan={6}>Aucun détail disponible.</td>
                  </tr>
                ) : classBreakdown.map((entry) => (
                  <tr key={entry.class} className="border-b border-slate-100 align-top">
                    <td className="py-2 font-medium">{entry.class}</td>
                    <td className="py-2">{entry.delta_applied ? 'oui' : 'non'}</td>
                    <td className="py-2">{entry.checks_executed}/{entry.checks_applicable}</td>
                    <td className="py-2">{entry.checks_skipped_ack ?? 0}</td>
                    <td className="py-2">{entry.issues_found}</td>
                    <td className="py-2">{formatDuration(entry.duration_ms)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        <Card className="oq-appear">
          <CardTitle className="inline-flex items-center gap-2">
            <CheckCircle2 className="h-5 w-5 text-teal-700" />
            Détail d&apos;exécution par contrôle
          </CardTitle>
          <CardDescription>Applicabilité et rendement des contrôles auto-adaptatifs</CardDescription>
          <div className="mt-3 oq-table-wrap">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-slate-500">
                  <th className="py-2">Contrôle</th>
                  <th className="py-2">Applicable</th>
                  <th className="py-2">Exécuté</th>
                  <th className="py-2">Ignorés (acquittements)</th>
                  <th className="py-2">Anomalies</th>
                  <th className="py-2">Erreurs</th>
                </tr>
              </thead>
              <tbody>
                {checkBreakdown.length === 0 ? (
                  <tr>
                    <td className="py-3 text-slate-500" colSpan={6}>Aucun détail disponible.</td>
                  </tr>
                ) : checkBreakdown.map((entry) => (
                  <tr key={entry.check} className="border-b border-slate-100">
                    <td className="py-2 font-medium">{entry.check}</td>
                    <td className="py-2">{entry.applicable_count}</td>
                    <td className="py-2">{entry.executed_count}</td>
                    <td className="py-2">{entry.ack_skipped_count ?? 0}</td>
                    <td className="py-2">{entry.issues_found}</td>
                    <td className="py-2">{entry.error_count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      </div>
    </AppLayout>
  );
}
