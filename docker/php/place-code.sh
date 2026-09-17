#!/bin/sh
# ---------------------------------------------------------------------------
# Put the image's application code into place, or deliberately leave it alone.
#
# The image carries its code at /usr/src/ticktz rather than at /var/www/html,
# so that the production stack can mount a volume at /var/www/html without the
# image losing the ability to update what is in it. A named volume is filled
# from the image only while it is still empty; after that the image's copy at
# the same path is shadowed and there would be no way to hand a newer version
# over. The second path is what keeps `docker compose pull` meaningful.
#
# That volume is shared by the app and the worker, which is what makes
# Administration → Updates possible at all: the worker does the upgrade, and
# the app has to be able to see what it did. A container's own writable layer
# is private to it and thrown away when it is recreated.
#
# Its own file rather than a block inside the entrypoint because it is the part
# with the decisions in it, and a decision nobody can run is a decision nobody
# has checked. See tests/Shell/place-code.test.sh.
# ---------------------------------------------------------------------------
set -e

IMAGE_TREE="${TICKTZ_IMAGE_TREE:-/usr/src/ticktz}"
IMAGE_VERSION_FILE="${TICKTZ_IMAGE_VERSION_FILE:-/usr/local/share/ticktz/image-version}"
APP_ROOT="${TICKTZ_APP_ROOT:-/var/www/html}"

# Written only by this script, and only when this script put the code there.
# Its absence is the signal that the directory belongs to somebody else.
PLACED_BY_IMAGE="$APP_ROOT/.ticktz-image"

# Only one container may place the code, and the others have to wait for it.
#
# The app and the worker start at the same time and mount the same volume, so
# without this they both run `rm -rf vendor && cp -a` over each other and
# php-fpm comes up on a half-copied tree. That surfaces as 502 from nginx, with
# nothing in any log that names the cause.
#
# `mkdir` is the lock because it is atomic on POSIX and works across containers
# sharing a volume, which `flock` does not reliably do.
LOCK="$APP_ROOT/.ticktz-placing"
LOCK_TIMEOUT="${TICKTZ_PLACE_TIMEOUT:-600}"

# What a release replaces, and therefore what a newer image replaces too.
#
# The same list as UpgradeSwap::owned() in the application, and a test asserts
# the two have not drifted: if a newer image replaced a different set of paths
# from the one a web upgrade replaces, the two ways of upgrading would leave
# different trees behind and only one of them would be the tested one.
OWNED="app bootstrap config database lang public resources routes scripts vendor docs docker artisan composer.json composer.lock build.json"

# Is $1 a newer version than $2? PHP rather than `sort -V`: PHP is already here,
# and busybox's support for -V is not something to find out about in production.
newer_than() {
    php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$1" "$2"
}

# Hand the instance's own directories to the user that serves the web.
#
# The entrypoint runs as root and php-fpm runs as www-data, so everything
# created here is root-owned unless it is given away. That matters because
# `storage` and `bootstrap/cache` are the two places the *application* writes
# to, and with /var/www/html empty in the image there is nothing for Docker to
# take ownership from: a fresh `storage` volume is created empty and owned by
# root.
#
# The symptom when this is missing is worth writing down, because it points
# nowhere near the cause. Redirects work and pages do not: Laravel compiles
# every Blade view into storage/framework/views on first render, so `GET /`
# answers 302 quite happily and the first page that renders anything returns
# 500 with an empty body. Nothing in the nginx or php-fpm log names a
# permission.
#
# Conditional, because `chown -R` over a desk with years of attachments in it
# is not something to do on every boot.
own_instance_directories() {
    [ "$(id -u)" = "0" ] || return 0

    for path in "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"; do
        [ -d "$path" ] || continue

        if [ "$(stat -c '%U' "$path" 2>/dev/null)" != "www-data" ]; then
            echo "ticktz: giving ${path} to www-data"
            chown -R www-data:www-data "$path" 2>/dev/null || true
        fi
    done
}

