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
    mkdir -p "$1/app" "$1/vendor/composer" "$1/config"
    printf 'from %s' "$2" > "$1/app/Marker.php"
    printf '#!/usr/bin/env php' > "$1/artisan"
    printf '%s' "$2" > "$WORK/image-version"

    # A real autoloader, in miniature: autoload.php requires a second file, the
    # way Composer's does. That is what makes a half-copied vendor detectable —
    # and a half-copied vendor is exactly what the missing polyfill bootstrap
    # was in the failure this guards against.
    printf '<?php require __DIR__ . "/composer/real.php";' > "$1/vendor/autoload.php"
    printf '<?php return true;' > "$1/vendor/composer/real.php"
}

# Never aborts the harness, even though the script runs under `set -e` and this
# file does too. A placement that dies is a result worth reporting as a failed
# check rather than a reason for the suite to vanish mid-run — which is what it
# did the first time the concurrency check below caught a real bug.
run_place() {
    TICKTZ_IMAGE_TREE="$1" \
    TICKTZ_IMAGE_VERSION_FILE="$WORK/image-version" \
    TICKTZ_APP_ROOT="$2" \
    APP_KEY="${APP_KEY-}" \
    sh "$SCRIPT" > "$WORK/out-$$" 2>&1 || true
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

# --- 7. Two containers starting at once do not corrupt the tree -------------
# The app and the worker mount the same volume and start together. Without a
# lock they both run `rm -rf vendor && cp -a` over each other, php-fpm comes up
# on a half-copied tree, and nginx answers 502 with nothing in any log naming
# the cause. This is that situation, with a tree big enough that the copy
# actually overlaps.
rm -rf "$WORK/image" "$WORK/race"; mkdir -p "$WORK/race"
make_image "$WORK/image" 1.1.0
i=0
while [ "$i" -lt 300 ]; do
    printf 'lots of vendor code %s' "$i" > "$WORK/image/vendor/file-$i.php"
    i=$((i + 1))
done
expected="$(find "$WORK/image/vendor" -type f | wc -l)"

run_place "$WORK/image" "$WORK/race" & first=$!
run_place "$WORK/image" "$WORK/race" & second=$!
wait "$first" || true
wait "$second" || true

check "two at once leave a complete vendor" "$(find "$WORK/race/vendor" -type f | wc -l)" "$expected"
check "two at once leave a usable tree" "$(cat "$WORK/race/app/Marker.php")" "from 1.1.0"
check "and the lock is released afterwards" "$([ -e "$WORK/race/.ticktz-placing" ] && echo held || echo free)" "free"

# --- 8. A lock left by a container that died is taken over ------------------
rm -rf "$WORK/stale"; mkdir -p "$WORK/stale/.ticktz-placing"
touch -d '2 hours ago' "$WORK/stale/.ticktz-placing"
TICKTZ_PLACE_TIMEOUT=60 run_place "$WORK/image" "$WORK/stale"

check "takes over a stale lock rather than hanging" "$(cat "$WORK/stale/artisan" 2>/dev/null)" "#!/usr/bin/env php"

# --- 9. A tree that says it is placed but cannot load is replaced ----------
# The failure this was written for: two containers clobbered each other's copy,
# vendor lost a file, and the marker said 1.1.0 all the same. Every restart
# then read the marker, decided there was nothing to do, and crashed on the
# same missing file. A marker is a claim; this is the check that it is true.
rm -rf "$WORK/damaged"; mkdir -p "$WORK/damaged"
make_image "$WORK/image" 1.1.0
run_place "$WORK/image" "$WORK/damaged"
rm -f "$WORK/damaged/vendor/composer/real.php"          # what the race did
printf 'broken but claims to be fine' > "$WORK/damaged/app/Marker.php"

run_place "$WORK/image" "$WORK/damaged"

check "repairs a tree that cannot load itself" "$([ -f "$WORK/damaged/vendor/composer/real.php" ] && echo repaired || echo "still broken")" "repaired"
check "and the repaired tree is the image's" "$(cat "$WORK/damaged/app/Marker.php")" "from 1.1.0"

# --- 10. A copy that cannot load is not marked as placed -------------------
# Better to stop with a message naming the cause than to write a marker every
# later start will believe.
rm -rf "$WORK/badimage" "$WORK/target"; mkdir -p "$WORK/target"
make_image "$WORK/badimage" 1.1.0
rm -f "$WORK/badimage/vendor/composer/real.php"
run_place "$WORK/badimage" "$WORK/target"

check "refuses to mark an unloadable copy as placed" "$([ -f "$WORK/target/.ticktz-image" ] && echo marked || echo "not marked")" "not marked"
check "and says why" "$(grep -c 'will not load' "$WORK/out-$$" 2>/dev/null || echo 0)" "1"

# --- 11. storage ends up owned by the user that serves the web -------------
# The entrypoint runs as root and php-fpm as www-data, and with /var/www/html
# empty in the image a fresh `storage` volume is created owned by root. Laravel
# compiles every Blade view into storage/framework/views on first render, so
# the symptom is redirects working and pages returning an empty 500 — with no
# permission named in any log.
if [ "$(id -u)" = "0" ] && id www-data >/dev/null 2>&1; then
    rm -rf "$WORK/owned"; mkdir -p "$WORK/owned"
    make_image "$WORK/image" 1.1.0
    run_place "$WORK/image" "$WORK/owned"

    check "storage is owned by www-data" "$(stat -c '%U' "$WORK/owned/storage")" "www-data"
    check "storage/framework/views too" "$(stat -c '%U' "$WORK/owned/storage/framework/views")" "www-data"
    check "and bootstrap/cache" "$(stat -c '%U' "$WORK/owned/bootstrap/cache")" "www-data"

    # An existing volume from an earlier version has the same problem, so the
    # repair has to happen on every boot and not only when placing.
    chown -R root:root "$WORK/owned/storage"
    run_place "$WORK/image" "$WORK/owned"

    check "a root-owned storage is handed over on a later boot" "$(stat -c '%U' "$WORK/owned/storage")" "www-data"

    # A developer's bind-mounted checkout has no marker, and handing their
    # storage to uid 82 would stop their own artisan writing to it.
    rm -rf "$WORK/devtree"; mkdir -p "$WORK/devtree/storage" "$WORK/devtree/vendor"
    printf '#!/usr/bin/env php' > "$WORK/devtree/artisan"
    printf '<?php require __DIR__ . "/composer/real.php";' > "$WORK/devtree/vendor/autoload.php"
    mkdir -p "$WORK/devtree/vendor/composer"
    printf '<?php return true;' > "$WORK/devtree/vendor/composer/real.php"
    chown -R root:root "$WORK/devtree"
    run_place "$WORK/image" "$WORK/devtree"

    check "leaves a mounted source tree's ownership alone" "$(stat -c '%U' "$WORK/devtree/storage")" "root"
else
    echo "  skip ownership checks (needs root and a www-data user)"
fi

# --- 12. an instance with no APP_KEY gets one, once ------------------------
# The image carries no `.env` — it must not, it would be somebody's database
# password — so when the environment does not supply a key there is nothing to
# read one from. Without this the instance boots and every page is
# MissingAppKeyException behind an empty 500.
#
# The fake artisan writes the key the way Laravel's key:generate does, so the
# check is about when this runs and how often, not about Laravel.
rm -rf "$WORK/keyed"; mkdir -p "$WORK/keyed"
make_image "$WORK/image" 1.1.0
cat > "$WORK/image/artisan" <<'ARTISAN'
#!/usr/bin/env php
<?php
if (($argv[1] ?? '') === 'key:generate') {
    $env = __DIR__ . '/.env';
    $key = 'base64:' . base64_encode(random_bytes(32));
    $body = is_file($env) ? file_get_contents($env) : "APP_KEY=\n";
    file_put_contents($env, preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $body));
}
ARTISAN

