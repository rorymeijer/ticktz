#!/bin/sh
set -e

# Wait for the database before doing anything that touches it. Compose health
# checks already gate startup, but self-hosters sometimes point Ticktz at an
# external MySQL that is slower to come up.
wait_for_database() {
    tries=0
    until php -r '
        $dsn = sprintf("mysql:host=%s;port=%s", getenv("DB_HOST") ?: "127.0.0.1", getenv("DB_PORT") ?: "3306");
        try { new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); }
        catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            echo "ticktz: database not reachable after 60 attempts, continuing anyway" >&2
            return 0
        fi
        echo "ticktz: waiting for database (${tries}/60)..."
        sleep 2
    done
}

# The development stack bind-mounts the source tree over the image, so the code
# moves and the autoloader's classmap does not. A class added since the image
# was built is on disk and absent from the map, which surfaces as
# `Trait "…" not found` in a file that has been there all along — and the
# `vendor` volume makes it stick, because Docker only fills a named volume from
# the image while it is still empty.
#
# Rebuilding the map takes a second or two and is never done in production,
# where the tree in the image is the tree that runs.
if [ "${APP_ENV:-production}" != "production" ] && [ -x /usr/bin/composer ]; then
    echo "ticktz: rebuilding the autoloader for the mounted source tree"
    composer dump-autoload --no-interaction --optimize --quiet 2>/dev/null || true
fi

if [ -z "${APP_KEY}" ] && [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
    echo "ticktz: generating application key"
    php artisan key:generate --force --no-interaction
fi

case "${CONTAINER_ROLE}" in
    app)
        wait_for_database
        if [ "${TICKTZ_AUTO_MIGRATE:-true}" = "true" ]; then
            php artisan migrate --force --no-interaction
        fi
        php artisan storage:link --no-interaction 2>/dev/null || true

        # Seed the demo desk on a first boot, so `docker compose up` on a fresh
        # clone gives something you can actually sign in to rather than an
        # empty database with no accounts in it.
        #
        # Three guards, because this writes people and tickets into a database:
        # never in production, never unless explicitly enabled, and never when
        # the instance already has users. The last one is what makes it safe to
        # leave on — a restart re-runs this block, and re-seeding over a desk
        # somebody has started using would be unforgivable.
        if [ "${TICKTZ_SEED_DEMO:-false}" = "true" ] && [ "${APP_ENV:-production}" != "production" ]; then
            if [ "$(php artisan tinker --execute='echo \App\Models\User::query()->withTrashed()->count();' 2>/dev/null | tr -cd '0-9')" = "0" ]; then
                echo "ticktz: empty instance, seeding the demo desk"
                php artisan ticktz:demo --no-interaction
            fi
        fi
        if [ "${APP_ENV:-production}" = "production" ]; then
            php artisan config:cache
            php artisan route:cache
            php artisan event:cache
        fi
        ;;
    worker)
        wait_for_database
        ;;
esac

exec "$@"
