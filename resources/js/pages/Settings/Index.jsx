import React, { useMemo, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import { Card, CardDescription, CardTitle } from '../../components/ui/card';
import { Select } from '../../components/ui/input';
import { Badge } from '../../components/ui/badge';
import { BookOpenText, Languages, LayoutPanelTop, ScrollText } from 'lucide-react';
import { useI18n } from '../../i18n';
import { useUiPrefs } from '../../ui-prefs';

function formatSize(sizeBytes) {
  const size = Number(sizeBytes || 0);
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / (1024 * 1024)).toFixed(2)} MB`;
}

export default function SettingsIndex({ readmes = [] }) {
  const { locale, setLocale, t } = useI18n();
  const { prefs, setTheme, setLayout, setDensity } = useUiPrefs();
  const [activeReadmeId, setActiveReadmeId] = useState(readmes[0]?.id ?? null);

  const activeReadme = useMemo(() => {
    if (readmes.length === 0) return null;
    return readmes.find((item) => item.id === activeReadmeId) ?? readmes[0];
  }, [readmes, activeReadmeId]);

  return (
    <AppLayout
      title={t('pages.settings.title')}
      subtitle={t('pages.settings.subtitle')}
      fullWidth
    >
      <div className="grid gap-5 xl:grid-cols-[340px_minmax(0,1fr)]">
        <div className="space-y-5 xl:sticky xl:top-24">
          <Card className="oq-appear h-fit">
            <CardTitle className="inline-flex items-center gap-2">
              <Languages className="h-5 w-5 text-teal-700" />
              {t('settings.languageCardTitle')}
            </CardTitle>
            <CardDescription>{t('settings.languageCardDescription')}</CardDescription>
            <div className="mt-3 space-y-2">
              <label className="text-xs font-semibold text-slate-600">{t('settings.languageLabel')}</label>
              <Select value={locale} onChange={(event) => setLocale(event.target.value)}>
                <option value="fr">{t('settings.frLabel')}</option>
                <option value="en">{t('settings.enLabel')}</option>
              </Select>
              <p className="text-xs text-slate-500">{t('settings.languageHint')}</p>
            </div>
          </Card>

          <Card className="oq-appear h-fit">
            <CardTitle className="inline-flex items-center gap-2">
              <LayoutPanelTop className="h-5 w-5 text-teal-700" />
              {t('settings.uiCardTitle')}
            </CardTitle>
            <CardDescription>{t('settings.uiCardDescription')}</CardDescription>
            <div className="mt-3 space-y-3">
              <div>
                <label className="text-xs font-semibold text-slate-600">{t('settings.themeLabel')}</label>
                <Select className="mt-1" value={prefs.theme} onChange={(event) => setTheme(event.target.value)}>
                  <option value="oqlook">{t('settings.themeOqlook')}</option>
                  <option value="slate">{t('settings.themeSlate')}</option>
                  <option value="sand">{t('settings.themeSand')}</option>
                  <option value="dark">{t('settings.themeDark')}</option>
                </Select>
              </div>
              <div>
                <label className="text-xs font-semibold text-slate-600">{t('settings.layoutLabel')}</label>
                <Select className="mt-1" value={prefs.layout} onChange={(event) => setLayout(event.target.value)}>
                  <option value="full">{t('settings.layoutFull')}</option>
                  <option value="boxed">{t('settings.layoutBoxed')}</option>
                </Select>
              </div>
              <div>
                <label className="text-xs font-semibold text-slate-600">{t('settings.densityLabel')}</label>
                <Select className="mt-1" value={prefs.density} onChange={(event) => setDensity(event.target.value)}>
                  <option value="comfortable">{t('settings.densityComfortable')}</option>
                  <option value="compact">{t('settings.densityCompact')}</option>
                </Select>
              </div>
            </div>
          </Card>
        </div>

        <Card className="oq-appear">
          <CardTitle className="inline-flex items-center gap-2">
            <BookOpenText className="h-5 w-5 text-teal-700" />
            {t('settings.readmeCardTitle')}
          </CardTitle>
          <CardDescription>{t('settings.readmeCardDescription')}</CardDescription>

          {readmes.length === 0 ? (
            <p className="mt-4 text-sm text-slate-500">{t('settings.readmeEmpty')}</p>
          ) : (
            <>
              <div className="mt-3 flex flex-wrap gap-2">
                {readmes.map((item) => (
                  <button
                    key={item.id}
                    type="button"
                    onClick={() => setActiveReadmeId(item.id)}
                    className={`rounded-xl border px-3 py-1.5 text-sm transition ${
                      item.id === activeReadme?.id
                        ? 'border-teal-600 bg-teal-50 text-teal-800'
                        : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'
                    }`}
                  >
                    {item.title}
                  </button>
                ))}
              </div>

              {activeReadme ? (
                <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                      <ScrollText className="h-4 w-4 text-slate-500" />
                      {activeReadme.title}
                    </p>
                    <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <Badge tone="slate">{activeReadme.relative_path}</Badge>
                      <span>{t('settings.readmeUpdatedAt')}: {activeReadme.updated_at}</span>
                      <span>{t('settings.readmeSize')}: {formatSize(activeReadme.size_bytes)}</span>
                    </div>
                  </div>
                  <pre className="mt-3 max-h-[68vh] overflow-auto rounded-xl border border-slate-200 bg-white p-3 text-xs leading-5 text-slate-800">
                    {activeReadme.content}
                  </pre>
                </div>
              ) : null}
            </>
          )}
        </Card>
      </div>
    </AppLayout>
  );
}
