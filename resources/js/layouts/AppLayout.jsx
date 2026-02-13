import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
  Activity,
  ChevronLeft,
  ChevronRight,
  Database,
  ExternalLink,
  Github,
  House,
  Info,
  LayoutDashboard,
  Settings,
  TriangleAlert,
  X,
} from 'lucide-react';
import { appPath } from '../lib/app-path';
import { useI18n } from '../i18n';
import { useUiPrefs } from '../ui-prefs';

const NAV_HISTORY_KEY = 'oqlike.nav_history';
const NAV_HISTORY_MAX = 16;
const APP_VERSION = 'alpha';
const APP_AUTHOR = 'Emre Toker';
const APP_GITHUB_URL = 'https://github.com/Tkremre/OQLook';
const APP_GITHUB_ISSUES_URL = 'https://github.com/Tkremre/OQLook/issues';
const APP_LICENSE_NAME = 'MIT';
const APP_LICENSE_URL = 'https://github.com/Tkremre/OQLook/blob/main/LICENSE';
const APP_EDITOR_URL = 'https://github.com/Tkremre';
const APP_CONTRIBUTORS_URL = 'https://github.com/Tkremre/OQLook/graphs/contributors';
const APP_OPENAI_URL = 'https://openai.com';
const APP_TECH_STACK = [
  'Laravel',
  'Inertia.js',
  'React',
  'Tailwind CSS',
  'PostgreSQL',
  'iTop / OQL',
  'OpenAI Codex (GPT-5)',
];

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
  const [aboutOpen, setAboutOpen] = useState(false);

  useEffect(() => {
    if (typeof window === 'undefined') return undefined;

    const onResize = () => {
      setHistoryLimit(historyLimitFromWidth(window.innerWidth));
    };

    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

  useEffect(() => {
    if (!aboutOpen) return undefined;

    const onEscape = (event) => {
      if (event.key === 'Escape') {
        setAboutOpen(false);
      }
    };

    window.addEventListener('keydown', onEscape);
    return () => window.removeEventListener('keydown', onEscape);
  }, [aboutOpen]);

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
  const desktopPrimaryNavItems = useMemo(
    () => NAV_ITEMS.filter((item) => item.labelKey !== 'nav.settings'),
    [],
  );

  const dashboardHref = NAV_ITEMS[0]?.href ?? appPath('');

  const useFullLayout = fullWidth && prefs.layout === 'full';
  const shellClass = useFullLayout
    ? 'mx-auto w-full max-w-[1720px] px-4 pb-8 pt-5 sm:px-6 lg:px-8'
    : 'mx-auto w-full max-w-7xl px-4 pb-8 pt-5 sm:px-6 lg:px-8';

  const sidebarWidthClass = sidebarCollapsed ? 'lg:w-[92px]' : 'lg:w-[250px]';

  return (
    <div className="oq-shell lg:flex lg:h-[100dvh] lg:overflow-hidden">
      <Head title={title || ''} />

      <aside className={`oq-sidebar hidden lg:sticky lg:top-0 lg:z-40 lg:flex lg:h-[100dvh] lg:min-h-[100dvh] lg:max-h-[100dvh] lg:shrink-0 lg:flex-col lg:overflow-hidden lg:border-r lg:border-slate-200/80 lg:bg-white/90 lg:backdrop-blur-xl ${sidebarWidthClass}`}>
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
          {desktopPrimaryNavItems.map((item) => {
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

        <div className="border-t border-slate-200/80 p-2">
          <Link
            href={appPath('settings')}
            title={t('nav.settings')}
            aria-label={t('nav.settings')}
            className={`oq-nav-link inline-flex w-full items-center rounded-xl transition ${
              sidebarCollapsed ? 'justify-center gap-0 px-2 py-3.5' : 'gap-2 px-3 py-2.5'
            } ${
              isNavItemActive(currentRelativePath, ['settings'])
                ? 'bg-teal-50 text-teal-800 ring-1 ring-teal-100'
                : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'
            }`}
          >
            <Settings className={`shrink-0 ${sidebarCollapsed ? 'h-6 w-6' : 'h-[18px] w-[18px]'}`} />
            {!sidebarCollapsed ? <span className="font-medium">{t('nav.settings')}</span> : null}
          </Link>

          <button
            type="button"
            onClick={() => setAboutOpen(true)}
            title={t('about.title')}
            aria-label={t('about.title')}
            className={`oq-nav-link inline-flex w-full items-center rounded-xl transition ${
              sidebarCollapsed ? 'justify-center gap-0 px-2 py-3.5' : 'gap-2 px-3 py-2.5'
            } text-slate-700 hover:bg-slate-100 hover:text-slate-900`}
          >
            <Info className={`shrink-0 ${sidebarCollapsed ? 'h-6 w-6' : 'h-[18px] w-[18px]'}`} />
            {!sidebarCollapsed ? <span className="font-medium">{t('about.title')}</span> : null}
          </button>
        </div>
      </aside>

      <div className="min-w-0 flex-1 lg:h-[100dvh] lg:overflow-y-auto">
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

      {aboutOpen ? (
        <div
          className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-label={t('about.title')}
          onClick={() => setAboutOpen(false)}
        >
          <div
            className="relative w-full max-w-2xl rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xl"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="rounded-2xl border border-slate-200 bg-gradient-to-r from-teal-50 via-white to-sky-50 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                  <img
                    src={appPath('brand/oqlook-mark.svg')}
                    alt="OQLook"
                    className="h-12 w-12 shrink-0"
                  />
                  <div>
                    <h2 className="text-xl font-bold text-slate-900">{t('about.title')}</h2>
                  </div>
                </div>
              </div>
            </div>

            <button
              type="button"
              className="absolute right-7 top-7 inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-900"
              onClick={() => setAboutOpen(false)}
              aria-label={t('about.close')}
              title={t('about.close')}
            >
              <X className="h-4 w-4" />
            </button>

            <div className="mt-4 grid gap-4 md:grid-cols-2">
              <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('about.applicationInfo')}</p>
                <p className="mt-2">
                  <span className="font-semibold text-slate-700">{t('about.version')}</span>: {APP_VERSION}
                </p>
                <p>
                  <span className="font-semibold text-slate-700">{t('about.author')}</span>: {APP_AUTHOR}
                </p>
                <p>
                  <span className="font-semibold text-slate-700">{t('about.licenseType')}</span>: {APP_LICENSE_NAME}
                </p>
              </div>

              <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('about.editors')}</p>
                <div className="mt-2 flex flex-col gap-2">
                  <a
                    href={APP_EDITOR_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 text-slate-700 hover:text-slate-900 hover:underline"
                  >
                    {APP_AUTHOR}
                    <ExternalLink className="h-3.5 w-3.5" />
                  </a>
                  <a
                    href={APP_CONTRIBUTORS_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 text-slate-700 hover:text-slate-900 hover:underline"
                  >
                    {t('about.contributors')}
                    <ExternalLink className="h-3.5 w-3.5" />
                  </a>
                  <a
                    href={APP_OPENAI_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 text-slate-700 hover:text-slate-900 hover:underline"
                  >
                    OpenAI Codex
                    <ExternalLink className="h-3.5 w-3.5" />
                  </a>
                </div>
              </div>
            </div>

            <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('about.technologies')}</p>
              <div className="mt-2 flex flex-wrap gap-2">
                {APP_TECH_STACK.map((tech) => (
                  <span
                    key={tech}
                    className="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700"
                  >
                    {tech}
                  </span>
                ))}
              </div>
            </div>

            <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('about.license')}</p>
              <p className="mt-2 text-slate-700">{t('about.licenseSummary')}</p>
              <a
                href={APP_LICENSE_URL}
                target="_blank"
                rel="noopener noreferrer"
                className="mt-2 inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              >
                {t('about.viewLicense')}
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
            </div>

            <div className="mt-4 flex flex-wrap gap-2">
              <a
                href={APP_GITHUB_URL}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              >
                <Github className="h-4 w-4" />
                {t('about.githubRepo')}
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
              <a
                href={APP_GITHUB_ISSUES_URL}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              >
                <Github className="h-4 w-4" />
                {t('about.githubIssues')}
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
