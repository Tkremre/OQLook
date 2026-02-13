# Docker Installation

## EN

### Requirements

- Docker Engine
- Docker Compose plugin

### Steps

```bash
cp .env.example .env
docker compose up --build -d
```

Open:
- `http://localhost:8080`

Default ports from this repository:
- App: `8080`
- PostgreSQL: `5434`
- Redis: `6380`

### First-time setup (if needed)

```bash
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
```

### Worker

```bash
docker compose exec app php artisan queue:work --queue=default --tries=1
```

For non-Docker production hardening (systemd/nginx/apache/logrotate), see:
- `docs/PRODUCTION_HARDENING.md`

## FR

### Prérequis

- Docker Engine
- Plugin Docker Compose

### Étapes

```bash
cp .env.example .env
docker compose up --build -d
```

Accès:
- `http://localhost:8080`

Ports par défaut de ce dépôt:
- App: `8080`
- PostgreSQL: `5434`
- Redis: `6380`

### Initialisation (si nécessaire)

```bash
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
```

### Worker

```bash
docker compose exec app php artisan queue:work --queue=default --tries=1
```

Pour un hardening de production hors Docker (systemd/nginx/apache/logrotate), voir:
- `docs/PRODUCTION_HARDENING.md`
