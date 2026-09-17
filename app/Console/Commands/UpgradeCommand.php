<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Upgrade;
use App\Services\Updates\ReleaseChecker;
use App\Services\Updates\UpgradePreflight;
use App\Services\Updates\UpgradeRunner;
use Illuminate\Console\Command;

/**
 * Upgrade this installation from the command line.
 *
 * The same mechanism the screen uses, with the queue left out: this runs the
 * upgrade in the foreground, as whoever invoked it. That makes it the answer
 * for the two installations the screen cannot serve — one with no worker, and
 * one where the operator would rather watch it happen — and it is also how the
 * mechanism is exercised without a queue in the way.
 *
 * It still writes an `upgrades` row. The account of what happened belongs in
 * the same place whether a person clicked a button or typed a command.
 */
class UpgradeCommand extends Command
{
    protected $signature = 'ticktz:upgrade
                            {--check : Report what is available and stop}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Install the newest published release of Ticktz';

    public function handle(ReleaseChecker $checker, UpgradePreflight $preflight, UpgradeRunner $runner): int
    {
        if (! $checker->enabled()) {
            $this->error('The update check is switched off. Set TICKTZ_UPDATE_CHECK=true to turn it on.');

            return self::FAILURE;
        }

        $current = $checker->current();
        $this->line('Installed: '.($current === null ? 'unknown' : (string) $current));

        $release = $checker->latest(refresh: true);

        if ($release === null) {
            $this->info('No release information available.');

            return self::SUCCESS;
        }

        if (! $checker->isUpgrade($release)) {
            $this->info('Already up to date.');

            return self::SUCCESS;
        }

        $step = $current === null ? 'unknown' : $release->version->stepFrom($current);
        $this->line('Available: '.(string) $release->version.'  ('.$step.')');

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        foreach ($preflight->run() as $check) {
            $this->line(sprintf(
                ' %s %s%s',
                $check['ok'] ? '✓' : ($check['blocking'] ? '✗' : '!'),
                $check['key'],
                $check['detail'] === null ? '' : '  ('.$check['detail'].')',
            ));
        }

        if (! $preflight->passes()) {
            $this->error('This installation cannot replace its own code. See the checks above.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Install {$release->version}? The current version is kept, and can be put back.", false)) {
            return self::SUCCESS;
        }

        $upgrade = Upgrade::query()->create([
            'from_version' => (string) ($current ?? 'unknown'),
            'to_version' => (string) $release->version,
            'status' => Upgrade::QUEUED,
        ]);

        $runner->run($upgrade);

        // The swap runs detached — by design, because it replaces the code this
        // process is running from. Waiting for it here is safe in a way it is
        // not in a web request: nothing else is holding a connection open, and
        // an operator who typed a command expects to be told how it went.
        return $this->waitFor($upgrade, $runner) ? self::SUCCESS : self::FAILURE;
    }

    private function waitFor(Upgrade $upgrade, UpgradeRunner $runner): bool
    {
        if ($upgrade->fresh()?->status === Upgrade::FAILED) {
            $this->error((string) $upgrade->fresh()?->error);

            return false;
        }

        $file = $runner->statusFileFor($upgrade);
        $deadline = time() + 1800;
        $said = '';

        while (time() < $deadline) {
            $contents = is_file($file) ? trim((string) file_get_contents($file)) : '';
            $lines = preg_split('/\R/', $contents) ?: [];
            $state = trim($lines[0] ?? '');
            $line = trim(implode("\n", array_slice($lines, 1)));

            if ($line !== '' && $line !== $said) {
                $this->line($line);
                $said = $line;
            }

            if ($state === 'installed') {
                $this->info('Installed.');

                return true;
            }

            if ($state === 'failed') {
                $this->error($line === '' ? 'The upgrade failed.' : $line);

                return false;
            }

            usleep(500_000);
        }

        $this->error('The upgrade did not finish within half an hour. Look in '.dirname($file).'.');

        return false;
    }
}
