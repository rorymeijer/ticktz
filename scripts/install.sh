#!/bin/sh
# ---------------------------------------------------------------------------
# Ticktz — bring up the production stack.
#
#   ./scripts/install.sh
#
# Writes the two passwords the bundled MySQL needs, starts the stack, and
# leaves the rest to the setup wizard in the browser.
#
# They are named TICKTZ_DB_PASSWORD and TICKTZ_DB_ROOT_PASSWORD rather than
# DB_PASSWORD, and the prefix is the point: everything in this file is handed
# to the container as an environment variable, and Laravel never lets `.env`
# replace one of those. Under the plain name, the bundled password would
# outrank whatever the setup wizard writes — including the credentials of a
# database of your own.
#
# Why there is anything to write at all: the MySQL container is handed its
# password at the moment it is *created*, which is before any application
# exists to ask for one. Everything else a Ticktz instance needs — its name,
# its URL, the administrator, outgoing mail — the wizard asks for and writes
# itself, so this script deliberately writes nothing else. A value sitting in
# `.env` is passed into the container as an environment variable, and Laravel
# leaves environment variables that already exist alone: anything planted here
# would quietly outrank what the wizard writes later, and the setting would
# appear to change in the screen while not changing at all.
#
# Safe to run twice. It never replaces a value that is already there.
# ---------------------------------------------------------------------------
set -eu

APP_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ENV_FILE="$APP_ROOT/.env"
COMPOSE_FILE="$APP_ROOT/docker-compose.prod.yml"
START=true

usage() {
    cat <<'USAGE'
Usage: scripts/install.sh [--no-start]

  --no-start   Write the missing secrets and stop, without starting anything.
               For a host where you bring the stack up yourself.
  -h, --help   This.
USAGE
}

for arg in "$@"; do
    case "$arg" in
        --no-start) START=false ;;
        -h|--help) usage; exit 0 ;;
        *) echo "install.sh: unknown option: $arg" >&2; usage >&2; exit 2 ;;
    esac
done

# Hex rather than anything prettier. A password is about to be written into a
# file that is parsed line by line and passed through a shell: a `#` starts a
# comment halfway through it, a space truncates it, and a quote does something
# different again in each of the several places this value is read. None of
# those fail loudly — they produce an instance that cannot reach its own
# database and blames the credentials.
random_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 24
        return
    fi

    if [ -r /dev/urandom ]; then
        od -An -tx1 -N24 /dev/urandom | tr -d ' \n'
        echo
        return
    fi

    echo "install.sh: no openssl and no /dev/urandom, so no way to generate a" >&2
    echo "            password worth having. Put TICKTZ_DB_PASSWORD and" >&2
    echo "            TICKTZ_DB_ROOT_PASSWORD" >&2
    echo "            in ${ENV_FILE} yourself." >&2
    exit 1
}

# Present means present *with a value*. `DB_PASSWORD=` is what a copied
# `.env.example` leaves behind, and treating that as set is how an instance
# ends up with an empty database password.
has_value() {
    grep -q "^$1=..*" "$ENV_FILE" 2>/dev/null
}

# $1 is the name to write. $2 is the name instances installed before 1.1.7
# used, and finding *that* one set counts as set: the bundled MySQL was
# initialised with it, and adding a second, different password under the new
# name would lock the application out of the data directory it already has.
set_secret() {
    key=$1
    legacy=${2:-}
    value=$(random_secret)

    if has_value "$key"; then
        echo "  $key is already set, leaving it alone"
        return
    fi

    if [ -n "$legacy" ] && has_value "$legacy"; then
        echo "  $legacy is set, leaving it alone — it is what your database was created with"
        return
    fi

    if grep -q "^$key=" "$ENV_FILE" 2>/dev/null; then
        # The key is there but empty. Replace that line and nothing else —
        # awk rather than sed -i, which is not the same program on macOS.
        tmp="$ENV_FILE.install.$$"
        awk -v k="$key" -v v="$value" \
            'index($0, k "=") == 1 { print k "=" v; next } { print }' \
            "$ENV_FILE" > "$tmp"
        mv "$tmp" "$ENV_FILE"
        echo "  $key generated"
        return
    fi

    printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    echo "  $key generated"
}

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "install.sh: no docker-compose.prod.yml next to this script." >&2
    echo "            Run it from a Ticktz checkout." >&2
    exit 1
fi

if [ ! -e "$ENV_FILE" ]; then
    cat > "$ENV_FILE" <<'HEADER'
# Ticktz. Written by scripts/install.sh.
#
# Only what has to exist before the application does. Everything else is asked
# for by the setup wizard and written by it — adding settings here overrides
# what the wizard writes, which is rarely what anybody wants.
#
# Back this file up. TICKTZ_DB_PASSWORD is the database; APP_KEY, once
# generated, encrypts the mailbox passwords stored in it.
HEADER
    # Set here, where this script owns the file, and deliberately nowhere
    # else. An operator who made an existing `.env` group-readable — 0640, so
    # a separate deployment account can run Compose — has a reason for that,
    # and tightening it under them breaks that account's next start.
    chmod 600 "$ENV_FILE"
    echo "Created ${ENV_FILE}"
else
    echo "Using the ${ENV_FILE} that is already here"
fi

set_secret TICKTZ_DB_PASSWORD DB_PASSWORD
set_secret TICKTZ_DB_ROOT_PASSWORD DB_ROOT_PASSWORD

if [ "$START" != "true" ]; then
    echo
    echo "Not starting anything, as asked. When you are ready:"
    echo "  docker compose -f docker-compose.prod.yml up -d --build --wait"
    exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
    echo
    echo "install.sh: docker is not on this machine's PATH, so the stack cannot" >&2
    echo "            be started. The secrets are written; install Docker and run" >&2
    echo "            this again, or start it yourself." >&2
    exit 1
fi

echo
echo "Starting the stack. The first run builds the image, so give it a while."

# --wait, because `up -d` returns when the containers have *started*, and the
# first boot is still placing code and waiting for MySQL well after that. A URL
# offered before then is a URL that answers 502.
cd "$APP_ROOT"
docker compose -f "$COMPOSE_FILE" up -d --build --wait

port=$(grep '^TICKTZ_HTTP_PORT=' "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2-)
[ -n "${port:-}" ] || port=80
[ "$port" = "80" ] && url="http://<this-host>" || url="http://<this-host>:$port"

cat <<DONE

Ticktz is up. Open ${url} and the setup wizard takes it from there:
it checks the server, asks where the database should live — choose the
built-in one, the fields are already filled in — names your service desk and
creates your administrator.

Two things worth doing afterwards:

  * Back up ${ENV_FILE}, and add the APP_KEY the first boot generated:
      docker compose -f docker-compose.prod.yml exec -T app \\
        php artisan tinker --execute="echo config('app.key');"
    It encrypts the mailbox passwords in your database. Losing it makes them
    unreadable, with no error that says why.

  * Put a reverse proxy in front for TLS. This stack does not terminate it.
    See docs/self-hosting.md.
DONE