APP_KEY= run_place "$WORK/image" "$WORK/keyed"

check "generates a key when the environment has none" \
    "$(grep -c '^APP_KEY=base64:' "$WORK/keyed/.env" 2>/dev/null || echo 0)" "1"

first_key="$(grep '^APP_KEY=' "$WORK/keyed/.env")"
APP_KEY= run_place "$WORK/image" "$WORK/keyed"

# Changing it on a later boot would make everything the first key encrypted —
# the mailbox passwords — unreadable.
check "and never changes it afterwards" "$(grep '^APP_KEY=' "$WORK/keyed/.env")" "$first_key"

# An operator who sets APP_KEY themselves should be left alone entirely.
rm -rf "$WORK/envkey"; mkdir -p "$WORK/envkey"
APP_KEY=base64:fromtheoperator run_place "$WORK/image" "$WORK/envkey"

check "writes no .env when the environment supplies a key" \
    "$([ -f "$WORK/envkey/.env" ] && echo written || echo "left alone")" "left alone"

# --- Settings seeded into the instance's own .env --------------------------
#
# They used to be environment variables from the compose file. Laravel's dotenv
# is immutable, so a variable already in the environment is never replaced by
# `.env` — and the file the setup wizard writes was therefore outranked by the
# compose file for the life of the instance. Choosing "my own database" in the
# wizard migrated that database, created the administrator in it, and then
# served every request from the bundled one.

