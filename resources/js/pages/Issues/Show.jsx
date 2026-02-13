import React, { useEffect, useMemo, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import { Card, CardDescription, CardTitle } from '../../components/ui/card';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input, Select } from '../../components/ui/input';
import {
  ArrowUpRight,
  CircleCheckBig,
  CircleGauge,
  CircleMinus,
  Database,
  FileText,
  Filter,
  Lightbulb,
  ListChecks,
  RefreshCw,
  Search,
  Sparkles,
} from 'lucide-react';
import { useI18n } from '../../i18n';
import { appPath } from '../../lib/app-path';

function severityLabel(severity) {
  if (severity === 'crit') return 'Critique';
  if (severity === 'warn') return 'Avertissement';
  if (severity === 'info') return 'Info';
  return severity ?? 'N/D';
}

function modeLabel(mode, t) {
  if (mode === 'full') return t('common.mode.full');
  if (mode === 'delta') return t('common.mode.delta');
  return mode ?? t('common.nd');
}

function formatInsightValue(value) {
  if (Array.isArray(value)) {
    if (value.length === 0) return '[]';
    return value
      .map((item) => {
        if (item === null || item === undefined) return '';
        if (typeof item === 'object') return JSON.stringify(item);
        return String(item);
      })
      .filter(Boolean)
      .join(' | ');
  }

  if (value === null || value === undefined || value === '') {
    return 'N/D';
  }

  if (typeof value === 'object') {
    return JSON.stringify(value);
  }

  return String(value);
}

function issueInsights(issue) {
  const meta = issue?.meta_json ?? {};
  const rows = [];

  if (meta.class) rows.push(['Classe', meta.class]);
  if (meta.staleness_field) rows.push(['Champ stale', meta.staleness_field]);
  if (meta.threshold_days) rows.push(['Seuil (jours)', meta.threshold_days]);
  if (meta.max_records) rows.push(['Cap analyse', meta.max_records]);
  if (meta.owner_fields) rows.push(['Ownership fields', meta.owner_fields]);
  if (meta.classification_fields) rows.push(['Classification fields', meta.classification_fields]);
  if (meta.placeholder_terms) rows.push(['Placeholder terms', meta.placeholder_terms]);
  if (meta.attributes_with_orphans) rows.push(['Attributs orphelins', meta.attributes_with_orphans]);

  return rows;
}

function compareNullable(a, b) {
  const left = String(a ?? '').toLowerCase();
  const right = String(b ?? '').toLowerCase();
  return left.localeCompare(right);
}

function objectSort(objects, sortBy) {
  const copy = [...objects];
  copy.sort((a, b) => {
    if (sortBy === 'class_asc') return compareNullable(a.itop_class, b.itop_class) || compareNullable(a.name, b.name);
    if (sortBy === 'class_desc') return compareNullable(b.itop_class, a.itop_class) || compareNullable(a.name, b.name);
    if (sortBy === 'id_asc') return compareNullable(a.itop_id, b.itop_id);
    if (sortBy === 'id_desc') return compareNullable(b.itop_id, a.itop_id);
    if (sortBy === 'name_desc') return compareNullable(b.name, a.name);
    return compareNullable(a.name, b.name);
  });
  return copy;
}

