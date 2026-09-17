#!/usr/bin/env bash
#
# Backs up everything a Ticktz instance cannot be rebuilt without.
#
#   scripts/backup.sh [destination-directory]
#
# Three things go in the archive:
#
#   database.sql.gz   every table, as a consistent snapshot
#   storage.tar.gz    attachments, logs and generated files
#   .env              the configuration, including APP_KEY
#
# APP_KEY is in there for a reason: it decrypts the mailbox and directory
# passwords stored in the database. A database backup restored without the
# matching key leaves an instance that starts, shows every ticket, and cannot
# read a single mailbox — with no error that says why. They travel together.
#
# Which also means the archive is as sensitive as the instance: it contains
# credentials in a form that works. Store it where you would store the
# production .env, not next to the public assets.
#
set -euo pipefail

cd "$(dirname "$0")/.."

DESTINATION="${1:-backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK="${DESTINATION}/ticktz-${STAMP}"
COMPOSE="${TICKTZ_COMPOSE:-docker compose -f docker-compose.prod.yml}"

if [[ ! -f .env ]]; then
    echo "No .env in $(pwd) — run this from the directory you deploy from." >&2
    exit 1
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

: "${DB_DATABASE:?DB_DATABASE is not set in .env}"
: "${DB_USERNAME:?DB_USERNAME is not set in .env}"
: "${DB_PASSWORD:?DB_PASSWORD is not set in .env}"

mkdir -p "${WORK}"

echo "==> Dumping ${DB_DATABASE}"
# --single-transaction keeps the dump consistent without locking the desk for
# the duration; every table is InnoDB, so it costs nothing.
# --routines and --events because a dump that silently drops them restores an
# instance that looks complete and is not.
${COMPOSE} exec -T mysql mysqldump \
    --user="${DB_USERNAME}" \
    --password="${DB_PASSWORD}" \
    --single-transaction \
    --quick \
    --routines \
    --events \
    --default-character-set=utf8mb4 \
    "${DB_DATABASE}" | gzip -9 > "${WORK}/database.sql.gz"

echo "==> Archiving storage"
# Straight out of the container, so it works the same whether storage is a
# named volume or a bind mount. Caches and compiled views are rebuilt on boot
# and only make the archive bigger.
${COMPOSE} exec -T app tar \
    --exclude='./framework/cache/*' \
    --exclude='./framework/sessions/*' \
    --exclude='./framework/views/*' \
    -czf - -C /var/www/html/storage . > "${WORK}/storage.tar.gz"

echo "==> Copying .env"
cp .env "${WORK}/.env"
chmod 600 "${WORK}/.env"

echo "==> Recording what this is"
cat > "${WORK}/MANIFEST" <<MANIFEST
Ticktz backup
taken:     ${STAMP}
database:  ${DB_DATABASE}
version:   $(${COMPOSE} exec -T app php artisan --version 2>/dev/null || echo 'unknown')
migration: $(${COMPOSE} exec -T app php artisan migrate:status --no-ansi 2>/dev/null | tail -n 1 || echo 'unknown')

Restore with:  scripts/restore.sh ${WORK}

Contains credentials. Treat it like the .env file it holds.
MANIFEST

chmod 700 "${WORK}"

echo
echo "Backup written to ${WORK}"
du -sh "${WORK}"
