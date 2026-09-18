#!/bin/sh
# ---------------------------------------------------------------------------
# Exercises docker/php/instance-setting.php.
#
# It answers "what is this instance's DB_HOST" for the entrypoint, and it has
# to answer it the way the application does — environment first, then `.env` —
# or the entrypoint waits for a database the application is not going to use.
#
# It exists because the production stack stopped delivering DB_* as environment
# variables, so that the setup wizard can change them. The entrypoint kept
# reading getenv() for a while during that change, which does not fail: it
# waits two minutes for 127.0.0.1 on every boot, logs one line about it, and
# carries on to migrate the right database anyway. Nobody would have looked
# twice.
#
#   sh tests/Shell/instance-setting.test.sh
# ---------------------------------------------------------------------------
set -e

SCRIPT="$(cd "$(dirname "$0")/../.." && pwd)/docker/php/instance-setting.php"
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

ask() {
    TICKTZ_APP_ROOT="$WORK/instance" php "$SCRIPT" "$@"
}

mkdir -p "$WORK/instance"

cat > "$WORK/instance/.env" <<'ENV'
# Written by the installer.
APP_NAME=Ticktz

DB_HOST=db.example.org
DB_PORT=3307
DB_USERNAME=ticktz
  DB_INDENTED=indented
DB_QUOTED="has a space and # a hash"
DB_SINGLE='single quoted'
DB_EMPTY=
DB_EQUALS=pa=ss=word
#DB_COMMENTED=never
ENV

echo
echo "instance-setting.php"

# --- Reading the file -------------------------------------------------------
check "reads a plain value" "$(ask DB_HOST)" "db.example.org"
check "reads a numeric one" "$(ask DB_PORT)" "3307"

# The installer quotes a value that needs it: a `#` would otherwise be a
# comment from that character on, and a space would truncate it. Both produce
# an instance that cannot reach its database and blames the credentials.
check "unwraps a double-quoted value" "$(ask DB_QUOTED)" "has a space and # a hash"
check "unwraps a single-quoted one" "$(ask DB_SINGLE)" "single quoted"
check "keeps every character after the first =" "$(ask DB_EQUALS)" "pa=ss=word"
check "tolerates a leading space on the line" "$(ask DB_INDENTED)" "indented"
check "ignores a commented-out key" "$(ask DB_COMMENTED fallback)" "fallback"

# --- Precedence -------------------------------------------------------------
# Laravel's dotenv is immutable: a variable already in the environment is never
# replaced by the file. Anything reading these has to agree, or it connects
# somewhere the application does not.
check "the environment outranks the file" \
    "$(TICKTZ_APP_ROOT="$WORK/instance" DB_HOST=from-the-environment php "$SCRIPT" DB_HOST)" \
    "from-the-environment"

# An empty environment variable is not a value — it is what an unset compose
# entry leaves behind — so the file still answers.
check "an empty environment variable does not count" \
    "$(TICKTZ_APP_ROOT="$WORK/instance" DB_HOST= php "$SCRIPT" DB_HOST)" \
    "db.example.org"

# --- When there is nothing to read ------------------------------------------
check "falls back when the key is absent" "$(ask DB_MISSING 3306)" "3306"
check "falls back when the key is empty" "$(ask DB_EMPTY 3306)" "3306"
check "answers nothing when there is no fallback" "$(ask DB_MISSING)" ""

check "falls back when there is no .env at all" \
    "$(TICKTZ_APP_ROOT="$WORK/nothing-here" php "$SCRIPT" DB_HOST 127.0.0.1)" "127.0.0.1"

# --- Misuse -----------------------------------------------------------------
TICKTZ_APP_ROOT="$WORK/instance" php "$SCRIPT" >/dev/null 2>&1 && r=ok || r=failed
check "refuses to be asked for nothing" "$r" "failed"

echo
if [ "$failures" -gt 0 ]; then
    printf '%s of %s checks failed\n\n' "$failures" "$checks"
    exit 1
fi

printf '%s checks passed\n\n' "$checks"
