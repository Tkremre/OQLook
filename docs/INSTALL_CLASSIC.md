# Classic Installation (No Docker)

## EN

### Requirements

- PHP 8.2+
- Composer 2+
- Node.js 20+
- PostgreSQL or MySQL
- Redis optional
- Web server (Nginx/Apache) with document root on `public/`

### Install

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
npm install
npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear
```

### Run

- Dev mode:

```bash
php artisan serve
```

- Production:
  - use Nginx/Apache + PHP-FPM
  - run queue worker:

```bash
php artisan queue:work --queue=default --tries=1
```

### Optional scripts

- Linux bootstrap: `scripts/install/linux/bootstrap.sh`
- Windows bootstrap: `scripts/install/windows/bootstrap.ps1`
- Production hardening: `docs/PRODUCTION_HARDENING.md`

## FR

### Prérequis

- PHP 8.2+
- Composer 2+
- Node.js 20+
- PostgreSQL ou MySQL
- Redis optionnel
- Serveur web (Nginx/Apache) avec racine sur `public/`

### Installation

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
npm install
npm run build
php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear
```

### Exécution

- Mode dev:

```bash
php artisan serve
```

- Production:
  - Nginx/Apache + PHP-FPM
  - worker queue:

```bash
php artisan queue:work --queue=default --tries=1
```

### Scripts optionnels

- Bootstrap Linux: `scripts/install/linux/bootstrap.sh`
- Bootstrap Windows: `scripts/install/windows/bootstrap.ps1`
- Hardening production: `docs/PRODUCTION_HARDENING.md`
