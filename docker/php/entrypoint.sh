#!/bin/sh
set -e

# Put the image's code into /var/www/html, or leave a mounted source tree
# alone. Its own script because it is the part with the decisions in it, and a
# decision nobody can run is a decision nobody has checked — see
# docker/php/place-code.sh and tests/Shell/place-code.test.sh.
if [ -x /usr/local/bin/ticktz-place-code ]; then
    /usr/local/bin/ticktz-place-code
fi

# It has just written the stack's settings into the instance's `.env`. Now take
# them out of the environment, so that the file is what decides — including the
# file the setup wizard writes. See docker/php/drop-seeded-env.sh.
if [ -r "${TICKTZ_DROP_SEEDED_ENV:-/usr/local/bin/ticktz-drop-seeded-env}" ]; then
    . "${TICKTZ_DROP_SEEDED_ENV:-/usr/local/bin/ticktz-drop-seeded-env}"
fi

cd "${TICKTZ_APP_ROOT:-/var/www/html}"

# Throw away the compiled config before anything reads it.
#
# It is a snapshot of the environment as it stood during some earlier boot, and
# it lives in the code volume, so it outlives the boot that wrote it. An
# instance that once came up without an APP_KEY has a compiled config saying
# there is no key, and keeps answering from it even after the key exists —
# `printenv APP_KEY` shows the key, `config('app.key')` says there is none, and
# nothing in either answer explains the other.
#
# Production compiles a fresh one at the end of this script, once the
# environment is settled. Everywhere else the framework reads .env directly,
# which is what a developer changing a setting expects.
rm -f bootstrap/cache/config.php

# One of this instance's settings, resolved the way the application resolves
# it — environment first, then `.env`. See docker/php/instance-setting.php.
setting() {
    php "${TICKTZ_INSTANCE_SETTING:-/usr/local/bin/ticktz-instance-setting}" "$1" "${2:-}"
}

# Wait for the database before doing anything that touches it. Compose health
# checks already gate startup, but self-hosters sometimes point Ticktz at an
# external MySQL that is slower to come up.
wait_for_database() {
    tries=0

    # Read once rather than per attempt: these do not change while we wait, and
    # sixty PHP startups to learn the same four values is sixty too many.
    db_host=$(setting DB_HOST 127.0.0.1)
    db_port=$(setting DB_PORT 3306)
    db_user=$(setting DB_USERNAME)
    db_pass=$(setting DB_PASSWORD)

    until php -r '
        try { new PDO(sprintf("mysql:host=%s;port=%s", $argv[1], $argv[2]), $argv[3], $argv[4]); exit(0); }
        catch (Throwable $e) { exit(1); }
    ' -- "$db_host" "$db_port" "$db_user" "$db_pass" 2>/dev/null; do
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

# The application key is handled by ticktz-place-code, under the same lock that
# guards the code, because two containers generating different keys is worse
# than either generating none: APP_KEY encrypts the mailbox passwords in the
# database, so a second key makes the first one's data unreadable.
#
# This used to live here, guarded by `[ -f .env ]`, and that guard was only
# ever satisfied by accident — the image carried a `.env` copied out of
# whoever's working directory built it. Removing that (correctly) left nothing
# to generate a key, and the instance came up with MissingAppKeyException and
# an empty 500.

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
