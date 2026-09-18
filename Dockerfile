# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Ticktz production image.
#
# Stage 1 builds the PHP dependencies, stage 2 builds the frontend bundle and
# stage 3 assembles a slim php-fpm runtime that contains both. The same image
# is used for the `app` (php-fpm) and `worker` (queue + scheduler) services;
# the entrypoint decides which role to start based on CONTAINER_ROLE.
#
# The application tree lands in /usr/src/ticktz rather than /var/www/html, and
# the entrypoint copies it into place on boot. That is what lets the production
# stack put /var/www/html on a volume the app and the worker share — which is
# what makes upgrading from the browser possible, since a container's own
# writable layer is invisible to its sibling and gone on the next `up -d`.
#
# The pristine copy has to live somewhere a volume cannot shadow, or the image
# would have no way to hand its code to a volume that already has older code in
# it. That is the whole reason for the second path. See docker/php/entrypoint.sh.
# ---------------------------------------------------------------------------

# --- Stage 1: composer dependencies ----------------------------------------
#
# Pinned to the platform doing the building rather than the one being built
# for. What comes out of this stage is PHP source and JavaScript — a `vendor`
# tree and a bundle, neither of which has an instruction set — so building it
# twice, once per architecture, is the same files produced twice, and building
# it under emulation is that plus the emulation.
#
# It changes nothing on the release runners, where each architecture is built
# by a machine of that architecture and the two platforms are already the same.
# It is for the person cross-building one image on their laptop, and for the
# day the arm64 runners are unavailable and CI falls back to QEMU: then only
# the runtime stage, which genuinely does compile C, is emulated.
FROM --platform=$BUILDPLATFORM php:8.4-fpm-alpine AS vendor

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
FROM --platform=$BUILDPLATFORM node:22-alpine AS assets

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
COPY docker/php/place-code.sh /usr/local/bin/ticktz-place-code
COPY docker/php/instance-setting.php /usr/local/bin/ticktz-instance-setting
COPY docker/php/drop-seeded-env.sh /usr/local/bin/ticktz-drop-seeded-env
RUN chmod +x /usr/local/bin/ticktz-entrypoint /usr/local/bin/ticktz-place-code

COPY --from=vendor /var/www/html /usr/src/ticktz
COPY --from=assets /app/public/build /usr/src/ticktz/public/build

# A second lock on the same door as .dockerignore, because this one is cheap
# and the failure it prevents is silent.
#
# `public/hot` is written by the Vite dev server and makes Laravel point every
# browser at http://localhost:5173 for its JavaScript — the page comes up white
# with nothing in any server log to say why. `bootstrap/cache` may hold a
# config compiled on whoever's machine built this. Neither belongs in an image,
# and the release archive already strips both; this keeps the two ways of
# shipping Ticktz producing the same tree.
# `storage` and `bootstrap/cache` keep their directories — the build needs
# them, and .dockerignore therefore leaves them in the context — so what is in
# them is emptied here instead. Whatever a developer's instance left behind
# (logs, sessions, compiled views, uploaded attachments, a config cache
# compiled for their paths) belongs to that instance and not to this image.
RUN rm -f /usr/src/ticktz/public/hot \
          /usr/src/ticktz/.env \
          /usr/src/ticktz/bootstrap/cache/*.php \
    && find /usr/src/ticktz/storage -type f ! -name '.gitignore' -exec rm -f {} +

RUN mkdir -p /usr/src/ticktz/storage/framework/{cache,sessions,testing,views} \
        /usr/src/ticktz/storage/logs \
        /usr/src/ticktz/bootstrap/cache \
    && chown -R www-data:www-data /usr/src/ticktz \
    && chmod -R ug+rwx /usr/src/ticktz/storage /usr/src/ticktz/bootstrap/cache

# The version this image was built from, for the entrypoint to compare against
# whatever is in the volume. Read out of the code rather than passed as a build
# argument, so it cannot disagree with what the application reports about
# itself. The build fails here rather than shipping an image that cannot tell
# the entrypoint what it is.
RUN set -eu; \
    version="$(sed -n "s/.*env('TICKTZ_VERSION', *'\([^']*\)').*/\1/p" /usr/src/ticktz/config/ticktz.php)"; \
    test -n "$version"; \
    mkdir -p /usr/local/share/ticktz; \
    printf '%s' "$version" > /usr/local/share/ticktz/image-version

# /var/www/html is filled by the entrypoint, from /usr/src/ticktz or from a
# volume that already holds a newer version. It exists here so a container
# started without any volume still has a working directory to be in.
RUN mkdir -p /var/www/html && chown www-data:www-data /var/www/html

ENV CONTAINER_ROLE=app

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/ticktz-entrypoint"]
CMD ["php-fpm"]
