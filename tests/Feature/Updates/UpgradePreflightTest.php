<?php

declare(strict_types=1);

use App\Services\Updates\UpgradePreflight;

function checkNamed(array $checks, string $key): array
{
    return collect($checks)->firstWhere('key', $key);
}

beforeEach(function (): void {
    config(['ticktz.updates.self_upgrade' => true, 'ticktz.updates.root' => base_path()]);
});

it('refuses to self-upgrade when nobody turned it on', function (): void {
    config(['ticktz.updates.self_upgrade' => false]);

    $preflight = app(UpgradePreflight::class);

    expect($preflight->canSelfUpgrade())->toBeFalse()
        ->and($preflight->passes())->toBeFalse()
        ->and(checkNamed($preflight->run(), 'enabled')['ok'])->toBeFalse();
});

/**
 * A Docker install cannot replace its own code, and no setting makes it able
 * to. Pointing the root at somewhere unwritable is the closest a test can come
 * to that shape.
 */
it('refuses when the code cannot be written', function (): void {
    config(['ticktz.updates.root' => '/proc/nowhere-at-all']);

    $preflight = app(UpgradePreflight::class);

    expect($preflight->canSelfUpgrade())->toBeFalse()
        ->and(checkNamed($preflight->run(), 'writable')['ok'])->toBeFalse();
});

it('reports the database as blocking and the web-write finding as not', function (): void {
    $checks = app(UpgradePreflight::class)->run();

    expect(checkNamed($checks, 'database')['blocking'])->toBeTrue()
        ->and(checkNamed($checks, 'web_cannot_write')['blocking'])->toBeFalse();
});

/**
 * The security of the whole feature. A web request that can write the
 * application's own code turns every file-write bug into a way to run code —
 * and a service desk takes uploads from anybody with an e-mail address.
 *
 * Reported rather than blocked: refusing to upgrade an instance that is
 * already exposed does not make it less exposed, and the finding is about the
 * deployment rather than about this upgrade.
 */
it('says nothing about the web server when asked from the console', function (): void {
    // The suite runs under the CLI, where the answer would be about the
    // console user and would say nothing about nginx.
    expect(checkNamed(app(UpgradePreflight::class)->run(), 'web_cannot_write')['ok'])->toBeTrue();
});

it('needs room for the release beside the installation, not merely a byte', function (): void {
    $disk = checkNamed(app(UpgradePreflight::class)->run(), 'disk_space');

    expect($disk['blocking'])->toBeTrue()
        ->and($disk['detail'])->toContain('needed');
});

it('passes on an installation that could actually do it', function (): void {
    expect(app(UpgradePreflight::class)->passes())->toBeTrue()
        ->and(app(UpgradePreflight::class)->canSelfUpgrade())->toBeTrue();
});
