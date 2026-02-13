import React, { useMemo, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import { Card, CardDescription, CardTitle } from '../../components/ui/card';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input, Select } from '../../components/ui/input';
import { useForm } from '@inertiajs/react';
import { appPath } from '../../lib/app-path';
import { useI18n } from '../../i18n';
import {
  Database,
  KeyRound,
  Link2,
  Network,
  PencilLine,
  PlugZap,
  Server,
} from 'lucide-react';

const DEFAULT_FORM_DATA = {
  name: 'iTop Production',
  itop_url: '',
  auth_mode: 'token',
  username: '',
  password: '',
  auth_token: '',
  connector_url: '',
  connector_bearer_token: '',
  fallback_classes: '',
  mandatory_fields: 'name',
};

function normalizeTestError(error, fallbackMessage) {
  return error?.response?.data ?? { ok: false, error: fallbackMessage };
}

function resultTone(ok) {
  return ok ? 'info' : 'crit';
}

export default function ConnectionsWizard({ connections = [] }) {
  const { t } = useI18n();
  const [editingConnectionId, setEditingConnectionId] = useState(null);
  const [connectionTests, setConnectionTests] = useState({});

  const form = useForm({ ...DEFAULT_FORM_DATA });

  const editingConnection = useMemo(() => {
    if (editingConnectionId === null) return null;
    return connections.find((connection) => String(connection.id) === String(editingConnectionId)) ?? null;
  }, [connections, editingConnectionId]);

  const resetToCreateMode = () => {
    setEditingConnectionId(null);
    form.setData({ ...DEFAULT_FORM_DATA });
    form.clearErrors();
  };

  const startEdit = (connection) => {
    const fallbackConfig = connection?.fallback_config_json ?? {};
    const fallbackClasses = Array.isArray(fallbackConfig?.classes) ? fallbackConfig.classes.join(',') : '';
    const mandatoryFields = Array.isArray(fallbackConfig?.mandatory_fields) && fallbackConfig.mandatory_fields.length > 0
      ? fallbackConfig.mandatory_fields.join(',')
      : 'name';

    setEditingConnectionId(connection.id);
    form.setData({
      name: connection.name ?? '',
      itop_url: connection.itop_url ?? '',
      auth_mode: connection.auth_mode ?? 'token',
      username: connection.username ?? '',
      password: '',
      auth_token: '',
      connector_url: connection.connector_url ?? '',
      connector_bearer_token: '',
      fallback_classes: fallbackClasses,
      mandatory_fields: mandatoryFields,
    });
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const submit = (event) => {
    event.preventDefault();

    const options = {
      preserveScroll: true,
      onSuccess: () => {
        resetToCreateMode();
      },
    };

    if (editingConnectionId !== null) {
      form.put(appPath(`connections/${editingConnectionId}`), options);
      return;
    }

    form.post(appPath('connections'), options);
  };

  const setConnectionTestState = (connectionId, testKey, payload) => {
    setConnectionTests((previous) => ({
      ...previous,
      [connectionId]: {
        ...(previous[connectionId] ?? {}),
        [testKey]: payload,
      },
    }));
  };

  const testItop = async (connection) => {
    setConnectionTestState(connection.id, 'itop', { pending: true });

    try {
      const response = await window.axios.post(appPath(`connections/${connection.id}/test-itop`));
      setConnectionTestState(connection.id, 'itop', response.data);
    } catch (error) {
      setConnectionTestState(connection.id, 'itop', normalizeTestError(error, 'Échec du test iTop'));
    }
  };

  const testConnector = async (connection) => {
    setConnectionTestState(connection.id, 'connector', { pending: true });

    try {
      const response = await window.axios.post(appPath(`connections/${connection.id}/test-connector`));
      setConnectionTestState(connection.id, 'connector', response.data);
    } catch (error) {
      setConnectionTestState(connection.id, 'connector', normalizeTestError(error, 'Échec du test connecteur'));
    }
  };

  return (
    <AppLayout
      title={t('pages.connections.title')}
      subtitle={t('pages.connections.subtitle')}
      fullWidth
    >
      <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_400px]">
        <Card className="oq-appear">
          <CardTitle className="inline-flex items-center gap-2">
            {editingConnection ? <PencilLine className="h-5 w-5 text-teal-700" /> : <Server className="h-5 w-5 text-teal-700" />}
            {editingConnection ? `Modifier la connexion #${editingConnection.id}` : 'Nouvelle connexion'}
          </CardTitle>
          <CardDescription>
            Prise en charge Basic Auth et jeton d'authentification + connecteur métamodèle optionnel
          </CardDescription>
          {Object.keys(form.errors).length > 0 ? (
            <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
              {Object.entries(form.errors).map(([field, message]) => (
                <p key={field}>
                  {field}: {message}
                </p>
              ))}
            </div>
          ) : null}
          <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={submit}>
            <div className="md:col-span-2">
              <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
                <Database className="h-3.5 w-3.5" />
                Nom
              </label>
              <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
            </div>
            <div className="md:col-span-2">
              <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
                <Network className="h-3.5 w-3.5" />
                URL iTop
              </label>
              <Input
                placeholder="https://itop.example.com/webservices/rest.php?version=1.3"
                value={form.data.itop_url}
                onChange={(e) => form.setData('itop_url', e.target.value)}
              />
            </div>
            <div>
              <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
                <KeyRound className="h-3.5 w-3.5" />
                Mode d'authentification
              </label>
              <Select value={form.data.auth_mode} onChange={(e) => form.setData('auth_mode', e.target.value)}>
                <option value="basic">basic</option>
                <option value="token">token</option>
              </Select>
            </div>
            {form.data.auth_mode === 'basic' ? (
              <>
                <div>
                  <label className="mb-1 block text-xs font-semibold text-slate-600">Nom d'utilisateur</label>
                  <Input value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} />
                </div>
                <div className="md:col-span-2">
                  <label className="mb-1 block text-xs font-semibold text-slate-600">
                    Mot de passe {editingConnection ? '(laisser vide pour conserver)' : ''}
                  </label>
                  <Input type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </div>
              </>
            ) : (
              <div className="md:col-span-2">
                <label className="mb-1 block text-xs font-semibold text-slate-600">
                  Jeton d'authentification {editingConnection ? '(laisser vide pour conserver)' : ''}
                </label>
                <Input type="password" value={form.data.auth_token} onChange={(e) => form.setData('auth_token', e.target.value)} />
              </div>
            )}

            <div className="md:col-span-2 mt-2 border-t border-slate-200 pt-3">
              <p className="inline-flex items-center gap-2 text-sm font-semibold">
                <PlugZap className="h-4 w-4 text-teal-700" />
                Connecteur métamodèle (recommandé)
              </p>
            </div>
            <div>
              <label className="mb-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-600">
                <Link2 className="h-3.5 w-3.5" />
                URL du connecteur
              </label>
              <Input
                placeholder="https://connector.example.com"
                value={form.data.connector_url}
                onChange={(e) => form.setData('connector_url', e.target.value)}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">
                Jeton bearer du connecteur {editingConnection ? '(laisser vide pour conserver)' : ''}
              </label>
              <Input
                type="password"
                value={form.data.connector_bearer_token}
                onChange={(e) => form.setData('connector_bearer_token', e.target.value)}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Classes de secours (CSV)</label>
              <Input
                placeholder="Server,Person"
                value={form.data.fallback_classes}
                onChange={(e) => form.setData('fallback_classes', e.target.value)}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Champs obligatoires (indices CSV)</label>
              <Input
                placeholder="name,status"
                value={form.data.mandatory_fields}
                onChange={(e) => form.setData('mandatory_fields', e.target.value)}
              />
            </div>

            <div className="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={form.processing}>
                  {editingConnection ? 'Mettre à jour' : 'Enregistrer'}
                </Button>
                {editingConnection ? (
                  <Button type="button" variant="ghost" onClick={resetToCreateMode} disabled={form.processing}>
                    Annuler l'édition
                  </Button>
                ) : null}
              </div>
            </div>
          </form>
        </Card>

        <Card className="oq-appear h-fit xl:sticky xl:top-24">
          <CardTitle className="inline-flex items-center gap-2">
            <Server className="h-5 w-5 text-teal-700" />
            Connexions existantes
          </CardTitle>
          <div className="mt-3 space-y-3">
            {connections.length === 0 ? <p className="text-sm text-slate-500">Aucune connexion.</p> : null}
            {connections.map((connection) => {
              const tests = connectionTests[connection.id] ?? {};
              const itop = tests.itop;
              const connector = tests.connector;

              return (
                <div key={connection.id} className="rounded-xl border border-slate-200 bg-white/70 p-3">
                  <p className="inline-flex items-center gap-2 font-semibold">
                    <Database className="h-4 w-4 text-slate-400" />
                    {connection.name}
                  </p>
                  <p className="truncate text-xs text-slate-500">{connection.itop_url}</p>
                  <p className="mt-1 text-xs text-slate-500">mode : {connection.auth_mode}</p>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    {connection.has_password ? <Badge tone="info">mot de passe ok</Badge> : null}
                    {connection.has_token ? <Badge tone="info">jeton ok</Badge> : null}
                    {connection.has_connector_bearer ? <Badge tone="info">jeton connecteur ok</Badge> : null}
                  </div>

                  <div className="mt-2 flex flex-wrap gap-2">
                    <Button type="button" variant="ghost" onClick={() => startEdit(connection)}>
                      Modifier
                    </Button>
                    <Button type="button" variant="outline" onClick={() => testItop(connection)}>
                      Test iTop
                    </Button>
                    <Button type="button" variant="outline" onClick={() => testConnector(connection)}>
                      Tester le connecteur
                    </Button>
                  </div>

                  {(itop || connector) ? (
                    <div className="mt-2 space-y-1 text-xs">
                      {itop ? (
                        <div className="flex items-center gap-2">
                          <span className="font-semibold">iTop:</span>
                          {itop.pending ? <span className="text-slate-500">test en cours...</span> : null}
                          {!itop.pending ? (
                            <>
                              <Badge tone={resultTone(Boolean(itop.ok))}>{itop.ok ? 'OK' : 'KO'}</Badge>
                              {itop.latency_ms ? <span className="text-slate-500">{itop.latency_ms} ms</span> : null}
                              {itop.error ? <span className="text-rose-700">{itop.error}</span> : null}
                            </>
                          ) : null}
                        </div>
                      ) : null}
                      {itop && !itop.pending ? (
                        <details className="rounded-lg border border-slate-200 bg-slate-50 p-2">
                          <summary className="cursor-pointer text-[11px] font-semibold text-slate-600">
                            Détails JSON iTop
                          </summary>
                          <pre className="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-all text-[10px] text-slate-700">
                            {JSON.stringify(itop, null, 2)}
                          </pre>
                        </details>
                      ) : null}

                      {connector ? (
                        <div className="flex items-center gap-2">
                          <span className="font-semibold">Connecteur :</span>
                          {connector.pending ? <span className="text-slate-500">test en cours...</span> : null}
                          {!connector.pending ? (
                            <>
                              <Badge tone={resultTone(Boolean(connector.ok))}>{connector.ok ? 'OK' : 'KO'}</Badge>
                              {connector.error ? <span className="text-rose-700">{connector.error}</span> : null}
                            </>
                          ) : null}
                        </div>
                      ) : null}
                      {connector && !connector.pending ? (
                        <details className="rounded-lg border border-slate-200 bg-slate-50 p-2">
                          <summary className="cursor-pointer text-[11px] font-semibold text-slate-600">
                            Détails JSON connecteur
                          </summary>
                          <pre className="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-all text-[10px] text-slate-700">
                            {JSON.stringify(connector, null, 2)}
                          </pre>
                        </details>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              );
            })}
          </div>
        </Card>
      </div>
    </AppLayout>
  );
}

