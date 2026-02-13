export function appPath(path = '') {
  if (typeof path === 'string' && (/^https?:\/\//.test(path) || path.startsWith('#'))) {
    return path;
  }

  const base = String(window.OQLOOK_BASE_PATH ?? '').trim().replace(/\/+$/, '');
  const clean = String(path ?? '').replace(/^\/+/, '');

  if (!clean) {
    return base || '/';
  }

  if (!base) {
    return `/${clean}`;
  }

  return `${base}/${clean}`.replace(/\/+/g, '/');
}
