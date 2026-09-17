<?php

declare(strict_types=1);

use App\Models\Upgrade;
use App\Services\Updates\UpgradeMonitor;
use App\Services\Updates\UpgradeRunner;

/*
 * The working directory lives under the install root, so point that somewhere
 * disposable: without this the tests write status files into the checkout's
 * own storage directory and leave them there.
 */
beforeEach(function () {
    $this->upgradeRoot = sys_get_temp_dir().'/ticktz-monitor-'.bin2hex(random_bytes(6));
    mkdir($this->upgradeRoot, 0755, true);
    config()->set('ticktz.updates.root', $this->upgradeRoot);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->upgradeRoot));
});

function upgradeRow(array $attributes = []): Upgrade
{
    return Upgrade::query()->create(array_merge([
        'from_version' => '1.0.0',
        'to_version' => '1.1.0',
        'status' => Upgrade::RUNNING,
    ], $attributes));
}

/** Put a status file where the monitor will look for this upgrade's. */
function writeSwapStatus(Upgrade $upgrade, string $contents): void
{
    $file = app(UpgradeRunner::class)->statusFileFor($upgrade);

    if (! is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }

    file_put_contents($file, $contents);
}

it('leaves a row alone when the swap has not said anything', function () {
    $upgrade = upgradeRow();

    expect(app(UpgradeMonitor::class)->reconcile($upgrade)->status)->toBe(Upgrade::RUNNING);
});

it('marks a row installed once the swap says so', function () {
    $upgrade = upgradeRow();
    writeSwapStatus($upgrade, "installed\nDone. The previous version is in /tmp/previous\n");

    $reconciled = app(UpgradeMonitor::class)->reconcile($upgrade);

    expect($reconciled->status)->toBe(Upgrade::INSTALLED)
        ->and($reconciled->finished_at)->not->toBeNull()
        ->and($reconciled->log)->toContain('previous version');
});

it('marks a row failed and keeps what went wrong', function () {
    $upgrade = upgradeRow();
    writeSwapStatus($upgrade, "failed\nCould not move config; putting it back\n");

    $reconciled = app(UpgradeMonitor::class)->reconcile($upgrade);

    expect($reconciled->status)->toBe(Upgrade::FAILED)
        ->and($reconciled->error)->toBe('Could not move config; putting it back');
});

it('does not repeat a line it has already logged', function () {
    $upgrade = upgradeRow();
    writeSwapStatus($upgrade, "running\nPutting the new version in place\n");

    $monitor = app(UpgradeMonitor::class);
    $monitor->reconcile($upgrade);
    $monitor->reconcile($upgrade->fresh());

    expect(substr_count((string) $upgrade->fresh()->log, 'Putting the new version in place'))->toBe(1);
});

it('does not touch a row that has already finished', function () {
    $upgrade = upgradeRow(['status' => Upgrade::INSTALLED]);
    writeSwapStatus($upgrade, "failed\nsomething from an older attempt\n");

    expect(app(UpgradeMonitor::class)->reconcile($upgrade)->status)->toBe(Upgrade::INSTALLED);
});

it('calls an upgrade nothing has picked up abandoned', function () {
    $fresh = upgradeRow(['status' => Upgrade::QUEUED]);
    expect($fresh->looksAbandoned())->toBeFalse();

    $stale = upgradeRow(['status' => Upgrade::QUEUED]);
    $stale->forceFill(['created_at' => now()->subMinutes(10)])->save();

    // Nothing else in the application can tell whether a worker is running.
    // Observing that nothing took this is the honest form of the question.
    expect($stale->fresh()->looksAbandoned())->toBeTrue();
});
