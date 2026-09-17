# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Ticktz production image.
#
# Stage 1 builds the PHP dependencies, stage 2 builds the frontend bundle and
# stage 3 assembles a slim php-fpm runtime that contains both. The same image
# is used for the `app` (php-fpm) and `worker` (queue + scheduler) services;
# the entrypoint decides which role to start based on CONTAINER_ROLE.
# ---------------------------------------------------------------------------

# --- Stage 1: composer dependencies ----------------------------------------
FROM php:8.4-fpm-alpine AS vendor

# `ldap` is not optional here even though only directory sign-in uses it:
# directorytree/ldaprecord declares ext-ldap as a hard requirement, so
# `composer install` refuses to resolve the lock file without it. Leave it out
# and this stage fails before a single package is written.
RUN apk add --no-cache git unzip icu-dev oniguruma-dev libzip-dev openldap-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" bcmath intl ldap pdo_mysql zip \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

COPY . .
# `--optimize` and deliberately *not* `--classmap-authoritative`.
#
# Authoritative means: if a class is not in this classmap, it does not exist —
# no filesystem lookup, ever. That is correct for an image whose code never
# changes, and wrong the moment a development stack bind-mounts a newer tree
# over it: a class added after the image was built is right there on disk and
# the autoloader refuses to look, which surfaces as `Trait "…" not found` in a
# file that has been there all along.
#
# The classmap still makes the common case a single array lookup. What we give
# up is a stat call for a class the map has never heard of, which in production
# is a class that does not exist anyway.
RUN composer dump-autoload --no-dev --optimize

# --- Stage 2: frontend bundle ----------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

# --- Stage 3: runtime -------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        icu-libs \
        oniguruma \
        libzip \
        libpng \
        freetype \
        libjpeg-turbo \
        libldap \
        mysql-client \
        supervisor \
        tini \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        openldap-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl ldap opcache pcntl pdo_mysql sockets zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

WORKDIR /var/www/html

# Composer, so the entrypoint can rebuild the autoloader on a development
# stack whose code is a bind mount. Two megabytes of PHP script, and it is
# never run in production — see the entrypoint.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-ticktz.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-ticktz.conf
COPY docker/worker/supervisord.conf /etc/supervisor/conf.d/ticktz.conf
COPY docker/php/entrypoint.sh /usr/local/bin/ticktz-entrypoint
RUN chmod +x /usr/local/bin/ticktz-entrypoint

COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

ENV CONTAINER_ROLE=app

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/ticktz-entrypoint"]
CMD ["php-fpm"]
