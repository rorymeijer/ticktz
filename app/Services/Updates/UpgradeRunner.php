<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Models\Upgrade;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Carries out one upgrade.
 *
 * Split in two on purpose, and the line between them is where the application
 * stops existing.
 *
 * Everything up to {@see UpgradeSwap} is ordinary work: fetch the release,
 * check it, unpack it beside the installation, decide whether to go on. All of
 * it can fail safely, because nothing has been touched yet.
 *
 * The swap is a separate process, and this one does not wait for it — in a
 * moment the code this method is running from will have been replaced
 * underneath it. What it leaves behind is a status file, which the next
 * request reads from the *new* code. See {@see UpgradeMonitor}.
 */
class UpgradeRunner
{
    public function __construct(
        private readonly ReleaseChecker $checker,
        private readonly ReleaseArchive $archive,
        private readonly UpgradePreflight $preflight,
        private readonly UpgradeSwap $swap,
    ) {}

    public function run(Upgrade $upgrade): void
    {
        try {
            $this->attempt($upgrade);
        } catch (Throwable $e) {
            // The upgrade failing is a normal outcome and the job is not the
            // place to find out about it: the row is what the screen reads.
            $upgrade->forceFill([
                'status' => Upgrade::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();

            $upgrade->note('Failed: '.$e->getMessage());

            Log::warning('Upgrade to {version} failed: {message}', [
                'version' => $upgrade->to_version,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function attempt(Upgrade $upgrade): void
    {
        $upgrade->forceFill(['status' => Upgrade::RUNNING, 'started_at' => now()])->save();
        $upgrade->note('Starting');

        if (! $this->preflight->passes()) {
            throw new RuntimeException('This installation cannot replace its own code. See the update screen.');
        }

        if (! UpgradeSwap::isAvailable()) {
            throw new RuntimeException(
                'This installation cannot start a separate process, which is what replaces the code. '
                .'Upgrade from the command line instead.'
            );
        }

        $release = $this->checker->latest();

        if ($release === null || (string) $release->version !== $upgrade->to_version) {
            // The release the screen offered is not the release that is there
            // now. Installing whatever happens to be newest instead of what
            // somebody agreed to is not an upgrade, it is a surprise.
            throw new RuntimeException("Release {$upgrade->to_version} is no longer the one on offer.");
        }

        $work = $this->workingDirectory($upgrade);

        $tree = $this->archive->stage($release, $work, fn (string $line) => $upgrade->note($line));

        $upgrade->note('Replacing the application');
        $upgrade->forceFill(['backup_path' => $work.'/previous'])->save();

        // From here the code under this process is being replaced. Nothing
        // after this line can be relied on to run.
        $this->swap->start($this->preflight->root(), $tree, $work);
    }

    /**
     * Where this upgrade unpacks and keeps the previous version.
     *
     * Under `storage`, which is the one directory an upgrade never replaces —
     * so the backup of the old tree survives the swap that creates it.
     */
    private function workingDirectory(Upgrade $upgrade): string
    {
        return $this->preflight->root().'/storage/app/upgrades/'.$upgrade->getKey();
    }

    public function statusFileFor(Upgrade $upgrade): string
    {
        return $this->workingDirectory($upgrade).'/swap.status';
    }
}
