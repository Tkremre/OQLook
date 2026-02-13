import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
  Activity,
  ChevronLeft,
  ChevronRight,
  Database,
  House,
  LayoutDashboard,
  Settings,
  TriangleAlert,
} from 'lucide-react';
import { appPath } from '../lib/app-path';
import { useI18n } from '../i18n';
import { useUiPrefs } from '../ui-prefs';

const NAV_HISTORY_KEY = 'oqlike.nav_history';
const NAV_HISTORY_MAX = 16;

const NAV_ITEMS = [
  { href: appPath(''), labelKey: 'nav.dashboard', icon: LayoutDashboard, matches: [''] },
  { href: appPath('connections/wizard'), labelKey: 'nav.connections', icon: Database, matches: ['connections'] },
  { href: appPath('issues'), labelKey: 'nav.issues', icon: TriangleAlert, matches: ['issues', 'issue'] },
  { href: appPath('settings'), labelKey: 'nav.settings', icon: Settings, matches: ['settings'] },
];

function normalizePath(pathname = '') {
  const withoutQuery = String(pathname).split('?')[0] ?? '';
  return withoutQuery.replace(/\/+$/, '');
}

function extractRelativePath(currentUrl, basePath) {
  const normalizedCurrent = normalizePath(currentUrl);
  const normalizedBase = normalizePath(basePath);

  if (!normalizedBase || normalizedBase === '/') {
    return normalizedCurrent.startsWith('/') ? normalizedCurrent.slice(1) : normalizedCurrent;
  }

  if (normalizedCurrent.startsWith(normalizedBase)) {
    return normalizedCurrent.slice(normalizedBase.length).replace(/^\/+/, '');
  }

  return normalizedCurrent.replace(/^\/+/, '');
}

function isNavItemActive(relativePath, matches) {
  const normalized = Array.isArray(matches) ? matches : [matches];
  if (normalized.includes('')) return relativePath === '' || relativePath === '/';

  return normalized.some((itemMatch) => (
    relativePath === itemMatch || relativePath.startsWith(`${itemMatch}/`)
  ));
}

function historyLimitFromWidth(width) {
  if (width >= 1700) return 7;
  if (width >= 1450) return 6;
  if (width >= 1250) return 5;
  if (width >= 1024) return 4;
  if (width >= 768) return 3;
  return 2;
}

function loadNavHistory() {
  if (typeof window === 'undefined') {
    return [];
  }

  try {
    const raw = window.localStorage.getItem(NAV_HISTORY_KEY);
    if (!raw) return [];

    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];

    return parsed
      .filter((entry) => entry && typeof entry === 'object')
      .map((entry) => ({
        label: String(entry.label ?? '').trim(),
        url: String(entry.url ?? '').trim(),
        at: Number(entry.at ?? 0),
      }))
      .filter((entry) => entry.label !== '' && entry.url !== '')
      .slice(-NAV_HISTORY_MAX);
  } catch {
    return [];
  }
}