export default function IssueShow({ issue }) {
  const { t } = useI18n();
  const severityTone = issue?.severity === 'crit' ? 'crit' : issue?.severity === 'warn' ? 'warn' : 'info';
  const insights = issueInsights(issue);
  const [objectsLoading, setObjectsLoading] = useState(false);
  const [objectsError, setObjectsError] = useState('');
  const [objects, setObjects] = useState([]);
  const [objectsMeta, setObjectsMeta] = useState({
    count: 0,
    max_records: 0,
    capped: false,
    source: null,
    warning: null,
  });
  const [objectSearch, setObjectSearch] = useState('');
  const [objectSortBy, setObjectSortBy] = useState('name_asc');
  const [ackFilter, setAckFilter] = useState('all');
  const [ackMap, setAckMap] = useState({});
  const [busyObjectKey, setBusyObjectKey] = useState(null);

  const loadImpactedObjects = async () => {
    setObjectsLoading(true);
    setObjectsError('');

    try {
      const response = await window.axios.get(appPath(`issue/${issue.id}/objects`), {
        headers: { Accept: 'application/json' },
      });
      const payload = response?.data ?? {};

      if (!payload.ok) {
        throw new Error(payload.error || 'Chargement des objets impactés impossible.');
      }

      setObjects(Array.isArray(payload.objects) ? payload.objects : []);
      setAckMap(payload.acknowledged_map ?? {});
      setObjectsMeta({
        count: Number(payload.count || 0),
        max_records: Number(payload.max_records || 0),
        capped: Boolean(payload.capped),
        source: payload.source ?? null,
        warning: payload.warning ?? null,
      });
    } catch (error) {
      setObjectsError(error?.response?.data?.error || error?.message || 'Chargement des objets impactés impossible.');
      setObjects([]);
      setAckMap({});
      setObjectsMeta({
        count: 0,
        max_records: 0,
        capped: false,
        source: null,
        warning: null,
      });
    } finally {
      setObjectsLoading(false);
    }
  };

  useEffect(() => {
    loadImpactedObjects();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [issue.id]);

  const filteredObjects = useMemo(() => {
    const search = objectSearch.trim().toLowerCase();
    const filtered = objects.filter((obj) => {
      const key = `${obj.itop_class}|${obj.itop_id}`;
      const isAcked = Boolean(ackMap[key]);

      if (ackFilter === 'acked' && !isAcked) return false;
      if (ackFilter === 'unacked' && isAcked) return false;

      if (search === '') return true;
      const haystack = `${obj.itop_class ?? ''} ${obj.itop_id ?? ''} ${obj.name ?? ''}`.toLowerCase();
      return haystack.includes(search);
    });

    return objectSort(filtered, objectSortBy);
  }, [objects, ackMap, ackFilter, objectSearch, objectSortBy]);

  const acknowledgedCount = useMemo(
    () => filteredObjects.filter((obj) => Boolean(ackMap[`${obj.itop_class}|${obj.itop_id}`])).length,
    [filteredObjects, ackMap]
  );

  const toggleObjectAcknowledgement = async (object) => {
    const key = `${object.itop_class}|${object.itop_id}`;
    if (!object?.itop_class || !object?.itop_id || busyObjectKey === key) return;

    setBusyObjectKey(key);
    setObjectsError('');

    try {
      if (ackMap[key]) {
        const response = await window.axios.delete(appPath(`issues/${issue.id}/objects/acknowledge`), {
          headers: { Accept: 'application/json' },
          data: {
            itop_class: object.itop_class,
            itop_id: object.itop_id,
          },
        });

        if (!response?.data?.ok) {
          throw new Error(response?.data?.status || 'Désacquittement objet impossible.');
        }

        setAckMap((previous) => {
          const next = { ...previous };
          delete next[key];
          return next;
        });
      } else {
        const response = await window.axios.post(
          appPath(`issues/${issue.id}/objects/acknowledge`),
          {
            itop_class: object.itop_class,
            itop_id: object.itop_id,
            name: object.name ?? null,
            link: object.link ?? null,
          },
          {
            headers: { Accept: 'application/json' },
          }
        );

        if (!response?.data?.ok) {
          throw new Error(response?.data?.status || 'Acquittement objet impossible.');
        }

        setAckMap((previous) => ({ ...previous, [key]: true }));
      }
    } catch (error) {
      setObjectsError(error?.response?.data?.status || error?.response?.data?.error || error?.message || 'Erreur d’acquittement objet.');
    } finally {
      setBusyObjectKey(null);
    }
  };

  return (
    <AppLayout
      title={t('pages.issueShow.title', { id: issue.id })}
      subtitle={t('pages.issueShow.subtitle')}
      fullWidth
    >
      <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
        <Card className="oq-appear">
          <CardTitle className="inline-flex items-center gap-2">
            <CircleGauge className="h-5 w-5 text-amber-600" />
            {issue.title}
          </CardTitle>
          <CardDescription>{issue.code} | {issue.domain}</CardDescription>
          <div className="mt-4 flex items-center gap-2">
            <Badge tone={severityTone}>{severityLabel(issue.severity)}</Badge>
            <span className="text-sm">Impact: {issue.impact}</span>
            <span className="text-sm">Affectés: {issue.affected_count}</span>
          </div>

          <div className="mt-5 space-y-4">
            <div>
              <p className="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <Lightbulb className="h-3.5 w-3.5" />
                Recommandation
              </p>
              <p className="mt-1 text-sm">{issue.recommendation}</p>
            </div>
            <div>
              <p className="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <FileText className="h-3.5 w-3.5" />
                OQL suggérée
              </p>
              <pre className="mt-1 overflow-auto rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">{issue.suggested_oql}</pre>
            </div>
            {insights.length > 0 ? (
              <div>
                <p className="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <Sparkles className="h-3.5 w-3.5" />
                  Insights admin
                </p>
                <div className="mt-1 grid gap-2 md:grid-cols-2">
                  {insights.map(([label, value]) => (
                    <div key={label} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                      <p className="text-[11px] uppercase tracking-wide text-slate-500">{label}</p>
                      <p className="mt-1 text-xs text-slate-800">{formatInsightValue(value)}</p>
                    </div>
                  ))}
                </div>
              </div>
            ) : null}
          </div>
        </Card>

        <Card className="oq-appear h-fit xl:sticky xl:top-24">
          <CardTitle className="inline-flex items-center gap-2">
            <Database className="h-5 w-5 text-teal-700" />
            Contexte du scan
          </CardTitle>
          <div className="mt-3 text-sm text-slate-600">
            <p>Scan #{issue.scan?.id}</p>
            <p>Connexion: {issue.scan?.connection?.name ?? 'N/D'}</p>
            <p>Mode: {modeLabel(issue.scan?.mode, t)}</p>
          </div>
        </Card>
      </div>

      <Card className="oq-appear mt-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle className="inline-flex items-center gap-2">
              <ListChecks className="h-5 w-5 text-slate-600" />
              Objets impactés ({filteredObjects.length})
            </CardTitle>
            <CardDescription>
              Liste complète iTop pour ce contrôle, avec tri/recherche et acquittement objet.
            </CardDescription>
          </div>
          <Button type="button" variant="outline" onClick={loadImpactedObjects} disabled={objectsLoading}>
            <RefreshCw className={`mr-1.5 h-4 w-4 ${objectsLoading ? 'animate-spin' : ''}`} />
            Actualiser
          </Button>
        </div>

        <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
          <div className="xl:col-span-2">
            <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
              <Search className="h-3.5 w-3.5" />
              Recherche
            </label>
            <Input
              value={objectSearch}
              onChange={(e) => setObjectSearch(e.target.value)}
              placeholder="classe, id, nom"
            />
          </div>
          <div>
            <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
              <Filter className="h-3.5 w-3.5" />
              Filtre acquittement
            </label>
            <Select value={ackFilter} onChange={(e) => setAckFilter(e.target.value)}>
              <option value="all">Tous</option>
              <option value="acked">Acquittés</option>
              <option value="unacked">Non acquittés</option>
            </Select>
          </div>
          <div>
            <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
              <ListChecks className="h-3.5 w-3.5" />
              Tri
            </label>
            <Select value={objectSortBy} onChange={(e) => setObjectSortBy(e.target.value)}>
              <option value="name_asc">Nom A-Z</option>
              <option value="name_desc">Nom Z-A</option>
              <option value="class_asc">Classe A-Z</option>
              <option value="class_desc">Classe Z-A</option>
              <option value="id_asc">ID croissant</option>
              <option value="id_desc">ID décroissant</option>
            </Select>
          </div>
          <div className="rounded-xl border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700">
            <p>Visibles: <span className="font-semibold">{filteredObjects.length}</span></p>
            <p>Acquittés visibles: <span className="font-semibold">{acknowledgedCount}</span></p>
            <p>Chargés: <span className="font-semibold">{objectsMeta.count}</span></p>
          </div>
        </div>

        {objectsMeta.warning ? (
          <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
            {objectsMeta.warning}
          </div>
        ) : null}

        {objectsMeta.capped ? (
          <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
            Cap actif: {objectsMeta.max_records} objets max chargés.
          </div>
        ) : null}

        {objectsError ? (
          <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
            {objectsError}
          </div>
        ) : null}

        <div className="mt-3 oq-table-wrap">
          <table className="min-w-[940px] w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-slate-500">
                <th className="py-2">Classe</th>
                <th className="py-2">ID</th>
                <th className="py-2">Nom</th>
                <th className="py-2">État</th>
                <th className="py-2">Lien</th>
                <th className="py-2">Action</th>
              </tr>
            </thead>
            <tbody>
              {objectsLoading ? (
                <tr>
                  <td colSpan={6} className="py-3 text-slate-500">Chargement des objets impactés...</td>
                </tr>
              ) : filteredObjects.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-3 text-slate-500">Aucun objet correspondant.</td>
                </tr>
              ) : filteredObjects.map((object) => {
                const key = `${object.itop_class}|${object.itop_id}`;
                const isAcked = Boolean(ackMap[key]);
                const isBusy = busyObjectKey === key;

                return (
                  <tr key={key} className="border-b border-slate-100">
                    <td className="py-2">{object.itop_class}</td>
                    <td className="py-2">{object.itop_id}</td>
                    <td className="py-2">{object.name || 'N/D'}</td>
                    <td className="py-2">
                      {isAcked ? <Badge tone="info">acquitté</Badge> : <Badge tone="warn">actif</Badge>}
                    </td>
                    <td className="py-2">
                      {object.link ? (
                        <a href={object.link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-teal-700 hover:underline">
                          ouvrir iTop
                          <ArrowUpRight className="h-3.5 w-3.5" />
                        </a>
                      ) : 'N/D'}
                    </td>
                    <td className="py-2">
                      <button
                        type="button"
                        className={`inline-flex items-center gap-1 hover:underline ${isAcked ? 'text-rose-700' : 'text-amber-700'} ${isBusy ? 'opacity-60' : ''}`}
                        disabled={isBusy}
                        onClick={() => toggleObjectAcknowledgement(object)}
                      >
                        {isAcked ? <CircleMinus className="h-3.5 w-3.5" /> : <CircleCheckBig className="h-3.5 w-3.5" />}
                        {isAcked ? 'désacquitter objet' : 'acquitter objet'}
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>

      <Card className="oq-appear mt-5">
        <CardTitle className="inline-flex items-center gap-2">
          <ListChecks className="h-5 w-5 text-slate-600" />
          Échantillons du scan ({issue.samples?.length ?? 0})
        </CardTitle>
        <CardDescription>
          Échantillons stockés lors du scan (vue de secours/debug).
        </CardDescription>
        <div className="mt-3 oq-table-wrap">
          <table className="min-w-[760px] w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-slate-500">
                <th className="py-2">Classe</th>
                <th className="py-2">ID</th>
                <th className="py-2">Nom</th>
                <th className="py-2">Lien</th>
              </tr>
            </thead>
            <tbody>
              {(issue.samples ?? []).length === 0 ? (
                <tr>
                  <td colSpan={4} className="py-3 text-slate-500">Aucun échantillon stocké.</td>
                </tr>
              ) : (issue.samples ?? []).map((sample) => (
                <tr key={sample.id} className="border-b border-slate-100">
                  <td className="py-2">{sample.itop_class}</td>
                  <td className="py-2">{sample.itop_id}</td>
                  <td className="py-2">{sample.name}</td>
                  <td className="py-2">
                    {sample.link ? (
                      <a href={sample.link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-teal-700 hover:underline">
                        ouvrir iTop
                        <ArrowUpRight className="h-3.5 w-3.5" />
                      </a>
                    ) : 'N/D'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </AppLayout>
  );
}

