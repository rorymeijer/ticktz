<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Models\Upgrade;

/**
 * Reads what the swap said, from the other side of it.
 *
 * The process that replaced the application could not report its own result:
 * for the duration of the swap there is no application to report with and no
 * database connection to report into. It wrote a file instead, and this reads
 * that file — from the new code, on the next request, which is the first
 * moment anything is in a position to.
 *
 * Also what answers "is a worker running", by observation rather than by
 * guess: an upgrade nothing has picked up in five minutes is not queued, it is
 * stuck, and saying so is more use than a check made before the fact.
 */
class UpgradeMonitor
{
    public function __construct(private readonly UpgradeRunner $runner) {}

    public function reconcile(Upgrade $upgrade): Upgrade
    {
        if ($upgrade->isFinished()) {
            return $upgrade;
        }

        $file = $this->runner->statusFileFor($upgrade);

        if (! is_file($file)) {
            return $upgrade;
        }

        [$state, $line] = $this->read($file);

        if ($line !== '' && ! str_contains((string) $upgrade->log, $line)) {
            $upgrade->note($line);
        }

        if ($state === 'installed') {
            $upgrade->forceFill([
                'status' => Upgrade::INSTALLED,
                'finished_at' => $upgrade->finished_at ?? now(),
            ])->save();

            $this->dropCompiledCode();
        }

        if ($state === 'failed') {
            $upgrade->forceFill([
                'status' => Upgrade::FAILED,
                'error' => mb_substr($line, 0, 1000),
                'finished_at' => $upgrade->finished_at ?? now(),
            ])->save();
        }

        return $upgrade;
    }

    /**
     * Make this process stop serving the code that is no longer there.
     *
     * The production image runs with `opcache.validate_timestamps=0`, which
     * means PHP compiles each file once and never looks at the disk again.
     * That is what makes an upgrade survivable — requests in flight keep
     * running from memory while the files underneath them are replaced — and
     * it is also why the new code would otherwise never be served: nothing
     * would ask the disk what changed.
     *
     * This has to happen in the process serving the web, which is not the
     * process that did the upgrade: in the bundled Docker stack those are two
     * containers with separate opcaches, and the worker resetting its own
     * would achieve nothing. Here is the first moment a web request is in a
     * position to do it, which is the same reason this class exists at all.
     *
     * The request running this finishes on the old compiled code, which is
     * correct — it was already halfway through it. The next one compiles the
     * new files.
     */
    private function dropCompiledCode(): void
    {
        if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
            @opcache_reset();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function read(string $file): array
    {
        $contents = (string) file_get_contents($file);
        $lines = preg_split('/\R/', trim($contents)) ?: [];

        return [trim($lines[0] ?? ''), trim(implode("\n", array_slice($lines, 1)))];
    }
}
