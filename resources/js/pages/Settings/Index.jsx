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
  const { prefs, setTheme, setLayout, setDensity, setSidebarCollapsed } = useUiPrefs();
  const [activeReadmeId, setActiveReadmeId] = useState(readmes[0]?.id ?? null);

  const activeReadme = useMemo(() => {
    if (readmes.length === 0) return null;
    return readmes.find((item) => item.id === activeReadmeId) ?? readmes[0];
  }, [readmes, activeReadmeId]);

  const themeLabelMap = {
    oqlook: t('settings.themeOqlook'),
    slate: t('settings.themeSlate'),
    sand: t('settings.themeSand'),
    volcano: t('settings.themeVolcano'),
    ocean: t('settings.themeOcean'),
    dark: t('settings.themeDark'),
    midnight: t('settings.themeMidnight'),
    graphite: t('settings.themeGraphite'),
  };

  const layoutLabelMap = {
    full: t('settings.layoutFull'),
    boxed: t('settings.layoutBoxed'),
  };

  const densityLabelMap = {
    comfortable: t('settings.densityComfortable'),
    compact: t('settings.densityCompact'),
  };

  return (
    <AppLayout
      title={t('pages.settings.title')}
      subtitle={t('pages.settings.subtitle')}
      fullWidth
    >
      <div className="grid gap-4 md:gap-5 xl:grid-cols-[340px_minmax(0,1fr)]">
        <div className="space-y-4 xl:sticky xl:top-24 xl:space-y-5">
          <Card className="oq-appear h-fit p-4 sm:p-5">
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

          <Card className="oq-appear h-fit p-4 sm:p-5">
            <CardTitle className="inline-flex items-center gap-2">
              <LayoutPanelTop className="h-5 w-5 text-teal-700" />
              {t('settings.uiCardTitle')}
            </CardTitle>
            <CardDescription>{t('settings.uiCardDescription')}</CardDescription>
            <div className="mt-3 flex flex-wrap gap-1.5">
              <Badge tone="info" className="normal-case">{themeLabelMap[prefs.theme] ?? prefs.theme}</Badge>
              <Badge tone="slate" className="normal-case">{layoutLabelMap[prefs.layout] ?? prefs.layout}</Badge>
              <Badge tone="slate" className="normal-case">{densityLabelMap[prefs.density] ?? prefs.density}</Badge>
            </div>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
              <div>
                <label className="text-xs font-semibold text-slate-600">{t('settings.themeLabel')}</label>
                <Select className="mt-1" value={prefs.theme} onChange={(event) => setTheme(event.target.value)}>
                  <option value="oqlook">{t('settings.themeOqlook')}</option>
                  <option value="slate">{t('settings.themeSlate')}</option>
                  <option value="sand">{t('settings.themeSand')}</option>
                  <option value="volcano">{t('settings.themeVolcano')}</option>
                  <option value="ocean">{t('settings.themeOcean')}</option>
                  <option value="dark">{t('settings.themeDark')}</option>
                  <option value="midnight">{t('settings.themeMidnight')}</option>
                  <option value="graphite">{t('settings.themeGraphite')}</option>
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
              <div className="sm:col-span-2 xl:col-span-1">
                <label className="text-xs font-semibold text-slate-600">{t('settings.sidebarLabel')}</label>
                <Select
                  className="mt-1"
                  value={prefs.sidebarCollapsed ? 'collapsed' : 'expanded'}
                  onChange={(event) => setSidebarCollapsed(event.target.value === 'collapsed')}
                >
                  <option value="expanded">{t('settings.sidebarExpanded')}</option>
                  <option value="collapsed">{t('settings.sidebarCollapsed')}</option>
                </Select>
              </div>
            </div>
          </Card>
        </div>

        <Card className="oq-appear p-4 sm:p-5">
          <CardTitle className="inline-flex items-center gap-2">
            <BookOpenText className="h-5 w-5 text-teal-700" />
            {t('settings.readmeCardTitle')}
          </CardTitle>
          <CardDescription>{t('settings.readmeCardDescription')}</CardDescription>

          {readmes.length === 0 ? (
            <p className="mt-4 text-sm text-slate-500">{t('settings.readmeEmpty')}</p>
          ) : (
            <>
              <div className="mt-3 -mx-1 flex snap-x snap-mandatory gap-2 overflow-x-auto px-1 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0">
                {readmes.map((item) => (
                  <button
                    key={item.id}
                    type="button"
                    onClick={() => setActiveReadmeId(item.id)}
                    className={`shrink-0 snap-start whitespace-nowrap rounded-xl border px-3 py-2 text-sm transition ${
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
                <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:p-4">
                  <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <p className="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                      <ScrollText className="h-4 w-4 text-slate-500" />
                      {activeReadme.title}
                    </p>
                    <div className="grid gap-1 text-[11px] text-slate-500 sm:flex sm:flex-wrap sm:items-center sm:gap-2 sm:text-xs">
                      <Badge tone="slate">{activeReadme.relative_path}</Badge>
                      <span>{t('settings.readmeUpdatedAt')}: {activeReadme.updated_at}</span>
                      <span>{t('settings.readmeSize')}: {formatSize(activeReadme.size_bytes)}</span>
                    </div>
                  </div>
                  {activeReadme.content_html ? (
                    <div
                      className="oq-readme-html mt-3 max-h-[52vh] overflow-auto rounded-xl border border-slate-200 bg-white p-3 text-[11px] leading-5 text-slate-800 sm:max-h-[68vh] sm:text-xs"
                      dangerouslySetInnerHTML={{ __html: activeReadme.content_html }}
                    />
                  ) : (
                    <pre className="mt-3 max-h-[52vh] overflow-auto whitespace-pre-wrap break-words rounded-xl border border-slate-200 bg-white p-3 text-[11px] leading-5 text-slate-800 sm:max-h-[68vh] sm:text-xs sm:whitespace-pre">
                      {activeReadme.content}
                    </pre>
                  )}
                </div>
              ) : null}
            </>
          )}
        </Card>
      </div>
    </AppLayout>
  );
}