# Can this tree actually boot?
#
# Asked rather than assumed, because the marker is a claim and not a fact. A
# copy that died halfway leaves a tree that looks placed — the marker is there,
# the version matches — and cannot load its own autoloader. Without this the
# next start reads the marker, says there is nothing to do, and php-fpm crashes
# again on the same missing file. Forever.
#
# Requiring the autoloader is the cheap version of the real question, and it is
# the exact thing that failed: `vendor/autoload.php` pulls in every polyfill
# bootstrap at require time, so a vendor missing one fails here too.
tree_is_usable() {
    [ -f "$APP_ROOT/artisan" ] || return 1
    [ -f "$APP_ROOT/vendor/autoload.php" ] || return 1

    php -r 'require $argv[1];' "$APP_ROOT/vendor/autoload.php" >/dev/null 2>&1
}

copy_tree_from_image() {
    for path in $OWNED; do
        [ -e "$IMAGE_TREE/$path" ] || continue
        rm -rf "${APP_ROOT:?}/$path"
        cp -a "$IMAGE_TREE/$path" "$APP_ROOT/$path"
    done

    # storage and bootstrap/cache belong to the instance, not to the release:
    # created when missing and never replaced.
    mkdir -p "$APP_ROOT/storage/framework/cache" \
             "$APP_ROOT/storage/framework/sessions" \
             "$APP_ROOT/storage/framework/views" \
             "$APP_ROOT/storage/logs" \
             "$APP_ROOT/bootstrap/cache"
}

# Three cases, and the third is the one worth being careful about.
#
#   1. Nothing there yet — a fresh volume, or no volume at all. Fill it.
#   2. This script filled it before and the image is now newer, which is what
#      `docker compose pull && up -d` means. Replace the code.
#   3. Something is there that this script did not put there: a bind-mounted
#      source tree. Copying over it would delete work in progress, so it is
#      never touched, whatever the versions say.
#
# Upgrading from the browser falls out of case 2 rather than needing a case of
# its own: an upgrade writes a newer version into the volume, so the image is
# no longer the newer of the two and the code it installed stays.
# Take the lock, or wait for whoever holds it to finish.
#
# A lock older than the timeout is a container that died mid-copy; taking it
# over is better than never starting again, and the copy is idempotent.
acquire_lock() {
    waited=0

    while ! mkdir "$LOCK" 2>/dev/null; do
        if [ -n "$(find "$LOCK" -maxdepth 0 -mmin "+$((LOCK_TIMEOUT / 60 + 1))" 2>/dev/null)" ]; then
            echo "ticktz: taking over a stale placement lock"
            rm -rf "$LOCK"
            continue
        fi

        if [ "$waited" -eq 0 ]; then
            echo "ticktz: another container is placing the code, waiting"
        fi

        waited=$((waited + 1))

        if [ "$waited" -ge "$LOCK_TIMEOUT" ]; then
            echo "ticktz: gave up waiting for the placement lock after ${LOCK_TIMEOUT}s" >&2
            return 1
        fi

        sleep 1
    done

    printf '%s' "$$" > "$LOCK/pid" 2>/dev/null || true

    return 0
}

release_lock() {
    rm -rf "$LOCK"
}

