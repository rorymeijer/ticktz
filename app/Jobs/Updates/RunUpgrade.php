<?php

declare(strict_types=1);

namespace App\Jobs\Updates;

use App\Models\Upgrade;
use App\Services\Updates\UpgradeRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The worker's half of an upgrade.
 *
 * A web request cannot do this, and that is the whole design rather than an
 * implementation detail: a web-facing PHP process must never be able to write
 * the application's own code, because a service desk accepts uploads from
 * anybody with an e-mail address and every file-write bug would otherwise
 * become a way to run code. So a request writes a row asking for a named,
 * published release, and this — running as whoever owns the code — is what
 * acts on it.
 *
 * Never retried. An upgrade that failed halfway has already moved something,
 * and a second attempt starting from an unknown state is how a working
 * instance becomes an unbootable one.
 */
class RunUpgrade implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $upgradeId) {}

    public function handle(UpgradeRunner $runner): void
    {
        $upgrade = Upgrade::query()->find($this->upgradeId);

        if ($upgrade === null || $upgrade->status !== Upgrade::QUEUED) {
            return;
        }

        $runner->run($upgrade);
    }

    public function failed(\Throwable $e): void
    {
        Upgrade::query()->whereKey($this->upgradeId)->where('status', '!=', Upgrade::INSTALLED)->update([
            'status' => Upgrade::FAILED,
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'finished_at' => now(),
        ]);
    }
}
