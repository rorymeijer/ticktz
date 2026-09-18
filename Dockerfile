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

# The redis extension is built from its own source, at a pinned version.
#
# It used to be `pecl install redis`, and on 2026-09-18 that stopped two
# attempts at cutting 1.1.8 with two different failures an hour apart:
#
#     Could not download from "https://pecl.php.net/get/redis-6.3.0.tgz"
#     (received: HTTP/1.1 504 Gateway Timeout)
#
#     Package "redis" does not have REST info xml available
#
# The second is the one that decided this. `pecl install redis` cannot resolve
# "latest" without the channel's REST metadata, so when pecl.php.net is
# degraded there is nothing to retry into — the request that fails is the one
# that works out what to ask for. Retrying a registry that is down is a longer
# way to fail.
#
# So the registry is out of the path. phpredis is fetched from its own
# repository at a tag and compiled here, which is what `pecl install` does
# underneath anyway. It trades a host nobody here operates for one this entire
# pipeline already cannot run without.
#
# One path rather than pecl-with-a-fallback: a fallback that runs on the rare
# day pecl is down is a fallback nobody has ever seen work. This runs on every
# build, including CI's docker job on every pull request, so it cannot rot.
#
# Downloaded to a file and then unpacked, rather than piped into `tar`. In a
# pipeline the shell reports the exit status of the last command, so a curl
# that fails into a `tar` that shrugs is a build that carries on without the
# source it was fetching.
#
# No checksum, deliberately. GitHub does not guarantee that an auto-generated
# tag archive is byte-for-byte stable over time, so a pinned digest here would
# eventually fail a build for a reason that is not a compromise — a guarantee
# that turns into a maintenance trap. The tag is the pin.
#
# `phpize && ./configure && make && make install` is what upstream's INSTALL.md
# documents, and `PHP_ARG_ENABLE(redis, ...)` is on by default for a phpize'd
# build, so plain `./configure` is the whole extension with none of the
# optional serializers — which is what `pecl install` was building anyway.
#
# Pinned at all because `pecl install redis` resolved to whatever was newest on
# the day, so two builds of the same tag could ship different extensions — a
# release image not reproducible from its own tag, which is most of what a tag
# is for. Bumping it is a commit somebody reviews.
#
# It stays inside this one RUN rather than becoming its own: `apk del
# .build-deps` has to happen in the same layer that added them, or the image
# carries a compiler toolchain it never uses.
ARG REDIS_EXT_VERSION=6.3.0

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
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl ldap opcache pcntl pdo_mysql sockets zip \
    && curl -fsSL -o /tmp/phpredis.tar.gz \
        "https://github.com/phpredis/phpredis/archive/refs/tags/${REDIS_EXT_VERSION}.tar.gz" \
    && mkdir -p /tmp/phpredis \
    && tar -xzf /tmp/phpredis.tar.gz -C /tmp/phpredis --strip-components=1 \
    && (cd /tmp/phpredis && phpize && ./configure && make -j"$(nproc)" && make install) \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /tmp/phpredis /tmp/phpredis.tar.gz

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
