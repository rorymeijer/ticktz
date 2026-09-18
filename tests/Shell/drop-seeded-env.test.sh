#!/bin/sh
# ---------------------------------------------------------------------------
# Exercises docker/php/drop-seeded-env.sh.
#
# It is three lines of shell, and it is the hinge the whole arrangement turns
# on. The compose file delivers the stack's settings as TICKTZ_DEFAULT_<KEY> so
# that they are not in the environment under the name the application reads —
# but `env_file` hands the operator's own `.env` to the container as well, and
# that file has DB_PASSWORD in it. Arriving by that route they are back in the
# environment, where Laravel's immutable dotenv means `.env` can never replace
# them, and the setup wizard cannot point the instance at a database of its own.
#
# What has to stay true: only a key the stack supplies a default for is
# dropped, and everything else the operator put in their `.env` is untouched.
#
#   sh tests/Shell/drop-seeded-env.test.sh
# ---------------------------------------------------------------------------
set -e

SCRIPT="$(cd "$(dirname "$0")/../.." && pwd)/docker/php/drop-seeded-env.sh"

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

# Sourced in a subshell with a made-up environment, reporting what survived.
# A subshell per case because the point of the script is that it changes the
# shell it is sourced into, and cases must not inherit each other's damage.
after() {
    (
        # A production container, as compose leaves it: the stack's defaults
        # under their prefix, and the operator's own `.env` injected whole by
        # `env_file` — including the plain names that do the shadowing.
        TICKTZ_DEFAULT_DB_CONNECTION=mysql
        TICKTZ_DEFAULT_DB_HOST=mysql
        TICKTZ_DEFAULT_DB_PASSWORD=from-the-compose-file
        TICKTZ_DEFAULT_QUEUE_CONNECTION=redis
        DB_HOST=mysql
        DB_PASSWORD=from-the-operators-env-file
        QUEUE_CONNECTION=redis
        MAIL_HOST=smtp.example.org
        TICKTZ_QUEUE_WORKERS=4
        APP_ENV=production
        export TICKTZ_DEFAULT_DB_CONNECTION TICKTZ_DEFAULT_DB_HOST \
               TICKTZ_DEFAULT_DB_PASSWORD TICKTZ_DEFAULT_QUEUE_CONNECTION \
               DB_HOST DB_PASSWORD QUEUE_CONNECTION MAIL_HOST \
               TICKTZ_QUEUE_WORKERS APP_ENV

        . "$SCRIPT"

        printenv "$1" || echo "GONE"
    )
}

echo
echo "drop-seeded-env.sh"

# --- What must go -----------------------------------------------------------
check "drops a shadowing DB_PASSWORD" "$(after DB_PASSWORD)" "GONE"
check "drops a shadowing DB_HOST" "$(after DB_HOST)" "GONE"
check "drops a shadowing QUEUE_CONNECTION" "$(after QUEUE_CONNECTION)" "GONE"

# A key the stack defaults but the operator never set is in the environment
# only under the prefix. It goes too, so nothing is left to shadow with.
check "drops a default the operator never set" "$(after DB_CONNECTION)" "GONE"

# And the prefixed ones, which have done their job by now and would otherwise
# be visible in `printenv` for the life of the container.
check "drops the prefixed copies" "$(after TICKTZ_DEFAULT_DB_HOST)" "GONE"
check "including the password" "$(after TICKTZ_DEFAULT_DB_PASSWORD)" "GONE"

# --- What must stay ---------------------------------------------------------
# The rule is narrow on purpose. An operator's `.env` holds settings this stack
# supplies no default for, and dropping those would be taking away their
# configuration rather than un-shadowing ours.
check "keeps a setting the stack has no default for" "$(after MAIL_HOST)" "smtp.example.org"
check "keeps the stack's own tuning" "$(after TICKTZ_QUEUE_WORKERS)" "4"

# APP_ENV is deliberately an environment variable and deliberately not seeded:
# this stack is production whatever a file says. Dropping it would let a `.env`
# left over from a developer's copy turn debug output back on.
check "keeps APP_ENV" "$(after APP_ENV)" "production"

# --- Nothing to do ----------------------------------------------------------
# Outside the production stack there are no defaults, and this must be a no-op
# rather than an error under `set -e`.
unprefixed=$(
    DB_HOST=untouched
    export DB_HOST
    . "$SCRIPT"
    printenv DB_HOST || echo GONE
)
check "leaves everything alone when the stack supplies no defaults" "$unprefixed" "untouched"

echo
if [ "$failures" -gt 0 ]; then
    printf '%s of %s checks failed\n\n' "$failures" "$checks"
    exit 1
fi

printf '%s checks passed\n\n' "$checks"
