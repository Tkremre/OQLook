<p align="center">
  <img src="docs/assets/oqlook-mark.svg" alt="OQLook" width="84" />
</p>

<h1 align="center">OQLook</h1>

<p align="center">
  EN: Adaptive CMDB quality scanner for iTop (self-hosted).<br/>
  FR: Scanner adaptatif de qualité CMDB pour iTop (auto-hébergé).
</p>

---

## Table of Contents / Sommaire

- [Overview / Présentation](#overview--présentation)
- [Features / Fonctionnalités](#features--fonctionnalités)
- [Quick Start (Linux/Windows)](#quick-start-linuxwindows)
- [Manual Installation / Installation manuelle](#manual-installation--installation-manuelle)
- [Configuration](#configuration)
- [Operations / Exploitation](#operations--exploitation)
- [Troubleshooting / Dépannage](#troubleshooting--dépannage)
- [Documentation](#documentation)

## Overview / Présentation

OQLook audits iTop CMDB data quality with adaptive checks, scoring, issue tracking, drilldown and exports.

OQLook audite la qualité des données iTop CMDB avec des contrôles adaptatifs, un scoring, le suivi des anomalies, le drilldown et des exports.

## Features / Fonctionnalités

- Multi-domain scoring: `completeness`, `consistency`, `relations`, `obsolescence`, `hygiene`.
- Full and delta scans.
- Rule-level and object-level acknowledgements.
- Drilldown list of impacted objects with filters/sorting.
- PDF export of scan context, KPIs and issue details.
- iTop metamodel discovery via REST and optional connector.
- UI preferences: language, theme, density, layout.

## Quick Start (Linux/Windows)

### Generic prerequisites

- PHP `8.2+` with required extensions (`curl`, `mbstring`, `intl`, `pdo_pgsql` or `pdo_mysql`, `zip`, `gd`; `ldap` optional)
- Composer `2+`
- Node.js `20+` (LTS recommended)
- PostgreSQL or MySQL/MariaDB
- Web server (Nginx or Apache) pointing to `public/`
- Redis optional (recommended for queue)

### Install scripts

| OS | Bootstrap script | Production hardening |
|---|---|---|
| Linux | `scripts/install/linux/bootstrap.sh` | `scripts/install/linux/hardening.sh` |
| Windows | `scripts/install/windows/bootstrap.ps1` | `scripts/install/windows/production-hardening.ps1` |

Clone:

```bash
git clone https://github.com/<org>/OQLook.git
cd OQLook
```

Linux example:

```bash
chmod +x scripts/install/linux/bootstrap.sh
./scripts/install/linux/bootstrap.sh --install-deps
```

Windows example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\bootstrap.ps1 -InstallDeps -InstallPostgres
```

Script reference: `scripts/install/README.md`

## Manual Installation / Installation manuelle

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
npm install
npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear
```

## Configuration

Minimum `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-hostname-or-subpath
ASSET_URL=https://your-hostname-or-subpath

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=oqlook
DB_USERNAME=oqlook
DB_PASSWORD=change_me
```

If served from a subpath (example `/oqlook`):

```env
APP_URL=https://mydomain/oqlook
ASSET_URL=https://mydomain/oqlook
```

Then:

```bash
npm run build
php artisan optimize:clear
```

### Key OQLike tuning variables

- Scan limits:
  - `OQLIKE_MAX_FULL_RECORDS_PER_CLASS`
  - `OQLIKE_MAX_FULL_RECORDS_WITHOUT_DELTA`
  - `OQLIKE_DELTA_STRICT_MODE`
  - `OQLIKE_MAX_DUPLICATE_SCAN_RECORDS`
- Metamodel/connector:
  - `OQLIKE_MAX_CONNECTOR_CLASSES`
  - `OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO`
  - `OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO`
  - `OQLIKE_DISCOVERY_SCAN_LIMIT`
- Object acknowledgements:
  - `OQLIKE_OBJECT_ACK_ENABLED=true`
  - `OQLIKE_OBJECT_ACK_MAX_VERIFICATIONS_PER_ISSUE=250`
  - `OQLIKE_ISSUE_OBJECTS_MAX_FETCH=5000`

## Operations / Exploitation

Queue worker (recommended):

```bash
php artisan queue:work --queue=default --tries=1
```

Discovery only:

```bash
php artisan oqlike:discover <connection_id>
```

Run scan from CLI:

```bash
php artisan oqlike:scan <connection_id> --mode=delta
php artisan oqlike:scan <connection_id> --mode=full --classes=Server,Person
```

## Troubleshooting / Dépannage

### Dotenv parse error

`Failed to parse dotenv file. Encountered unexpected whitespace`

Do not put spaces in comma-separated values:

```env
OQLIKE_ADMIN_PACK_PLACEHOLDER_TERMS=test,tmp,todo,tbd,sample,dummy,unknown,n/a,na,xxx,to_define
```

### `Could not open input file: artisan`

Run commands from project root:

```bash
cd /path/to/OQLook
php artisan ...
```

### Missing assets (`/build/assets/...`)

- Check `APP_URL` and `ASSET_URL`
- Rebuild frontend assets
- Clear Laravel caches

### Scan seems stuck

- Inspect `storage/logs/laravel.log`
- Reduce caps (`OQLIKE_MAX_FULL_RECORDS_PER_CLASS`, `OQLIKE_MAX_DUPLICATE_SCAN_RECORDS`)
- Use queue worker and watchdog in production

## Documentation

- Classic install: `docs/INSTALL_CLASSIC.md`
- Docker install: `docs/INSTALL_DOCKER.md`
- Production hardening: `docs/PRODUCTION_HARDENING.md`
- Connector deployment: `oqlike-connector/README.md`
- Install scripts: `scripts/install/README.md`
