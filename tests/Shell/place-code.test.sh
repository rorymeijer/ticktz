#!/bin/sh
# ---------------------------------------------------------------------------
# Exercises docker/php/place-code.sh against real directories.
#
# The script decides whether the image's code replaces what is in
# /var/www/html, and the expensive way to find out it is wrong is a deployment
# where `docker compose pull` quietly changes nothing — or worse, one where a
# developer's bind-mounted source is overwritten by the image. Both are cheap
# to check here.
#
#   sh tests/Shell/place-code.test.sh
# ---------------------------------------------------------------------------
set -e

SCRIPT="$(cd "$(dirname "$0")/../.." && pwd)/docker/php/place-code.sh"
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

# An image tree at $1 holding version $2.
make_image() {
    rm -rf "$1"
    mkdir -p "$1/app" "$1/vendor" "$1/config"
    printf 'from %s' "$2" > "$1/app/Marker.php"
    printf '<?php' > "$1/vendor/autoload.php"
    printf '#!/usr/bin/env php' > "$1/artisan"
    printf '%s' "$2" > "$WORK/image-version"
}

run_place() {
    TICKTZ_IMAGE_TREE="$1" \
    TICKTZ_IMAGE_VERSION_FILE="$WORK/image-version" \
    TICKTZ_APP_ROOT="$2" \
    sh "$SCRIPT" > "$WORK/out" 2>&1
}

echo "place-code.sh"

# --- 1. A fresh volume is filled from the image ----------------------------
make_image "$WORK/image" 1.1.0
rm -rf "$WORK/root"; mkdir -p "$WORK/root"
run_place "$WORK/image" "$WORK/root"

check "fills an empty root" "$(cat "$WORK/root/app/Marker.php")" "from 1.1.0"
check "records which image filled it" "$(cat "$WORK/root/.ticktz-image")" "1.1.0"
check "creates storage for the instance" "$([ -d "$WORK/root/storage/logs" ] && echo yes)" "yes"

# --- 2. A newer image replaces the code (docker compose pull) --------------
make_image "$WORK/image" 1.2.0
run_place "$WORK/image" "$WORK/root"

check "a newer image replaces the code" "$(cat "$WORK/root/app/Marker.php")" "from 1.2.0"
check "and records the new version" "$(cat "$WORK/root/.ticktz-image")" "1.2.0"

# --- 3. An older image leaves a browser upgrade alone ----------------------
# This is the case the whole feature depends on: an upgrade installed from the
# browser writes a newer version into the volume, and the next container start
# must not put the image's older code back over it.
printf 'installed from the browser' > "$WORK/root/app/Marker.php"
printf '1.4.0' > "$WORK/root/.ticktz-image"
make_image "$WORK/image" 1.2.0
run_place "$WORK/image" "$WORK/root"

check "an older image leaves the code alone" "$(cat "$WORK/root/app/Marker.php")" "installed from the browser"
check "and does not rewrite the marker" "$(cat "$WORK/root/.ticktz-image")" "1.4.0"

# --- 4. The same version is left alone -------------------------------------
make_image "$WORK/image" 1.4.0
printf 'untouched' > "$WORK/root/app/Marker.php"
run_place "$WORK/image" "$WORK/root"

check "an equal version is left alone" "$(cat "$WORK/root/app/Marker.php")" "untouched"

# --- 5. A bind-mounted source tree is never touched ------------------------
# No marker, because this script did not put the code there. A developer's
# working tree, in other words, and copying over it would delete their work.
rm -rf "$WORK/source"; mkdir -p "$WORK/source/app" "$WORK/source/vendor"
printf 'work in progress' > "$WORK/source/app/Marker.php"
printf '<?php' > "$WORK/source/vendor/autoload.php"
printf '#!/usr/bin/env php' > "$WORK/source/artisan"
make_image "$WORK/image" 9.9.9
run_place "$WORK/image" "$WORK/source"

check "never overwrites a mounted source tree" "$(cat "$WORK/source/app/Marker.php")" "work in progress"
check "and leaves no marker behind" "$([ -f "$WORK/source/.ticktz-image" ] && echo yes || echo no)" "no"

# --- 6. An empty vendor volume over a source tree is filled ----------------
# What the development stack needs: the bind mount hides the image's vendor,
# and the named volume over it starts out empty.
rm -rf "$WORK/source/vendor"; mkdir -p "$WORK/source/vendor"
run_place "$WORK/image" "$WORK/source"

check "fills an empty vendor volume" "$([ -f "$WORK/source/vendor/autoload.php" ] && echo yes || echo no)" "yes"
check "and still does not touch the source" "$(cat "$WORK/source/app/Marker.php")" "work in progress"

echo
if [ "$failures" -gt 0 ]; then
    echo "$failures of $checks checks failed"
    exit 1
fi

echo "$checks checks passed"
