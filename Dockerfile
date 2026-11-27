# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base: PHP-FPM, nginx and supervisor. Shared by the build and runtime stages
# so Composer resolves against the same PHP and extensions that run the app.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

RUN apk upgrade --no-cache \
    && apk add --no-cache nginx supervisor libpq icu-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql intl pcntl bcmath opcache \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/www/html/*

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor: production PHP dependencies. Composer never reaches the final image.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache git unzip

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer install --no-dev --classmap-authoritative --no-interaction --no-progress

# ---------------------------------------------------------------------------
# assets: the Vite build. Node never reaches the final image.
# ---------------------------------------------------------------------------
FROM node:26-bookworm-slim AS assets

ARG VITE_APP_NAME=wag.run
ENV VITE_APP_NAME=$VITE_APP_NAME

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-fund

# The stylesheet imports CSS from vendor/, and Tailwind scans the views.
COPY --from=vendor /var/www/html ./
RUN npm run build

# ---------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

ARG VERSION=dev

LABEL org.opencontainers.image.title="wag.run" \
      org.opencontainers.image.description="Self-hosted workflow automation" \
      org.opencontainers.image.source="https://github.com/wag-run/wag-run" \
      org.opencontainers.image.licenses="AGPL-3.0-only" \
      org.opencontainers.image.version="$VERSION"

# Config caches go to /tmp so the application directory can stay read-only.
ENV VERSION=$VERSION \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/storage/database/database.sqlite \
    APP_CONFIG_CACHE=/tmp/cache/config.php \
    APP_ROUTES_CACHE=/tmp/cache/routes.php \
    APP_EVENTS_CACHE=/tmp/cache/events.php \
    QUEUE_WORKERS=2

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i -e '/^user = /d' -e '/^group = /d' /usr/local/etc/php-fpm.d/www.conf

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=0555 docker/entrypoint.sh /usr/local/bin/entrypoint

# Code is owned by root and read-only to the app user. Only storage/, which is
# meant to be a volume, is writable.
COPY --from=vendor /var/www/html ./
COPY --from=assets /app/public/build ./public/build

RUN rm -rf docker \
    && ln -s ../storage/app/public public/storage \
    && mkdir -p storage/database \
    && chown -R www-data:www-data storage \
    && chmod -R u=rwX,g=rX,o= storage

# www-data, by number so orchestrators can verify the container is non-root.
USER 82:82

EXPOSE 8080

VOLUME ["/var/www/html/storage"]

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
