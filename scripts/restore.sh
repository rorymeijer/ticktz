#!/usr/bin/env bash
#
# Restores a Ticktz instance from an archive made by scripts/backup.sh.
#
#   scripts/restore.sh backups/ticktz-20260917T084500Z
#
# This overwrites the database. It asks first, and it says what it is about to
# destroy, because the moment you need this script is the moment you are least
# able to afford a second mistake.
#
# It does not overwrite .env. The archived copy is placed beside it as
# .env.restored so you can diff the two: the new host usually has a different
# APP_URL, different mail settings and different TLS, and silently replacing a
# working .env with a backup of somebody else's is a worse failure than the one
# you are recovering from. The one line you must carry across is APP_KEY.
#
set -euo pipefail

cd "$(dirname "$0")/.."

ARCHIVE="${1:-}"
COMPOSE="${TICKTZ_COMPOSE:-docker compose -f docker-compose.prod.yml}"

if [[ -z "${ARCHIVE}" || ! -d "${ARCHIVE}" ]]; then
    echo "Usage: scripts/restore.sh <backup-directory>" >&2
    exit 1
fi

for required in database.sql.gz storage.tar.gz; do
    if [[ ! -f "${ARCHIVE}/${required}" ]]; then
        echo "Not a Ticktz backup: ${ARCHIVE}/${required} is missing." >&2
        exit 1
    fi
done

if [[ ! -f .env ]]; then
    echo "No .env in $(pwd). Copy ${ARCHIVE}/.env into place, review it, then run this again." >&2
    exit 1
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

: "${DB_DATABASE:?DB_DATABASE is not set in .env}"
: "${DB_USERNAME:?DB_USERNAME is not set in .env}"
: "${DB_PASSWORD:?DB_PASSWORD is not set in .env}"

if [[ -f "${ARCHIVE}/MANIFEST" ]]; then
    echo "---- ${ARCHIVE}/MANIFEST ----"
    cat "${ARCHIVE}/MANIFEST"
    echo "-----------------------------"
fi

# The key mismatch is worth catching before anything is overwritten rather
# than after: encrypted mailbox and directory passwords in the dump cannot be
# read with a different APP_KEY, and nothing about the running instance will
# say so.
if [[ -f "${ARCHIVE}/.env" ]]; then
    ARCHIVED_KEY="$(grep -E '^APP_KEY=' "${ARCHIVE}/.env" | head -n1 | cut -d= -f2- || true)"

    if [[ -n "${ARCHIVED_KEY}" && "${ARCHIVED_KEY}" != "${APP_KEY:-}" ]]; then
        echo
        echo "WARNING: APP_KEY differs from the one in the backup."
        echo "  Encrypted settings (mailbox and directory passwords) will not decrypt."
        echo "  Copy APP_KEY out of ${ARCHIVE}/.env into .env before continuing."
        echo
    fi
fi

EXISTING="$(${COMPOSE} exec -T mysql mysql \
    --user="${DB_USERNAME}" --password="${DB_PASSWORD}" \
    --skip-column-names --batch \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}'" 2>/dev/null | tr -d '[:space:]' || echo 0)"

echo "About to replace database '${DB_DATABASE}' (currently ${EXISTING} tables) and the storage directory."
read -r -p "Type the database name to confirm: " CONFIRM

if [[ "${CONFIRM}" != "${DB_DATABASE}" ]]; then
    echo "Aborted; nothing was changed." >&2
    exit 1
fi

echo "==> Stopping the workers so nothing writes mid-restore"
${COMPOSE} stop worker >/dev/null 2>&1 || true

echo "==> Recreating the schema"
${COMPOSE} exec -T mysql mysql --user="${DB_USERNAME}" --password="${DB_PASSWORD}" \
    -e "DROP DATABASE IF EXISTS \`${DB_DATABASE}\`; CREATE DATABASE \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "==> Loading the dump"
gunzip -c "${ARCHIVE}/database.sql.gz" | ${COMPOSE} exec -T mysql mysql \
    --user="${DB_USERNAME}" --password="${DB_PASSWORD}" --default-character-set=utf8mb4 "${DB_DATABASE}"

echo "==> Restoring storage"
${COMPOSE} exec -T app tar -xzf - -C /var/www/html/storage < "${ARCHIVE}/storage.tar.gz"

if [[ -f "${ARCHIVE}/.env" && ! -f .env.restored ]]; then
    cp "${ARCHIVE}/.env" .env.restored
    chmod 600 .env.restored
    echo "==> Archived configuration placed at .env.restored (not applied) — diff it against .env"
fi

echo "==> Applying any migrations the running version needs"
${COMPOSE} exec -T app php artisan migrate --force

echo "==> Clearing caches"
${COMPOSE} exec -T app php artisan optimize:clear

echo "==> Starting the workers"
${COMPOSE} start worker >/dev/null

echo
echo "Restored from ${ARCHIVE}."
${COMPOSE} exec -T app php artisan about --only=environment || true
