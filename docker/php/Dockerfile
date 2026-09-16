# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Renovo application image (php-fpm).
#
# Both database drivers are compiled in, because the same image must be able
# to run against PostgreSQL or MySQL/MariaDB — which engine is in use is a
# runtime setting, not a build-time one.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        icu-libs \
        libpq \
        libzip \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        opcache \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini

# ---------------------------------------------------------------------------
# Dependencies are installed in their own layer so that changing application
# code does not invalidate the Composer cache.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist \
    && composer clear-cache

# ---------------------------------------------------------------------------
FROM base AS app

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .

# var/attachments and var/imports are created here rather than left to the
# application so their mode is set once, at build time. Both hold user files
# that are never web-served — invoices and receipts in the first, in-progress
# uploads in the second — so they are 0750 rather than the 0755 a cache wants.
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p var/cache var/log var/attachments var/imports public/assets/logos \
    && chmod 750 var/attachments var/imports \
    && chown -R www-data:www-data var public/assets/logos

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
