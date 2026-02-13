import './bootstrap';
import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';
import { I18nProvider } from './i18n';
import { UiPrefsProvider } from './ui-prefs';

const appName = import.meta.env.VITE_APP_NAME || 'OQLook';

createInertiaApp({
  title: (title) => {
    const normalized = String(title ?? '').trim();
    if (!normalized) return appName;
    if (normalized === appName || normalized.endsWith(` - ${appName}`)) return normalized;
    return `${normalized} - ${appName}`;
  },
  resolve: (name) =>
    resolvePageComponent(`./pages/${name}.jsx`, import.meta.glob('./pages/**/*.jsx')),
  setup({ el, App, props }) {
    const initialLocale = props?.initialPage?.props?.locale ?? 'fr';
    createRoot(el).render(
      <UiPrefsProvider>
        <I18nProvider initialLocale={initialLocale}>
          <App {...props} />
        </I18nProvider>
      </UiPrefsProvider>,
    );
  },
});

if (typeof window !== 'undefined' && !window.__oqlookProgressBound) {
  NProgress.configure({ showSpinner: false });

  const clamp = (value) => Math.max(0, Math.min(1, value));

  router.on('start', () => {
    NProgress.start();
  });

  router.on('progress', (event) => {
    const percentage = event?.detail?.progress?.percentage;
    if (typeof percentage === 'number' && Number.isFinite(percentage)) {
      NProgress.set(clamp(percentage / 100));
    }
  });

  router.on('finish', () => {
    NProgress.done();
  });

  window.__oqlookProgressBound = true;
}
