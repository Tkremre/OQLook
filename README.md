# OQLook

EN: OQLook is a self-hosted Laravel application that audits iTop CMDB data quality (adaptive checks, scoring, issues, drilldown, exports).  
FR: OQLook est une application Laravel auto-hébergée pour auditer la qualité des données iTop CMDB (contrôles adaptatifs, score, anomalies, drilldown, exports).

## EN - Quick Start

### 1) Requirements

- PHP 8.2+ with required extensions (`curl`, `mbstring`, `intl`, `pdo_pgsql` or `pdo_mysql`, `zip`, `gd`, `ldap` if needed)
- Composer 2+
- Node.js 20+ (LTS recommended)
- PostgreSQL or MySQL/MariaDB
- Redis (optional, recommended for queue)
- Web server (Nginx or Apache) pointing to `public/`

### 2) Automatic setup scripts

- Linux: `scripts/install/linux/bootstrap.sh`
- Windows: `scripts/install/windows/bootstrap.ps1`
- Linux production hardening: `scripts/install/linux/hardening.sh`
- Windows production hardening: `scripts/install/windows/production-hardening.ps1`
- Script docs: `scripts/install/README.md`

Install directly from GitHub:

```bash
git clone https://github.com/<org>/OQLook.git
cd OQLook
```

Examples:

```bash
chmod +x scripts/install/linux/bootstrap.sh
./scripts/install/linux/bootstrap.sh --install-deps
```

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\bootstrap.ps1 -InstallDeps -InstallPostgres
```

### 3) Manual setup

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
npm install
npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear
```

### 4) Configure `.env`

Minimum:

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

If deployed under subpath (example `/oqlook`), set:

```env
APP_URL=https://mydomain/oqlook
ASSET_URL=https://mydomain/oqlook
```

Then rebuild frontend and clear cache:

```bash
npm run build
php artisan optimize:clear
```

### 5) Queue worker (recommended in production)

```bash
php artisan queue:work --queue=default --tries=1
```

### 6) Useful commands

- Run class discovery only:

```bash
php artisan oqlike:discover <connection_id>
```

- Run a scan from CLI:

```bash
php artisan oqlike:scan <connection_id> --mode=delta
php artisan oqlike:scan <connection_id> --mode=full --classes=Server,Person
```

## FR - Démarrage Rapide

### 1) Prérequis

- PHP 8.2+ avec extensions requises (`curl`, `mbstring`, `intl`, `pdo_pgsql` ou `pdo_mysql`, `zip`, `gd`, `ldap` si nécessaire)
- Composer 2+
- Node.js 20+ (LTS recommandé)
- PostgreSQL ou MySQL/MariaDB
- Redis (optionnel, recommandé pour la queue)
- Serveur web (Nginx ou Apache) pointant vers `public/`

### 2) Scripts d'installation automatiques

- Linux : `scripts/install/linux/bootstrap.sh`
- Windows : `scripts/install/windows/bootstrap.ps1`
- Hardening prod Linux : `scripts/install/linux/hardening.sh`
- Hardening prod Windows : `scripts/install/windows/production-hardening.ps1`
- Documentation : `scripts/install/README.md`

Installer directement depuis GitHub :

```bash
git clone https://github.com/<org>/OQLook.git
cd OQLook
```

Exemples :

```bash
chmod +x scripts/install/linux/bootstrap.sh
./scripts/install/linux/bootstrap.sh --install-deps
```

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install\windows\bootstrap.ps1 -InstallDeps -InstallPostgres
```

### 3) Installation manuelle

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
npm install
npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear
```

### 4) Configuration `.env`

Minimum :

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-hote-ou-sous-chemin
ASSET_URL=https://votre-hote-ou-sous-chemin

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=oqlook
DB_USERNAME=oqlook
DB_PASSWORD=change_me
```

Si l'application est servie dans un sous-chemin (ex: `/oqlook`) :

```env
APP_URL=https://mondomaine/oqlook
ASSET_URL=https://mondomaine/oqlook
```

Puis :

```bash
npm run build
php artisan optimize:clear
```

### 5) Worker queue (recommandé en production)

```bash
php artisan queue:work --queue=default --tries=1
```

### 6) Commandes utiles

- Découverte des classes uniquement :

```bash
php artisan oqlike:discover <connection_id>
```

- Scan en ligne de commande :

```bash
php artisan oqlike:scan <connection_id> --mode=delta
php artisan oqlike:scan <connection_id> --mode=full --classes=Server,Person
```

## Docker

For containerized setup, see:
- `docs/INSTALL_DOCKER.md`

## Advanced OQLike Settings

Key options in `.env` / `config/oqlike.php`:

- Scan behavior:
  - `OQLIKE_MAX_FULL_RECORDS_PER_CLASS`
  - `OQLIKE_MAX_FULL_RECORDS_WITHOUT_DELTA`
  - `OQLIKE_DELTA_STRICT_MODE`
  - `OQLIKE_MAX_DUPLICATE_SCAN_RECORDS`
- Connector/metamodel memory guards:
  - `OQLIKE_MAX_CONNECTOR_CLASSES`
  - `OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO`
  - `OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO`
- Admin pack:
  - `OQLIKE_ADMIN_PACK_ENABLED`
  - `OQLIKE_ADMIN_PACK_*`
- Object acknowledgements:
  - `OQLIKE_OBJECT_ACK_ENABLED=true`
  - `OQLIKE_OBJECT_ACK_MAX_VERIFICATIONS_PER_ISSUE=250`
  - `OQLIKE_ISSUE_OBJECTS_MAX_FETCH=5000`

After editing `.env`:

```bash
php artisan optimize:clear
```

## Acknowledgements (Rule and Object Level)

OQLook supports:

- Rule/class acknowledgements: skip full checks for `connection + class + issue_code`
- Object acknowledgements: exclude specific iTop objects from issue counts

Object acknowledgements require the migration:

```bash
php artisan migrate --force
```

## Common Troubleshooting

### `Failed to parse dotenv file. Encountered unexpected whitespace`

Do not use spaces in comma-separated env lists.  
Example:

```env
OQLIKE_ADMIN_PACK_PLACEHOLDER_TERMS=test,tmp,todo,tbd,sample,dummy,unknown,n/a,na,xxx,to_define
```

### `Could not open input file: artisan`

Run commands from the project root:

```bash
cd /path/to/OQLook
php artisan ...
```

### `404 /build/assets/...`

- ensure `APP_URL` and `ASSET_URL` are correct
- rebuild assets
- clear cache

### Scans are long or appear stuck

- check `storage/logs/laravel.log`
- use lower caps (`OQLIKE_MAX_FULL_RECORDS_PER_CLASS`, `OQLIKE_MAX_DUPLICATE_SCAN_RECORDS`)
- run queue worker for background scans

## Additional Docs

- Classic install: `docs/INSTALL_CLASSIC.md`
- Docker install: `docs/INSTALL_DOCKER.md`
- Production hardening: `docs/PRODUCTION_HARDENING.md`
- Connector deployment: `oqlike-connector/README.md`
