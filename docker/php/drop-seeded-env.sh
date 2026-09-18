# Take the seeded settings back out of the environment.
#
# Sourced by the entrypoint, after the placement script has written them into
# the instance's `.env`. Its own file so that it can be sourced by a test with
# a made-up environment — see tests/Shell/drop-seeded-env.test.sh.
#
# Why it has to happen at all: Laravel's dotenv is immutable, so a variable
# already in the process environment is never replaced by `.env`. The compose
# file delivers these as `TICKTZ_DEFAULT_<KEY>` precisely so that they are not
# in the environment under the name the application reads — but `env_file`
# hands the operator's whole `.env` beside the compose file to this container
# as well, and that file contains `DB_PASSWORD` and may contain any of the
# others. Arriving by that route they are back in the environment, outranking
# the file the application writes about itself, and the setup wizard cannot
# point the instance at a database of its own.
#
# The rule is narrow on purpose: only a key the stack also supplies a default
# for is dropped. Anything else the operator put in their `.env` — MAIL_HOST,
# the queue names, their own additions — reaches the container exactly as
# before. What is dropped is not lost either: the placement script has already
# written it into the instance's `.env`, where it is now the starting value
# rather than the final word.
drop_seeded_from_environment() {
    seeded=$(env | sed -n 's/^TICKTZ_DEFAULT_\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1/p')

    for key in $seeded; do
        unset "$key"
        unset "TICKTZ_DEFAULT_$key"
    done

    unset seeded key
}

drop_seeded_from_environment
