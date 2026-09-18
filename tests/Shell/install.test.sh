#!/bin/sh
# ---------------------------------------------------------------------------
# Exercises scripts/install.sh against real .env files.
#
# The script's whole job is to write two secrets into a file it does not own,
# and the ways that goes wrong are all quiet: a password overwritten on a
# second run points a working instance at a database it can no longer reach,
# and an empty `DB_PASSWORD=` left alone — the line a copied `.env.example`
# leaves behind — starts MySQL with no password at all.
#
# Everything here runs with --no-start, so nothing touches Docker.
#
#   sh tests/Shell/install.test.sh
# ---------------------------------------------------------------------------
set -e

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0
checks=0

check() {
    checks=$((checks + 1))
    if [ "$2" = "$3" ]; then
        printf '  ok   %s\n' "$1"
    else
        printf '  FAIL %s\n       expected [%s]\n       actual   [%s]\n' "$1" "$3" "$2"
        failures=$((failures + 1))
    fi
}

# A checkout, in miniature: the script insists on finding the compose file
# beside itself, so the fake one needs the same shape.
make_checkout() {
    rm -rf "$1"
    mkdir -p "$1/scripts"
    cp "$ROOT/scripts/install.sh" "$1/scripts/install.sh"
    chmod +x "$1/scripts/install.sh"
    touch "$1/docker-compose.prod.yml"
}

run_install() {
    ( cd "$1" && sh scripts/install.sh --no-start >"$WORK/out" 2>&1 ) && echo ok || echo failed
}

value_of() {
    grep "^$2=" "$1/.env" 2>/dev/null | head -1 | cut -d= -f2-
}

echo
echo "install.sh"

# --- A host with nothing on it --------------------------------------------
C="$WORK/fresh"
make_checkout "$C"
check "runs on a checkout with no .env" "$(run_install "$C")" "ok"
check "creates the .env" "$([ -f "$C/.env" ] && echo yes || echo no)" "yes"