seed() {
    TICKTZ_DEFAULT_DB_CONNECTION=mysql \
    TICKTZ_DEFAULT_DB_HOST=mysql \
    TICKTZ_DEFAULT_DB_DATABASE=ticktz \
    TICKTZ_DEFAULT_DB_PASSWORD=from-the-compose-file \
    TICKTZ_DEFAULT_QUEUE_CONNECTION=redis \
    run_place "$1" "$2"
}

rm -rf "$WORK/seeded"; mkdir -p "$WORK/seeded"
APP_KEY=base64:supplied seed "$WORK/image" "$WORK/seeded"

check "writes the stack's settings into the instance" \
    "$(grep '^DB_HOST=' "$WORK/seeded/.env" 2>/dev/null)" "DB_HOST=mysql"
check "including the bundled password" \
    "$(grep '^DB_PASSWORD=' "$WORK/seeded/.env" 2>/dev/null)" "DB_PASSWORD=from-the-compose-file"
# grep -c prints 0 and exits non-zero, so the `|| echo 0` this had at first
# appended a second one.
check "and strips the prefix as it goes" \
    "$(grep -c '^TICKTZ_DEFAULT_' "$WORK/seeded/.env" 2>/dev/null)" "0"

# The whole point: what the wizard writes afterwards has to survive. A second
# boot must not put the bundled database back.
sed -i 's|^DB_HOST=.*|DB_HOST=db.example.org|; s|^DB_PASSWORD=.*|DB_PASSWORD=chosen-in-the-wizard|' "$WORK/seeded/.env"
APP_KEY=base64:supplied seed "$WORK/image" "$WORK/seeded"

check "never overwrites what the wizard chose" \
    "$(grep '^DB_HOST=' "$WORK/seeded/.env")" "DB_HOST=db.example.org"
check "nor the password it chose" \
    "$(grep '^DB_PASSWORD=' "$WORK/seeded/.env")" "DB_PASSWORD=chosen-in-the-wizard"
check "and adds no second line for either" \
    "$(grep -c '^DB_HOST=' "$WORK/seeded/.env")" "1"

# A key that is there but empty is not a value. Left as it was, an instance
# would come up with no database host at all.
rm -rf "$WORK/emptied"; mkdir -p "$WORK/emptied"
printf 'APP_NAME=Ticktz\nDB_HOST=\nMAIL_HOST=smtp.example.org\n' > "$WORK/emptied/.env"
APP_KEY=base64:supplied seed "$WORK/image" "$WORK/emptied"

check "fills a key that is present but empty" \
    "$(grep '^DB_HOST=' "$WORK/emptied/.env")" "DB_HOST=mysql"
check "without duplicating the line" "$(grep -c '^DB_HOST=' "$WORK/emptied/.env")" "1"
check "and leaves the rest of the file alone" \
    "$(grep '^MAIL_HOST=' "$WORK/emptied/.env")" "MAIL_HOST=smtp.example.org"

# Outside the stack that sets them there is nothing to seed, which is what
# keeps this away from a developer's bind-mounted working copy.
rm -rf "$WORK/nodefaults"; mkdir -p "$WORK/nodefaults"
APP_KEY=base64:supplied run_place "$WORK/image" "$WORK/nodefaults"

check "writes nothing when the stack supplies no defaults" \
    "$([ -f "$WORK/nodefaults/.env" ] && echo written || echo "left alone")" "left alone"

echo
if [ "$failures" -gt 0 ]; then
    echo "$failures of $checks checks failed"
    exit 1
fi

echo "$checks checks passed"
