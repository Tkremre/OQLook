import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Activity, Database, LayoutDashboard, Settings, ShieldCheck, TriangleAlert } from 'lucide-react';
import { appPath } from '../lib/app-path';
import { useI18n } from '../i18n';
import { useUiPrefs } from '../ui-prefs';

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

export default function AppLayout({ children, title, subtitle, fullWidth = true }) {
  const page = usePage();
  const { flash } = page.props;
  const { t } = useI18n();
  const { prefs } = useUiPrefs();
  const currentRelativePath = extractRelativePath(page.url, appPath(''));
  const useFullLayout = fullWidth && prefs.layout === 'full';
  const shellClass = useFullLayout
    ? 'mx-auto w-full max-w-[1720px] px-4 pb-8 pt-6 sm:px-6 lg:px-8'
    : 'mx-auto w-full max-w-7xl px-4 pb-8 pt-6 sm:px-6 lg:px-8';
  const topbarInnerClass = useFullLayout
    ? 'oq-topbar-inner mx-auto flex w-full max-w-[1720px] items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8'
    : 'oq-topbar-inner mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8';

  return (
    <div className="oq-shell">
      <header className="oq-topbar relative sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
        <div className={topbarInnerClass}>
          <div className="flex min-w-0 items-center gap-3">
            <div className="rounded-2xl bg-teal-700 p-2.5 text-white shadow-sm shadow-teal-900/20">
              <ShieldCheck className="h-5 w-5" />
            </div>
            <div className="min-w-0">
              <p className="truncate text-xl font-bold tracking-tight text-slate-900">{t('app.name')}</p>
              <p className="truncate text-xs text-slate-500">{t('app.tagline')}</p>
            </div>
          </div>
          <nav className="flex items-center gap-1 text-sm font-medium">
            {NAV_ITEMS.map((item) => {
              const active = isNavItemActive(currentRelativePath, item.matches);
              const Icon = item.icon;

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={`oq-nav-link inline-flex items-center gap-2 rounded-xl px-3 py-2 transition ${
                    active
                      ? 'bg-teal-50 text-teal-800 ring-1 ring-teal-100'
                      : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'
                  }`}
                >
                  <Icon className="h-4 w-4" />
                  <span className="hidden sm:inline">{t(item.labelKey)}</span>
                </Link>
              );
            })}
          </nav>
        </div>
      </header>

      <main className={shellClass}>
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
  );
}
