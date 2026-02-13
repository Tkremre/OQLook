import React, { useEffect, useMemo, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import { Card, CardDescription, CardTitle } from '../../components/ui/card';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input, Select } from '../../components/ui/input';
import { Link, router } from '@inertiajs/react';
import { appPath } from '../../lib/app-path';
import { useI18n } from '../../i18n';
import {
  CircleCheckBig,
  CircleMinus,
  CircleOff,
  Eye,
  Flame,
  LayoutList,
  ListChecks,
  Search,
  ShieldAlert,
  ShieldCheck,
  ShieldX,
  SlidersHorizontal,
  Sparkles,
  Target,
  X,
} from 'lucide-react';

const ADMIN_PACK_CODES = new Set([
  'OWNERSHIP_MISSING',
  'CLASSIFICATION_MISSING',
  'STATUS_EMPTY',
  'NAME_PLACEHOLDER',
  'STALE_WITHOUT_OWNER',
  'CLASSIFICATION_ORG_LOCATION_MISMATCH',
  'RELATIONS_ORPHAN_EXTERNALKEY',
]);

function toneFromSeverity(severity) {
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

function severityRank(severity) {
  if (severity === 'crit') return 3;
  if (severity === 'warn') return 2;
  if (severity === 'info') return 1;
  return 0;
}

function severityLabel(severity) {
  if (severity === 'crit') return 'Critique';
  if (severity === 'warn') return 'Avertissement';
  if (severity === 'info') return 'Info';
  return severity ?? 'N/D';
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

function resolveIssueClass(issue) {
  return issue?.meta_json?.class ?? issue?.samples?.[0]?.itop_class ?? 'N/D';
}

function isAdminPackIssue(issue) {
  return ADMIN_PACK_CODES.has(String(issue?.code ?? ''));
}

export default function IssuesIndex({ scan, scans = [], issues = [], acknowledgements = [], acknowledgedMap = {} }) {
  const { t } = useI18n();
  const [domainFilter, setDomainFilter] = useState('all');
  const [severityFilter, setSeverityFilter] = useState('all');
  const [classFilter, setClassFilter] = useState('all');
  const [minAffected, setMinAffected] = useState('');
  const [sortBy, setSortBy] = useState('affected_desc');
  const [search, setSearch] = useState('');
  const [quickView, setQuickView] = useState('all');
  const [page, setPage] = useState(1);
  const [rowsPerPage, setRowsPerPage] = useState(100);
  const [showAcknowledgementsModal, setShowAcknowledgementsModal] = useState(false);

  const summary = scan?.summary_json ?? {};
  const scanStatus = summary?.status ?? 'ok';

  const domainOptions = useMemo(() => {
    const items = new Set(issues.map((issue) => issue.domain).filter(Boolean));
    return ['all', ...Array.from(items).sort((a, b) => a.localeCompare(b))];
  }, [issues]);

  const severityOptions = useMemo(() => {
    const items = new Set(issues.map((issue) => issue.severity).filter(Boolean));
    return ['all', ...Array.from(items).sort((a, b) => severityRank(b) - severityRank(a))];
  }, [issues]);

  const classOptions = useMemo(() => {
    const items = new Set(issues.map((issue) => resolveIssueClass(issue)).filter(Boolean));
    return ['all', ...Array.from(items).sort((a, b) => a.localeCompare(b))];
  }, [issues]);

  const filtered = useMemo(() => {
    const minAffectedValue = Number.parseInt(String(minAffected || '').trim(), 10);
    const hasMinAffected = Number.isFinite(minAffectedValue) && minAffectedValue > 0;
    const normalizedSearch = String(search || '').trim().toLowerCase();

    const filteredItems = issues.filter((issue) => {
      const issueClass = resolveIssueClass(issue);

      if (quickView === 'admin_pack' && !isAdminPackIssue(issue)) return false;
      if (quickView === 'critical' && issue.severity !== 'crit') return false;
      if (quickView === 'high_impact' && Number(issue.impact || 0) < 4) return false;

      if (domainFilter !== 'all' && issue.domain !== domainFilter) return false;
      if (severityFilter !== 'all' && issue.severity !== severityFilter) return false;
      if (classFilter !== 'all' && issueClass !== classFilter) return false;
      if (hasMinAffected && Number(issue.affected_count || 0) < minAffectedValue) return false;

      if (normalizedSearch !== '') {
        const haystack = [
          issue.code,
          issue.title,
          issue.domain,
          issue.severity,
          issueClass,
          issue.recommendation,
          issue.suggested_oql,
        ]
          .filter(Boolean)
          .join(' ')
          .toLowerCase();

        if (!haystack.includes(normalizedSearch)) return false;
      }

      return true;
    });

    return filteredItems.sort((a, b) => {
      const classA = resolveIssueClass(a);
      const classB = resolveIssueClass(b);
      switch (sortBy) {
        case 'affected_asc':
          return Number(a.affected_count || 0) - Number(b.affected_count || 0);
        case 'impact_desc':
          return Number(b.impact || 0) - Number(a.impact || 0);
        case 'impact_asc':
          return Number(a.impact || 0) - Number(b.impact || 0);
        case 'severity_desc':
          return severityRank(b.severity) - severityRank(a.severity);
        case 'severity_asc':
          return severityRank(a.severity) - severityRank(b.severity);
        case 'title_asc':
          return String(a.title || '').localeCompare(String(b.title || ''));
        case 'code_asc':
          return String(a.code || '').localeCompare(String(b.code || ''));
        case 'class_asc':
          return classA.localeCompare(classB);
        case 'affected_desc':
        default:
          return Number(b.affected_count || 0) - Number(a.affected_count || 0);
      }
    });
  }, [issues, quickView, domainFilter, severityFilter, classFilter, minAffected, search, sortBy]);

  const quickViewStats = useMemo(() => {
    let adminPack = 0;
    let critical = 0;
    let highImpact = 0;

    for (const issue of issues) {
      if (isAdminPackIssue(issue)) adminPack += 1;
      if (issue.severity === 'crit') critical += 1;
      if (Number(issue.impact || 0) >= 4) highImpact += 1;
    }

    return {
      all: issues.length,
      admin_pack: adminPack,
      critical,
      high_impact: highImpact,
    };
  }, [issues]);

  const filteredStats = useMemo(() => {
    const totals = {
      count: filtered.length,
      affected: 0,
      crit: 0,
      warn: 0,
      info: 0,
    };

    for (const issue of filtered) {
      totals.affected += Number(issue.affected_count || 0);
      if (issue.severity === 'crit') totals.crit += 1;
      else if (issue.severity === 'warn') totals.warn += 1;
      else if (issue.severity === 'info') totals.info += 1;
    }

    return totals;
  }, [filtered]);

  useEffect(() => {
    setPage(1);
  }, [quickView, domainFilter, severityFilter, classFilter, minAffected, sortBy, search, rowsPerPage]);

  const pagination = useMemo(() => {
    const totalItems = filtered.length;
    const safeRowsPerPage = Math.max(10, Number(rowsPerPage) || 100);
    const totalPages = Math.max(1, Math.ceil(totalItems / safeRowsPerPage));
    const currentPage = Math.min(Math.max(1, page), totalPages);
    const start = (currentPage - 1) * safeRowsPerPage;
    const end = start + safeRowsPerPage;
    const items = filtered.slice(start, end);

    return {
      totalItems,
      totalPages,
      currentPage,
      from: totalItems === 0 ? 0 : start + 1,
      to: totalItems === 0 ? 0 : Math.min(end, totalItems),
      items,
    };
  }, [filtered, page, rowsPerPage]);

  const acknowledgeIssue = (issueId) => {
    router.post(appPath(`issues/${issueId}/acknowledge`), {}, { preserveScroll: true });
  };

  const deacknowledgeIssue = (issueId) => {
    router.delete(appPath(`issues/${issueId}/acknowledge`), { preserveScroll: true });
  };

  const deacknowledgeRule = (ruleId) => {
    router.delete(appPath(`acknowledgements/${ruleId}`), { preserveScroll: true });
  };

  const deleteScan = () => {
    if (!scan?.id) return;
    if (!window.confirm(`Supprimer le scan #${scan.id} ? Cette action est irréversible.`)) return;

    router.delete(appPath(`scans/${scan.id}`), {
      preserveScroll: true,
      data: { from: 'issues' },
    });
  };

  const resumeScan = () => {
    if (!scan?.id) return;
    if (!window.confirm(`Reprendre le scan #${scan.id} depuis le dernier point de progression connu ?`)) return;

    router.post(appPath(`scans/${scan.id}/resume`), {}, { preserveScroll: true });
  };

  const canResumeScan = Boolean(scan?.id) && (scanStatus === 'failed' || !scan?.finished_at);

  const resetFilters = () => {
    setQuickView('all');
    setDomainFilter('all');
    setSeverityFilter('all');
    setClassFilter('all');
    setMinAffected('');
    setSortBy('affected_desc');
    setSearch('');
    setRowsPerPage(100);
    setPage(1);
  };

  return (
    <AppLayout
      title={t('pages.issues.title')}
      subtitle={t('pages.issues.subtitle')}
      fullWidth
    >
      <div className="space-y-5">
        <div className="space-y-5">
          <Card className="oq-appear">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div>
                <CardTitle className="inline-flex items-center gap-2">
                  <LayoutList className="h-5 w-5 text-teal-700" />
                  Contexte du scan #{scan?.id ?? 'N/D'}
                </CardTitle>
                <CardDescription>
                  Connexion: {scan?.connection?.name ?? 'N/D'} | Mode: {modeLabel(summary.mode ?? scan?.mode, t)}
                </CardDescription>
              </div>
              {scan?.id ? (
                <div className="flex flex-wrap items-center gap-2">
                  {canResumeScan ? (
                    <Button type="button" variant="outline" className="text-sm" onClick={resumeScan}>
                      Reprendre le scan
                    </Button>
                  ) : null}
                  <Button type="button" variant="danger" className="text-sm" onClick={deleteScan}>
                    Supprimer ce scan
                  </Button>
                </div>
              ) : null}
            </div>
            <div className="mt-3 grid gap-2 text-sm md:grid-cols-2 xl:grid-cols-5">
              <p className="inline-flex items-center gap-2">
                {scanStatus === 'failed' ? <ShieldX className="h-4 w-4 text-rose-600" /> : <ShieldCheck className="h-4 w-4 text-emerald-600" />}
                Statut: <span className="font-semibold">{scanStatusLabel(scanStatus, t)}</span>
              </p>
              <p>Score global: <span className="font-semibold">{scan?.scores_json?.global ?? 'N/D'}</span></p>
              <p>Anomalies: <span className="font-semibold">{summary.issue_count ?? issues.length}</span></p>
              <p>Total affecté: <span className="font-semibold">{summary.total_affected ?? 0}</span></p>
              <p>Durée: <span className="font-semibold">{formatDuration(summary.duration_ms)}</span></p>
              <p>Classes: <span className="font-semibold">{summary.classes_count ?? (summary.classes_scanned ?? []).length}</span></p>
              <p>Métamodèle: <span className="font-semibold">{summary.metamodel_source ?? 'N/D'}</span></p>
              <p>Détail source: <span className="font-semibold">{summary.metamodel_source_detail ?? 'N/D'}</span></p>
              <p>Règles d&apos;acquittement: <span className="font-semibold">{summary.acknowledgements?.active_rules ?? 0}</span></p>
              <p>Contrôles ignorés (acquittements): <span className="font-semibold">{summary.acknowledgements?.skipped_checks ?? 0}</span></p>
            </div>
            {scanStatus === 'failed' ? (
              <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                <p className="font-semibold">Erreur de scan</p>
                <p className="mt-1">{summary.error ?? 'Erreur inconnue'}</p>
              </div>
            ) : null}
            {summary.discovery_error ? (
              <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
                <p className="font-semibold">Avertissement de découverte</p>
                <p className="mt-1">{summary.discovery_error}</p>
              </div>
            ) : null}
            {(summary.warnings ?? []).length > 0 ? (
              <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
                {(summary.warnings ?? []).slice(0, 8).map((warning) => (
                  <p key={warning}>- {warning}</p>
                ))}
              </div>
            ) : null}
          </Card>

          <Card className="oq-appear">
            <CardTitle className="inline-flex items-center gap-2">
              <SlidersHorizontal className="h-5 w-5 text-slate-600" />
              Filtres et tri
            </CardTitle>
            <CardDescription>Affinage multicritère pour exploiter les anomalies</CardDescription>
            <div className="mt-3 flex flex-col gap-2 xl:flex-row xl:items-start xl:justify-between">
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={() => setQuickView('all')}
                  className={`inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                    quickView === 'all'
                      ? 'border-teal-600 bg-teal-50 text-teal-800'
                      : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'
                  }`}
                >
                  <ListChecks className="h-3.5 w-3.5" />
                  Toutes ({quickViewStats.all})
                </button>
                <button
                  type="button"
                  onClick={() => setQuickView('admin_pack')}
                  className={`inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                    quickView === 'admin_pack'
                      ? 'border-sky-600 bg-sky-50 text-sky-800'
                      : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'
                  }`}
                >
                  <Sparkles className="h-3.5 w-3.5" />
                  Admin CMDB ({quickViewStats.admin_pack})
                </button>
                <button
                  type="button"
                  onClick={() => setQuickView('critical')}
                  className={`inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                    quickView === 'critical'
                      ? 'border-rose-600 bg-rose-50 text-rose-800'
                      : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'
                  }`}
                >
                  <Flame className="h-3.5 w-3.5" />
                  Critiques ({quickViewStats.critical})
                </button>
                <button
                  type="button"
                  onClick={() => setQuickView('high_impact')}
                  className={`inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                    quickView === 'high_impact'
                      ? 'border-amber-600 bg-amber-50 text-amber-800'
                      : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'
                  }`}
                >
                  <Target className="h-3.5 w-3.5" />
                  Impact {'>='} 4 ({quickViewStats.high_impact})
                </button>
              </div>
              <div className="rounded-xl border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700 xl:min-w-[260px]">
                <p>Visibles : <span className="font-semibold">{filteredStats.count}</span> / {issues.length}</p>
                <p>Total affecté : <span className="font-semibold">{filteredStats.affected}</span></p>
                <p>Crit/Avertissement/Info : <span className="font-semibold">{filteredStats.crit}/{filteredStats.warn}/{filteredStats.info}</span></p>
              </div>
            </div>
            <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
              <div className="xl:col-span-2">
                <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
                  <ListChecks className="h-3.5 w-3.5" />
                  Scan
                </label>
                <Select value={scan?.id ?? ''} onChange={(e) => (window.location.href = appPath(`issues/${e.target.value}`))}>
                  {scans.map((item) => (
                    <option key={item.id} value={item.id}>
                      #{item.id} | {item.connection?.name ?? `conn#${item.connection_id}`} | {modeLabel(item.mode, t)}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="xl:col-span-2">
                <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
                  <Search className="h-3.5 w-3.5" />
                  Recherche globale
                </label>
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="code, titre, classe, recommandation, OQL"
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-semibold text-slate-600">Tri</label>
                <Select value={sortBy} onChange={(e) => setSortBy(e.target.value)}>
                  <option value="affected_desc">Affecté décroissant</option>
                  <option value="affected_asc">Affecté croissant</option>
                  <option value="severity_desc">Sévérité décroissante</option>
                  <option value="severity_asc">Sévérité croissante</option>
                  <option value="impact_desc">Impact décroissant</option>
                  <option value="impact_asc">Impact croissant</option>
                  <option value="title_asc">Titre A-Z</option>
                  <option value="code_asc">Code A-Z</option>
                  <option value="class_asc">Classe A-Z</option>
                </Select>
              </div>
              <div className="flex items-end">
                <Button type="button" variant="outline" className="w-full" onClick={resetFilters}>
                  Réinitialiser
                </Button>
              </div>
              <div>
                <label className="mb-1 block text-xs font-semibold text-slate-600">Domaine</label>
                <Select value={domainFilter} onChange={(e) => setDomainFilter(e.target.value)}>
                  {domainOptions.map((option) => (
                    <option key={option} value={option}>{option}</option>
                  ))}
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-semibold text-slate-600">Sévérité</label>
                <Select value={severityFilter} onChange={(e) => setSeverityFilter(e.target.value)}>
                  {severityOptions.map((option) => (
                    <option key={option} value={option}>{option}</option>
                  ))}
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-semibold text-slate-600">Classe</label>
                <Select value={classFilter} onChange={(e) => setClassFilter(e.target.value)}>
                  {classOptions.map((option) => (
                    <option key={option} value={option}>{option}</option>
                  ))}
                </Select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-semibold text-slate-600">Minimum affecté</label>
                <Input value={minAffected} onChange={(e) => setMinAffected(e.target.value)} placeholder="0" />
              </div>
              <div className="flex items-end xl:col-start-6">
                <Button
                  type="button"
                  variant="outline"
                  className="inline-flex h-10 w-full items-center justify-center gap-2 sm:h-9"
                  onClick={() => setShowAcknowledgementsModal(true)}
                >
                  <CircleCheckBig className="h-4 w-4" />
                  Acquittements actifs ({acknowledgements.length})
                </Button>
              </div>
            </div>
          </Card>
        </div>

        <div className="grid gap-5">
          <Card className="oq-appear overflow-hidden">
            <CardTitle className="inline-flex items-center gap-2">
              <ShieldAlert className="h-5 w-5 text-amber-600" />
              Anomalies ({filtered.length})
            </CardTitle>
            <CardDescription>Vue opérationnelle, triable et filtrable</CardDescription>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
              <p>
                Affichage <span className="font-semibold">{pagination.from}</span>-<span className="font-semibold">{pagination.to}</span> sur{' '}
                <span className="font-semibold">{pagination.totalItems}</span>
              </p>
              <div className="flex flex-wrap items-center gap-2">
                <label className="text-xs font-semibold text-slate-600" htmlFor="issuesRowsPerPage">
                  Lignes/page
                </label>
                <Select
                  id="issuesRowsPerPage"
                  value={String(rowsPerPage)}
                  onChange={(e) => setRowsPerPage(Number(e.target.value) || 100)}
                  className="h-10 min-w-[92px] sm:h-8"
                >
                  <option value="50">50</option>
                  <option value="100">100</option>
                  <option value="200">200</option>
                  <option value="500">500</option>
                </Select>
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 px-3 text-xs sm:h-8"
                  onClick={() => setPage((prev) => Math.max(1, prev - 1))}
                  disabled={pagination.currentPage <= 1}
                >
                  Précédent
                </Button>
                <span className="min-w-[96px] text-center text-xs font-semibold text-slate-700">
                  Page {pagination.currentPage}/{pagination.totalPages}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 px-3 text-xs sm:h-8"
                  onClick={() => setPage((prev) => Math.min(pagination.totalPages, prev + 1))}
                  disabled={pagination.currentPage >= pagination.totalPages}
                >
                  Suivant
                </Button>
              </div>
            </div>
            {quickView !== 'all' ? (
              <div className="mt-2">
                <Badge tone="info">
                  Vue rapide active: {quickView === 'admin_pack' ? 'Admin CMDB' : quickView === 'critical' ? 'Critiques' : 'Impact >= 4'}
                </Badge>
              </div>
            ) : null}
            <div className="mt-3 space-y-2 md:hidden">
              {filtered.length === 0 ? (
                <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-500">
                  Aucune anomalie ne correspond aux filtres.
                </div>
              ) : pagination.items.map((issue) => (
                <article key={issue.id} className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                  <div className="flex items-start justify-between gap-2">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-600">{issue.code}</p>
                    <Badge tone={toneFromSeverity(issue.severity)}>{severityLabel(issue.severity)}</Badge>
                  </div>
                  <p className="mt-1 text-sm font-semibold text-slate-900">{issue.title}</p>
                  <div className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-600">
                    <p>Domaine: <span className="font-semibold text-slate-800">{issue.domain || 'N/D'}</span></p>
                    <p>Classe: <span className="font-semibold text-slate-800">{resolveIssueClass(issue)}</span></p>
                    <p>Impact: <span className="font-semibold text-slate-800">{issue.impact ?? 0}</span></p>
                    <p>Affecté: <span className="font-semibold text-slate-800">{issue.affected_count ?? 0}</span></p>
                  </div>
                  <p className="mt-2 text-xs text-slate-600">{issue.recommendation ? issue.recommendation : 'N/D'}</p>
                  <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
                    <Link href={appPath(`issue/${issue.id}`)} className="inline-flex items-center gap-1 text-teal-700 hover:underline">
                      <Eye className="h-3.5 w-3.5" />
                      Détail
                    </Link>
                    {issue?.meta_json?.class ? (
                      <>
                        {acknowledgedMap[`${issue.meta_json.class}|${issue.code}`] ? (
                          <button
                            type="button"
                            className="inline-flex items-center gap-1 text-rose-700 hover:underline"
                            onClick={() => deacknowledgeIssue(issue.id)}
                          >
                            <CircleMinus className="h-3.5 w-3.5" />
                            Désacquitter
                          </button>
                        ) : (
                          <button
                            type="button"
                            className="inline-flex items-center gap-1 text-amber-700 hover:underline"
                            onClick={() => acknowledgeIssue(issue.id)}
                          >
                            <CircleCheckBig className="h-3.5 w-3.5" />
                            Acquitter
                          </button>
                        )}
                      </>
                    ) : null}
                  </div>
                </article>
              ))}
            </div>

            <div className="mt-3 hidden md:block oq-table-wrap oq-table-wrap--issues">
              <table className="min-w-[1280px] w-full text-sm leading-relaxed">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-slate-500">
                    <th className="oq-col-sticky-left px-3 py-3 text-xs font-semibold uppercase tracking-wide">Code</th>
                    <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wide">Titre</th>
                    <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wide">Domaine</th>
                    <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wide">Sévérité</th>
                    <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wide">Impact</th>
                    <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wide">Affecté</th>
                    <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wide">Classe</th>
                    <th className="px-3 py-3 text-xs font-semibold uppercase tracking-wide">Recommandation</th>
                    <th className="oq-col-sticky-right px-3 py-3 text-xs font-semibold uppercase tracking-wide">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="py-3 text-slate-500">
                        Aucune anomalie ne correspond aux filtres.
                      </td>
                    </tr>
                  ) : pagination.items.map((issue) => (
                    <tr key={issue.id} className="border-b border-slate-100 align-top">
                      <td className="oq-col-sticky-left px-3 py-3 text-xs font-semibold text-slate-700">
                        <div className="flex flex-wrap items-center gap-1">
                          <span>{issue.code}</span>
                          {isAdminPackIssue(issue) ? <Badge tone="info" className="!px-2 !py-0.5 !text-[10px]">admin</Badge> : null}
                        </div>
                      </td>
                      <td className="px-3 py-3 text-[15px] font-semibold text-slate-800">{issue.title}</td>
                      <td className="px-3 py-3">{issue.domain}</td>
                      <td className="px-3 py-3"><Badge tone={toneFromSeverity(issue.severity)}>{severityLabel(issue.severity)}</Badge></td>
                      <td className="px-3 py-3">{issue.impact}</td>
                      <td className="px-3 py-3 font-semibold">{issue.affected_count}</td>
                      <td className="px-3 py-3">{resolveIssueClass(issue)}</td>
                      <td className="px-3 py-3 text-sm text-slate-700">{issue.recommendation ? issue.recommendation : 'N/D'}</td>
                      <td className="oq-col-sticky-right px-3 py-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <Link href={appPath(`issue/${issue.id}`)} className="inline-flex items-center gap-1 text-teal-700 hover:underline">
                            <Eye className="h-3.5 w-3.5" />
                            Détail
                          </Link>
                          {issue?.meta_json?.class ? (
                            <>
                              {acknowledgedMap[`${issue.meta_json.class}|${issue.code}`] ? (
                                <button
                                  type="button"
                                  className="inline-flex items-center gap-1 text-rose-700 hover:underline"
                                  onClick={() => deacknowledgeIssue(issue.id)}
                                >
                                  <CircleMinus className="h-3.5 w-3.5" />
                                  Désacquitter
                                </button>
                              ) : (
                                <button
                                  type="button"
                                  className="inline-flex items-center gap-1 text-amber-700 hover:underline"
                                  onClick={() => acknowledgeIssue(issue.id)}
                                >
                                  <CircleCheckBig className="h-3.5 w-3.5" />
                                  Acquitter
                                </button>
                              )}
                            </>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {pagination.totalPages > 1 ? (
              <div className="mt-3 flex flex-wrap items-center justify-end gap-2 text-xs">
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 px-3 text-xs sm:h-8"
                  onClick={() => setPage((prev) => Math.max(1, prev - 1))}
                  disabled={pagination.currentPage <= 1}
                >
                  Précédent
                </Button>
                <span className="min-w-[96px] text-center text-xs font-semibold text-slate-700">
                  Page {pagination.currentPage}/{pagination.totalPages}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 px-3 text-xs sm:h-8"
                  onClick={() => setPage((prev) => Math.min(pagination.totalPages, prev + 1))}
                  disabled={pagination.currentPage >= pagination.totalPages}
                >
                  Suivant
                </Button>
              </div>
            ) : null}
          </Card>

        </div>
      </div>

      {showAcknowledgementsModal ? (
        <div
          className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-label="Acquittements actifs"
          onClick={() => setShowAcknowledgementsModal(false)}
        >
          <div
            className="relative w-full max-w-4xl rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xl"
            onClick={(event) => event.stopPropagation()}
          >
            <button
              type="button"
              className="absolute right-4 top-4 inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-900"
              onClick={() => setShowAcknowledgementsModal(false)}
              aria-label="Fermer"
              title="Fermer"
            >
              <X className="h-4 w-4" />
            </button>

            <CardTitle className="inline-flex items-center gap-2">
              <CircleCheckBig className="h-5 w-5 text-teal-700" />
              Acquittements actifs ({acknowledgements.length})
            </CardTitle>
            <CardDescription>Portée : connexion {scan?.connection?.name ?? 'N/D'}</CardDescription>

            <div className="mt-3 oq-table-wrap oq-table-wrap--ack max-h-[65vh]">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-slate-500">
                    <th className="py-2">Classe</th>
                    <th className="py-2">Code</th>
                    <th className="py-2">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {acknowledgements.length === 0 ? (
                    <tr>
                      <td colSpan={3} className="py-3 text-slate-500">Aucun acquittement actif.</td>
                    </tr>
                  ) : acknowledgements.map((ack) => (
                    <tr key={ack.id} className="border-b border-slate-100 align-top">
                      <td className="py-2 text-xs">{ack.itop_class}</td>
                      <td className="py-2 text-xs">{ack.issue_code}</td>
                      <td className="py-2">
                        <button
                          type="button"
                          className="inline-flex items-center gap-1 text-rose-700 hover:underline"
                          onClick={() => deacknowledgeRule(ack.id)}
                        >
                          <CircleOff className="h-3.5 w-3.5" />
                          Désacquitter
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      ) : null}
    </AppLayout>
  );
}


