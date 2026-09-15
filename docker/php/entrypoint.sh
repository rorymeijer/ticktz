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
