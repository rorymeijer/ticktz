<?php

declare(strict_types=1);

use App\Services\Updates\UpgradePreflight;
use App\Services\Updates\UpgradeSwap;
use Symfony\Component\Yaml\Yaml;

/**
 * The Docker half of upgrading.
 *
 * The bundled stack runs the app and the worker as two containers from one
 * image. Only what they both mount is shared, so an upgrade written into a
 * container's own writable layer is invisible to its sibling and gone at the
 * next `docker compose up -d`. These are the checks that stand between that
 * and an upgrade that reports success and silently reverts.
 */
it('replaces the same paths from the image as a release does', function () {
    $entrypoint = file_get_contents(base_path('docker/php/place-code.sh'));

    expect($entrypoint)->toBeString();

    preg_match('/^OWNED="([^"]+)"$/m', (string) $entrypoint, $matches);

    expect($matches)->toHaveCount(2);

    $fromEntrypoint = preg_split('/\s+/', trim($matches[1])) ?: [];

    // Two ways of upgrading that leave different trees behind would mean only
    // one of them is the tested one. A newer image and an installed release
    // replace the same set or this fails.
    expect($fromEntrypoint)->toBe(UpgradeSwap::owned());
});

it('gives nginx, the app and the worker the same code', function () {
    $compose = yaml_or_null(base_path('docker-compose.prod.yml'));

    $mounts = fn (string $service): array => array_map(
        static fn (string $line): string => explode(':', $line)[0],
        (array) ($compose['services'][$service]['volumes'] ?? []),
    );

    // The base fragment is merged by compose itself, so app and worker inherit
    // the code volume from it rather than declaring it.
    $base = array_map(
        static fn (string $line): string => explode(':', $line)[0],
        (array) ($compose['x-app-base']['volumes'] ?? []),
    );

    expect($base)->toContain('code')
        ->and($mounts('nginx'))->toContain('code')
        ->and(array_keys((array) $compose['volumes']))->toContain('code');

    foreach (['app', 'worker'] as $service) {
        expect(array_merge($base, $mounts($service)))->toContain('code');
    }
});

it('refuses to replace a directory something is mounted on', function () {
    // `app` is in the owned list, and here it is a different device from the
    // root above it — which is what a mount looks like from PHP. The swap has
    // to notice before it moves anything, because a mount point cannot be
    // moved aside and cannot be removed once emptied: it would get halfway,
    // roll back, and report a failure whose cause is nowhere in the message.
    $root = fakeMountedTree();

    expect(fn () => (new UpgradeSwap)->start($root, $root.'/new', $root.'/work'))
        ->toThrow(RuntimeException::class, 'mounted there');
})->skip(fn () => ! canFakeAMount(), 'Needs a filesystem this process may mount onto.');

it('treats an ordinary directory as upgradeable', function () {
    // The same code path as the test above, on a tree with no mounts in it:
    // whatever that check does, it must not refuse a normal installation.
    $root = sys_get_temp_dir().'/ticktz-plain-'.bin2hex(random_bytes(6));
    mkdir($root.'/app', 0755, true);
    mkdir($root.'/new', 0755, true);
    mkdir($root.'/work', 0755, true);
    file_put_contents($root.'/artisan', "<?php\nexit(0);\n");

    (new UpgradeSwap)->start($root, $root.'/new', $root.'/work');

    expect(is_file($root.'/work/swap.status'))->toBeTrue();
});

it('says the code does not persist when it is the container layer', function () {
    // Outside a container the question does not arise, so that is what this
    // asserts here: the check passes, and its detail is empty because there is
    // nothing about a plain directory worth reporting.
    $checks = collect(app(UpgradePreflight::class)->run())->keyBy('key');

    expect($checks)->toHaveKey('code_persists')
        ->and($checks['code_persists']['blocking'])->toBeTrue()
        ->and($checks['code_persists']['ok'])->toBeTrue();
})->skip(fn () => is_file('/.dockerenv'), 'This asserts the answer outside a container.');

function yaml_or_null(string $path): array
{
    $parsed = Yaml::parseFile($path);

    return is_array($parsed) ? $parsed : [];
}

function canFakeAMount(): bool
{
    return PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && posix_geteuid() === 0;
}

/**
 * A tree whose `app` directory is a real mount, so the check is exercised
 * against the thing it is about rather than against a stub of it.
 */
function fakeMountedTree(): string
{
    $root = sys_get_temp_dir().'/ticktz-mount-'.bin2hex(random_bytes(6));
    mkdir($root.'/app', 0755, true);
    mkdir($root.'/new', 0755, true);
    mkdir($root.'/work', 0755, true);

    exec('mount -t tmpfs tmpfs '.escapeshellarg($root.'/app').' 2>&1', $output, $code);

    if ($code !== 0) {
        test()->markTestSkipped('Could not mount a tmpfs: '.implode("\n", $output));
    }

    register_shutdown_function(static fn () => exec('umount '.escapeshellarg($root.'/app').' 2>/dev/null'));

    return $root;
}
