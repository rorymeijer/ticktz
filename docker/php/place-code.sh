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
place_code() {
    [ -d "$IMAGE_TREE" ] || return 0

    mkdir -p "$APP_ROOT"

    image_version="$(cat "$IMAGE_VERSION_FILE" 2>/dev/null || echo '0.0.0')"

    if [ ! -e "$APP_ROOT/artisan" ]; then
        echo "ticktz: installing ${image_version} into ${APP_ROOT}"
        copy_tree_from_image
        printf '%s' "$image_version" > "$PLACED_BY_IMAGE"
        return 0
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

        return 0
    fi

    placed_version="$(cat "$PLACED_BY_IMAGE" 2>/dev/null || echo '0.0.0')"

    if newer_than "$image_version" "$placed_version"; then
        echo "ticktz: image ${image_version} is newer than ${placed_version}, replacing the code"
        copy_tree_from_image
        printf '%s' "$image_version" > "$PLACED_BY_IMAGE"
    else
        echo "ticktz: keeping the code already in ${APP_ROOT} (${placed_version})"
    fi
}

place_code