pw=$(value_of "$C" DB_PASSWORD)
root=$(value_of "$C" DB_ROOT_PASSWORD)
check "generates a database password" "$([ -n "$pw" ] && echo yes || echo no)" "yes"
check "generates a root password" "$([ -n "$root" ] && echo yes || echo no)" "yes"
check "the two differ" "$([ "$pw" != "$root" ] && echo yes || echo no)" "yes"
check "long enough to be worth having" "$([ "${#pw}" -ge 32 ] && echo yes || echo no)" "yes"

# A password is read back by a shell, a YAML parser and a line-based .env
# parser before it reaches MySQL. Hex survives all three; most other things
# survive two of them.
check "no characters that a .env parser treats as syntax" \
    "$(printf '%s' "$pw" | tr -d '0-9a-f' | wc -c | tr -d ' ')" "0"

# --- The stack has to be able to start from it ----------------------------
# The only reason those two values exist is that compose refuses to resolve
# without them. Proving they satisfy it is the point of the whole script.
if command -v docker >/dev/null 2>&1 && command -v php >/dev/null 2>&1; then
    cp "$ROOT/docker-compose.prod.yml" "$C/docker-compose.prod.yml"
    mkdir -p "$C/docker/nginx" "$C/docker/mysql"
    touch "$C/docker/nginx/default.conf" "$C/docker/mysql/ticktz.cnf"

    ( cd "$C" && docker compose -f docker-compose.prod.yml config --format json ) \
        > "$WORK/resolved.json" 2>/dev/null || : > "$WORK/resolved.json"

    # Asked of the resolved services rather than grepped out of the YAML: what
    # matters is the value each container is handed, and a count of matching
    # lines answers a different question that happens to look the same.
    env_of() {
        php -r '
            $c = json_decode(file_get_contents($argv[1]), true);
            echo $c["services"][$argv[2]]["environment"][$argv[3]] ?? "MISSING";
        ' "$WORK/resolved.json" "$1" "$2" 2>/dev/null
    }

    check "the two secrets satisfy the real compose file" \
        "$(env_of mysql MYSQL_PASSWORD)" "$pw"

    # Left to Laravel's own fallbacks a production instance comes up on sqlite
    # with a synchronous queue, beside a MySQL and a Redis it never speaks to.
    # The stack supplies them — as defaults for the instance's own `.env`
    # rather than as environment variables, so that the setup wizard can still
    # change them. The entrypoint strips the prefix; see place-code.sh, and
    # drop-seeded-env.sh for the other half.
    for service in app worker; do
        check "$service is told which database to start with" \
            "$(env_of "$service" TICKTZ_DEFAULT_DB_CONNECTION)" "mysql"
        check "$service is told the database beside it" \
            "$(env_of "$service" TICKTZ_DEFAULT_DB_HOST)" "mysql"
        check "$service is told the generated password" \
            "$(env_of "$service" TICKTZ_DEFAULT_DB_PASSWORD)" "$pw"
        check "$service is told to keep sessions in redis" \
            "$(env_of "$service" TICKTZ_DEFAULT_SESSION_DRIVER)" "redis"
        check "$service is told to queue to redis" \
            "$(env_of "$service" TICKTZ_DEFAULT_QUEUE_CONNECTION)" "redis"
        check "$service is told to cache in redis" \
            "$(env_of "$service" TICKTZ_DEFAULT_CACHE_STORE)" "redis"

        # And under no name the application reads. The exception is the
        # operator's own `.env`, which `env_file` hands over whole — DB_PASSWORD
        # among it. That one the entrypoint removes at boot, because it cannot
        # be kept out of here without taking the rest of their file with it.
        for key in DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME \
                   REDIS_HOST SESSION_DRIVER CACHE_STORE QUEUE_CONNECTION; do
            check "$service is handed no $key by the compose file" \
                "$(env_of "$service" "$key")" "MISSING"
        done
    done

    check "and the image is one that exists" \
        "$(php -r '
            $c = json_decode(file_get_contents($argv[1]), true);
            echo $c["services"]["app"]["image"] ?? "MISSING";
        ' "$WORK/resolved.json" 2>/dev/null)" "ghcr.io/rorymeijer/ticktz:latest"
else
    echo "  --   docker or php not available, skipping the compose checks"
fi

# --- A second run ----------------------------------------------------------
check "runs again without complaint" "$(run_install "$C")" "ok"
check "does not change the database password" "$(value_of "$C" DB_PASSWORD)" "$pw"
check "does not change the root password" "$(value_of "$C" DB_ROOT_PASSWORD)" "$root"
check "says so rather than pretending it did something" \
    "$(grep -c 'already set' "$WORK/out" || true)" "2"

# --- A .env copied from the example ---------------------------------------
# `DB_PASSWORD=` with nothing after it is not a password, and the empty string
# is what MySQL would be started with if this were treated as set.
C="$WORK/copied"
make_checkout "$C"
printf '# a comment worth keeping\nAPP_NAME=Ticktz\nDB_PASSWORD=\nDB_ROOT_PASSWORD=\nMAIL_HOST=smtp.example.org\n' > "$C/.env"
check "runs on an .env from the example" "$(run_install "$C")" "ok"
check "fills in the empty database password" \
    "$([ -n "$(value_of "$C" DB_PASSWORD)" ] && echo yes || echo no)" "yes"
check "fills in the empty root password" \
    "$([ -n "$(value_of "$C" DB_ROOT_PASSWORD)" ] && echo yes || echo no)" "yes"
check "keeps the other settings" "$(value_of "$C" MAIL_HOST)" "smtp.example.org"
check "keeps the comments" "$(grep -c 'a comment worth keeping' "$C/.env")" "1"
check "adds no second DB_PASSWORD line" "$(grep -c '^DB_PASSWORD=' "$C/.env")" "1"

# --- A .env somebody has already set up ------------------------------------
C="$WORK/existing"
make_checkout "$C"
printf 'DB_PASSWORD=chosen-by-hand\nDB_ROOT_PASSWORD=also-by-hand\nAPP_URL=https://desk.example.org\n' > "$C/.env"
check "runs on a configured .env" "$(run_install "$C")" "ok"
check "never replaces a chosen password" "$(value_of "$C" DB_PASSWORD)" "chosen-by-hand"
check "never replaces a chosen root password" "$(value_of "$C" DB_ROOT_PASSWORD)" "also-by-hand"
check "leaves everything else alone" "$(value_of "$C" APP_URL)" "https://desk.example.org"

# --- What it refuses to write ----------------------------------------------
# A value here outranks what the setup wizard writes later, because Laravel
# does not overwrite an environment variable that already exists. The script
# writing an APP_URL would make that field in the wizard do nothing.
C="$WORK/minimal"
make_checkout "$C"
run_install "$C" >/dev/null
check "writes no APP_URL" "$(grep -c '^APP_URL=' "$C/.env" || true)" "0"
check "writes no APP_KEY" "$(grep -c '^APP_KEY=' "$C/.env" || true)" "0"
check "writes no DB_CONNECTION" "$(grep -c '^DB_CONNECTION=' "$C/.env" || true)" "0"
check "writes exactly the two secrets" "$(grep -c '^[A-Z_]*=' "$C/.env")" "2"
check "keeps the file it created to its owner" \
    "$(stat -c '%a' "$C/.env" 2>/dev/null || stat -f '%Lp' "$C/.env")" "600"

# An existing file's mode is the operator's business. 0640 is a deployment
# account being given read access on purpose, and tightening it under them
# breaks that account's next `docker compose up`.
C="$WORK/groupreadable"
make_checkout "$C"
printf 'DB_PASSWORD=chosen\n' > "$C/.env"
chmod 640 "$C/.env"
run_install "$C" >/dev/null
check "leaves an existing file's permissions alone" \
    "$(stat -c '%a' "$C/.env" 2>/dev/null || stat -f '%Lp' "$C/.env")" "640"

# --- Run from somewhere else ------------------------------------------------
# A deployment runs this by absolute path from wherever cron happens to be.
C="$WORK/elsewhere"
make_checkout "$C"
( cd / && sh "$C/scripts/install.sh" --no-start >/dev/null 2>&1 ) \
    && r=ok || r=failed
check "works when run by absolute path from elsewhere" "$r" "ok"
check "wrote into the checkout, not the working directory" \
    "$([ -f "$C/.env" ] && echo yes || echo no)" "yes"

# --- Not a checkout ---------------------------------------------------------
C="$WORK/notacheckout"
rm -rf "$C"; mkdir -p "$C/scripts"
cp "$ROOT/scripts/install.sh" "$C/scripts/install.sh"
check "refuses where there is no compose file" "$(run_install "$C")" "failed"
check "and writes no .env there" "$([ -f "$C/.env" ] && echo yes || echo no)" "no"

# --- Arguments --------------------------------------------------------------
C="$WORK/args"
make_checkout "$C"
( cd "$C" && sh scripts/install.sh --wat >/dev/null 2>&1 ) && r=ok || r=failed
check "rejects an option it does not know" "$r" "failed"
( cd "$C" && sh scripts/install.sh --help >/dev/null 2>&1 ) && r=ok || r=failed
check "explains itself when asked" "$r" "ok"

echo
if [ "$failures" -gt 0 ]; then
    printf '%s of %s checks failed\n\n' "$failures" "$checks"
    exit 1
fi

printf '%s checks passed\n\n' "$checks"
