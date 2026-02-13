#!/usr/bin/env sh
set -e

if [ ! -f .env ]; then
  cp .env.example .env
fi

php artisan key:generate --force || true
php artisan migrate --force || true

exec php artisan serve --host=0.0.0.0 --port=8000