export default function AppLayout({ children, title, subtitle, fullWidth = true }) {
  const page = usePage();
  const { flash } = page.props;
  const { t } = useI18n();
  const { prefs, toggleSidebarCollapsed } = useUiPrefs();

  const currentRelativePath = extractRelativePath(page.url, appPath(''));
  const activeNavItem = NAV_ITEMS.find((item) => isNavItemActive(currentRelativePath, item.matches)) ?? NAV_ITEMS[0];
  const sectionLabel = activeNavItem ? t(activeNavItem.labelKey) : t('nav.dashboard');
  const normalizedTitle = String(title ?? '').trim();
  const breadcrumbLabel = normalizedTitle || sectionLabel;
  const sidebarCollapsed = Boolean(prefs.sidebarCollapsed);

  const [navHistory, setNavHistory] = useState(() => loadNavHistory());
  const [historyLimit, setHistoryLimit] = useState(() => (
    typeof window === 'undefined' ? 4 : historyLimitFromWidth(window.innerWidth)
  ));

  useEffect(() => {
    if (typeof window === 'undefined') return undefined;

    const onResize = () => {
      setHistoryLimit(historyLimitFromWidth(window.innerWidth));
    };

    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

  const currentPageUrl = appPath(currentRelativePath || '');

  useEffect(() => {
    if (!currentPageUrl || !breadcrumbLabel) return;

    setNavHistory((previous) => {
      const sanitizedPrevious = Array.isArray(previous) ? previous : [];
      const withoutCurrent = sanitizedPrevious.filter((entry) => entry.url !== currentPageUrl);
      const next = [...withoutCurrent, { label: breadcrumbLabel, url: currentPageUrl, at: Date.now() }].slice(-NAV_HISTORY_MAX);

      if (typeof window !== 'undefined') {
        window.localStorage.setItem(NAV_HISTORY_KEY, JSON.stringify(next));
      }

      return next;
    });
  }, [breadcrumbLabel, currentPageUrl]);

  const visibleHistory = useMemo(() => navHistory.slice(-historyLimit), [navHistory, historyLimit]);

  const dashboardHref = NAV_ITEMS[0]?.href ?? appPath('');

  const useFullLayout = fullWidth && prefs.layout === 'full';
  const shellClass = useFullLayout
    ? 'mx-auto w-full max-w-[1720px] px-4 pb-8 pt-5 sm:px-6 lg:px-8'
    : 'mx-auto w-full max-w-7xl px-4 pb-8 pt-5 sm:px-6 lg:px-8';

  const shellGridClass = sidebarCollapsed
    ? 'lg:grid-cols-[92px_minmax(0,1fr)]'
    : 'lg:grid-cols-[250px_minmax(0,1fr)]';

  return (
    <div className={`oq-shell lg:grid lg:min-h-screen ${shellGridClass}`}>
      <Head title={title || ''} />

      <aside className="oq-sidebar hidden lg:sticky lg:top-0 lg:z-50 lg:flex lg:h-[100dvh] lg:min-h-[100dvh] lg:max-h-[100dvh] lg:flex-col lg:overflow-hidden lg:border-r lg:border-slate-200/80 lg:bg-white/90 lg:backdrop-blur-xl">
        <div className="flex items-center justify-between gap-2 border-b border-slate-200/80 px-3 py-3">
          <Link href={appPath('')} className={`flex min-w-0 items-center ${sidebarCollapsed ? 'justify-center gap-0' : 'gap-3'}`}>
            <img
              src={appPath('brand/oqlook-mark.svg')}
              alt="OQLook"
              className="h-10 w-10 shrink-0"
            />
            {!sidebarCollapsed ? (
              <div className="min-w-0">
                <p className="truncate text-lg font-bold tracking-tight text-slate-900">{t('app.name')}</p>
                <p className="truncate text-[11px] text-slate-500">{t('app.tagline')}</p>
              </div>
            ) : null}
          </Link>

          <button
            type="button"
            onClick={toggleSidebarCollapsed}
            className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
            title={sidebarCollapsed ? t('settings.sidebarExpanded') : t('settings.sidebarCollapsed')}
            aria-label={sidebarCollapsed ? t('settings.sidebarExpanded') : t('settings.sidebarCollapsed')}
          >
            {sidebarCollapsed ? <ChevronRight className="h-5 w-5" /> : <ChevronLeft className="h-5 w-5" />}
          </button>
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto px-2 py-4 text-sm font-medium">
          {NAV_ITEMS.map((item) => {
            const active = isNavItemActive(currentRelativePath, item.matches);
            const Icon = item.icon;

            return (
              <Link
                key={item.href}
                href={item.href}
                title={t(item.labelKey)}
                className={`oq-nav-link inline-flex w-full items-center rounded-xl transition ${
                  sidebarCollapsed ? 'justify-center gap-0 px-2 py-3.5' : 'gap-2 px-3 py-2.5'
                } ${
                  active
                    ? 'bg-teal-50 text-teal-800 ring-1 ring-teal-100'
                    : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'
                }`}
              >
                <Icon className={`shrink-0 ${sidebarCollapsed ? 'h-6 w-6' : 'h-[18px] w-[18px]'}`} />
                {!sidebarCollapsed ? <span className="font-medium">{t(item.labelKey)}</span> : null}
              </Link>
            );
          })}
        </nav>
      </aside>

      <div className="min-w-0">
        <header className="oq-mobilebar sticky top-0 z-50 border-b border-slate-200/80 bg-white/95 px-4 py-3 shadow-sm shadow-slate-900/5 backdrop-blur-xl lg:hidden">
          <div className="flex min-w-0 items-center justify-between gap-3">
            <Link href={appPath('')} className="flex min-w-0 items-center gap-2.5">
              <img
                src={appPath('brand/oqlook-mark.svg')}
                alt="OQLook"
                className="h-9 w-9 shrink-0"
              />
              <div className="min-w-0">
                <p className="truncate text-base font-bold tracking-tight text-slate-900">{t('app.name')}</p>
                <p className="truncate text-[11px] text-slate-500">{sectionLabel}</p>
              </div>
            </Link>
          </div>

          <nav className="mt-3 grid grid-cols-4 gap-2 overflow-auto pb-1 text-sm font-medium">
            {NAV_ITEMS.map((item) => {
              const active = isNavItemActive(currentRelativePath, item.matches);
              const Icon = item.icon;

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  title={t(item.labelKey)}
                  aria-label={t(item.labelKey)}
                  className={`oq-nav-link inline-flex h-10 w-full items-center justify-center rounded-xl transition ${
                    active
                      ? 'bg-teal-50 text-teal-800 ring-1 ring-teal-100'
                      : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'
                  }`}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                </Link>
              );
            })}
          </nav>
        </header>

        <main className={shellClass}>
          <div className="oq-breadcrumb sticky top-3 z-30 mb-3 hidden flex-wrap items-center gap-1.5 rounded-xl px-3 py-2 text-xs text-slate-500 shadow-sm shadow-slate-900/5 backdrop-blur md:flex">
            <House className="h-3.5 w-3.5" />
            <Link href={dashboardHref} className="hover:text-slate-700 hover:underline">
              {t('nav.dashboard')}
            </Link>
            {visibleHistory
              .filter((entry, index) => !(index === 0 && entry.url === dashboardHref))
              .map((entry, index, array) => {
                const isLast = index === array.length - 1;
                const key = `${entry.url}-${entry.at}`;

                return (
                  <React.Fragment key={key}>
                    <ChevronRight className="h-3.5 w-3.5 text-slate-400" />
                    {isLast ? (
                      <span className="font-semibold text-slate-700">{entry.label}</span>
                    ) : (
                      <Link href={entry.url} className="hover:text-slate-700 hover:underline">
                        {entry.label}
                      </Link>
                    )}
                  </React.Fragment>
                );
              })}
          </div>

          {title ? (
            <section className="mb-5 rounded-2xl border border-slate-200/80 bg-white/85 px-4 py-4 shadow-sm shadow-slate-900/5 sm:px-5">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{title}</h1>
                  {subtitle ? <p className="mt-1 text-sm text-slate-500">{subtitle}</p> : null}
                </div>
                <Activity className="mt-1 h-5 w-5 shrink-0 text-slate-300" />
              </div>
            </section>
          ) : null}

          {flash?.status ? (
            <div className="oq-card oq-appear mb-4 border-l-4 border-l-teal-700 p-4 text-sm text-slate-700">
              {flash.status}
            </div>
          ) : null}

          {children}
        </main>
      </div>
    </div>
  );
}
