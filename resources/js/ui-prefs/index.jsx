import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';

const STORAGE_KEY = 'oqlike.ui_prefs';

const THEMES = ['oqlook', 'slate', 'sand', 'dark'];
const LAYOUTS = ['full', 'boxed'];
const DENSITIES = ['comfortable', 'compact'];

const DEFAULT_PREFS = {
  theme: 'oqlook',
  layout: 'full',
  density: 'comfortable',
};

const UiPrefsContext = createContext({
  prefs: DEFAULT_PREFS,
  setTheme: () => {},
  setLayout: () => {},
  setDensity: () => {},
});

function normalizePrefs(rawPrefs) {
  const candidate = typeof rawPrefs === 'object' && rawPrefs !== null ? rawPrefs : {};

  return {
    theme: THEMES.includes(candidate.theme) ? candidate.theme : DEFAULT_PREFS.theme,
    layout: LAYOUTS.includes(candidate.layout) ? candidate.layout : DEFAULT_PREFS.layout,
    density: DENSITIES.includes(candidate.density) ? candidate.density : DEFAULT_PREFS.density,
  };
}

function loadPrefs() {
  if (typeof window === 'undefined') {
    return DEFAULT_PREFS;
  }

  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);

    if (!raw) {
      return DEFAULT_PREFS;
    }

    return normalizePrefs(JSON.parse(raw));
  } catch {
    return DEFAULT_PREFS;
  }
}

export function UiPrefsProvider({ children }) {
  const [prefs, setPrefs] = useState(() => loadPrefs());

  useEffect(() => {
    if (typeof window !== 'undefined') {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    }

    if (typeof document !== 'undefined') {
      document.documentElement.setAttribute('data-oq-theme', prefs.theme);
      document.documentElement.setAttribute('data-oq-layout', prefs.layout);
      document.documentElement.setAttribute('data-oq-density', prefs.density);
    }
  }, [prefs]);

  const value = useMemo(() => ({
    prefs,
    setTheme: (theme) => {
      setPrefs((previous) => ({
        ...previous,
        theme: THEMES.includes(theme) ? theme : previous.theme,
      }));
    },
    setLayout: (layout) => {
      setPrefs((previous) => ({
        ...previous,
        layout: LAYOUTS.includes(layout) ? layout : previous.layout,
      }));
    },
    setDensity: (density) => {
      setPrefs((previous) => ({
        ...previous,
        density: DENSITIES.includes(density) ? density : previous.density,
      }));
    },
  }), [prefs]);

  return <UiPrefsContext.Provider value={value}>{children}</UiPrefsContext.Provider>;
}

export function useUiPrefs() {
  return useContext(UiPrefsContext);
}
