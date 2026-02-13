import './bootstrap';
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { InertiaProgress } from '@inertiajs/progress';
import { I18nProvider } from './i18n';
import { UiPrefsProvider } from './ui-prefs';

const appName = import.meta.env.VITE_APP_NAME || 'OQLook';

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
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

InertiaProgress.init({
  color: '#0f766e',
  showSpinner: false,
});
