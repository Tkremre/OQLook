FROM node:25-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY resources ./resources
COPY vite.config.js ./
COPY public ./public
RUN npm run build

FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

FROM php:8.3-cli-alpine
WORKDIR /var/www

RUN apk add --no-cache icu-dev libzip-dev postgresql-dev oniguruma-dev bash \
    && docker-php-ext-install pdo pdo_pgsql intl zip mbstring

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN chmod +x ./scripts/docker-entrypoint.sh \
    && sed -i 's/\r$//' ./scripts/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 8000
CMD ["./scripts/docker-entrypoint.sh"]