place_code() {
    [ -d "$IMAGE_TREE" ] || return 0

    mkdir -p "$APP_ROOT"

    # Decide *after* taking the lock, never before: the container that waited
    # must re-ask the question, because the answer changed while it waited.
    acquire_lock || return 1
    trap release_lock EXIT INT TERM

    image_version="$(cat "$IMAGE_VERSION_FILE" 2>/dev/null || echo '0.0.0')"

    if [ ! -e "$APP_ROOT/artisan" ]; then
        echo "ticktz: installing ${image_version} into ${APP_ROOT}"
        place_and_verify "$image_version"
        release_lock
        return $?
    fi

    if [ ! -f "$PLACED_BY_IMAGE" ]; then
        # A tree this script did not write: mounted source. The one useful
        # thing here is filling an empty `vendor` volume, which is how the
        # development stack keeps the bind mount from hiding the image's
        # dependencies behind it.
        if [ ! -f "$APP_ROOT/vendor/autoload.php" ] && [ -d "$IMAGE_TREE/vendor" ]; then
            echo "ticktz: filling an empty vendor from the image"
            mkdir -p "$APP_ROOT/vendor"
            cp -a "$IMAGE_TREE/vendor/." "$APP_ROOT/vendor/"
        fi

        release_lock
        return 0
    fi

    placed_version="$(cat "$PLACED_BY_IMAGE" 2>/dev/null || echo '0.0.0')"

    if newer_than "$image_version" "$placed_version"; then
        echo "ticktz: image ${image_version} is newer than ${placed_version}, replacing the code"
        place_and_verify "$image_version"
        release_lock
        return $?
    fi

    # Same version, or the volume is ahead. Keep it — but only if it works.
    # A tree that cannot load its own autoloader is not worth keeping, whatever
    # its marker says, and putting the image's copy back is the only move that
    # can get this instance running again without somebody logging in.
    if tree_is_usable; then
        echo "ticktz: keeping the code already in ${APP_ROOT} (${placed_version})"
        release_lock
        return 0
    fi

    echo "ticktz: the code in ${APP_ROOT} is damaged, replacing it with ${image_version} from the image" >&2
    place_and_verify "$image_version"
    release_lock
    return $?
}

# Copy, check the result, and only then say the image put it there.
#
# The marker is what every later start trusts, so it is written last and only
# on success. A failure here stops the container with a message naming the
# cause, which is worth more than a crash loop that repeats a stack trace about
# a missing polyfill.
place_and_verify() {
    copy_tree_from_image

    if ! tree_is_usable; then
        rm -f "$PLACED_BY_IMAGE"
        echo "ticktz: the code copied out of the image will not load. Not marking it as placed." >&2
        return 1
    fi

    printf '%s' "$1" > "$PLACED_BY_IMAGE"

    return 0
}

# Make sure this instance has an APP_KEY, and that it is the same one next time.
#
# Under the placement lock, because two containers generating different keys is
# worse than either of them generating none: APP_KEY encrypts the mailbox
# passwords in the database, so a second key makes the first one's data
# unreadable.
#
# Only a fallback. An operator who sets APP_KEY in their own `.env` — which
# compose injects into the environment — never reaches this, and that is the
# arrangement to prefer: a key in the operator's file is backed up with their
# other settings, while this one lives in the code volume and goes with it.
ensure_app_key() {
    [ -z "${APP_KEY:-}" ] || return 0

    env_file="$APP_ROOT/.env"

    if [ -f "$env_file" ] && grep -q '^APP_KEY=base64:' "$env_file"; then
        return 0
    fi

    acquire_lock || return 0

    # Asked again now that nothing else is writing: another container may have
    # generated one while this one waited.
    if [ ! -f "$env_file" ] || ! grep -q '^APP_KEY=base64:' "$env_file"; then
        echo "ticktz: no APP_KEY in the environment, generating one in ${env_file}"
        echo "ticktz: back it up — it encrypts the mailbox passwords in your database"

        [ -f "$env_file" ] || printf 'APP_KEY=\n' > "$env_file"
        grep -q '^APP_KEY=' "$env_file" || printf 'APP_KEY=\n' >> "$env_file"

        (cd "$APP_ROOT" && php artisan key:generate --force --no-interaction) || true
        chown www-data:www-data "$env_file" 2>/dev/null || true
        chmod 640 "$env_file" 2>/dev/null || true
    fi

    release_lock
}

place_code
own_instance_directories
ensure_app_key
