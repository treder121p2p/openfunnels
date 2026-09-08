FROM composer:2 AS php-dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

FROM node:22-alpine AS frontend
WORKDIR /app
ENV NODE_OPTIONS=--dns-result-order=ipv4first
RUN corepack enable
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY resources ./resources
COPY public ./public
COPY components.json tsconfig.json vite.config.ts ./
RUN echo "Building at $(date)" && pnpm run build

FROM php:8.4-cli-alpine AS application
RUN apk add --no-cache icu-libs libzip sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-install intl mbstring pcntl pdo_sqlite zip \
    && apk del .build-deps

WORKDIR /app
COPY . .
COPY --from=php-dependencies /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
# Disable Inertia SSR (vendor config uses env() which casts "false" to true)
RUN sed -i "s/'enabled' => (bool) env('INERTIA_SSR_ENABLED', true)/'enabled' => false/" vendor/inertiajs/inertia-laravel/config/inertia.php
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs /data \
    && chmod -R 775 storage bootstrap/cache /data

EXPOSE 8000
ENTRYPOINT ["sh", "/app/docker/entrypoint.sh"]
CMD ["web"]
