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
     * @return array{0: string, 1: string}
     */
    private function read(string $file): array
    {
        $contents = (string) file_get_contents($file);
        $lines = preg_split('/\R/', trim($contents)) ?: [];

        return [trim($lines[0] ?? ''), trim(implode("\n", array_slice($lines, 1)))];
    }
}
