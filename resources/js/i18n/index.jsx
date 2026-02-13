import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { SUPPORTED_LOCALES, TRANSLATIONS } from './translations';

const LOCALE_STORAGE_KEY = 'oqlike.locale';

const I18nContext = createContext({
  locale: 'fr',
  setLocale: () => {},
  t: (key, vars, fallback) => fallback ?? key,
});

function getNestedValue(object, dottedKey) {
  return String(dottedKey || '')
    .split('.')
    .reduce((acc, part) => (acc && Object.prototype.hasOwnProperty.call(acc, part) ? acc[part] : undefined), object);
}

function interpolate(template, vars = {}) {
  if (typeof template !== 'string') return template;
  return template.replace(/\{(\w+)\}/g, (_, key) => String(vars?.[key] ?? `{${key}}`));
}

function resolveInitialLocale(initialLocale) {
  if (typeof window !== 'undefined') {
    const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY);
    if (stored && SUPPORTED_LOCALES.includes(stored)) {
      return stored;
    }
  }

  if (SUPPORTED_LOCALES.includes(initialLocale)) {
    return initialLocale;
  }

  return 'fr';
}

export function I18nProvider({ children, initialLocale = 'fr' }) {
  const [locale, setLocaleState] = useState(() => resolveInitialLocale(initialLocale));

  const setLocale = useCallback((nextLocale) => {
    const normalized = SUPPORTED_LOCALES.includes(nextLocale) ? nextLocale : 'fr';
    setLocaleState(normalized);

    if (typeof window !== 'undefined') {
      window.localStorage.setItem(LOCALE_STORAGE_KEY, normalized);
    }
  }, []);

  const t = useCallback((key, vars = {}, fallback = null) => {
    const selectedPack = TRANSLATIONS[locale] ?? TRANSLATIONS.fr;
    const frenchPack = TRANSLATIONS.fr;
    const raw = getNestedValue(selectedPack, key) ?? getNestedValue(frenchPack, key) ?? fallback ?? key;
    return interpolate(raw, vars);
  }, [locale]);

  const value = useMemo(() => ({
    locale,
    setLocale,
    t,
    locales: SUPPORTED_LOCALES,
  }), [locale, setLocale, t]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  return useContext(I18nContext);
}

