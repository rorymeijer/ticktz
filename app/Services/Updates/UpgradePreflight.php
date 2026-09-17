<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether this instance can upgrade itself, and whether it should right now.
 *
 * Two different questions, kept apart on purpose. *Can* is about how the
 * instance is deployed and does not change between one upgrade and the next.
 * *Should* is about this moment — is the database up, is there room on the
 * disk — and is re-asked every time.
 *
 * The security of the whole feature lives in one of these checks. A web
 * request must never be able to write the application's own code: if it can,
 * every file-write bug in the application becomes a way to run code, and a
 * service desk takes uploads from anybody with an e-mail address. So the
 * process that replaces the code is the queue worker, and the check below is
 * how an operator finds out whether their deployment actually keeps those two
 * apart — or whether it only looks like it does.
 */
class UpgradePreflight
{
    /**
     * @return array<int, array{key: string, ok: bool, blocking: bool, detail: string|null}>
     */
    public function run(): array
    {
        $root = $this->root();

        return [
            $this->check('enabled', (bool) config('ticktz.updates.self_upgrade'), true),
            $this->check('writable', $this->rootIsWritable($root), true, $root),
            $this->check('web_cannot_write', ! $this->webCanWriteCode($root), false),
            $this->check('archive', class_exists(\ZipArchive::class), true),
            $this->check('database', $this->databaseAnswers(), true),
            $this->check('code_persists', $this->codePersists($root), true, $this->persistenceDetail($root)),
            $this->check('disk_space', $this->freeBytes($root) >= $this->requiredBytes(), true, $this->diskDetail($root)),
        ];

        /*
         * There is deliberately no "is a worker running" check here.
         *
         * The honest version needs a heartbeat this application does not have,
         * and the dishonest version — reading the jobs table — says yes when
         * work is piling up unconsumed, which is exactly backwards. The screen
         * answers it by observation instead: an upgrade that sits at "queued"
         * for a few minutes is told, on the screen, that nothing is taking it.
         */
    }

    /** True when nothing blocking failed. */
    public function passes(): bool
    {
        foreach ($this->run() as $check) {
            if ($check['blocking'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this deployment can replace its own code at all.
     *
     * A Docker install cannot, and no setting makes it able to: replacing a
     * running container needs the daemon, and the web process must never be
     * able to reach that. Those installs are shown the command instead, which
     * is not a lesser feature — it is the honest one.
     */
    public function canSelfUpgrade(): bool
    {
        return (bool) config('ticktz.updates.self_upgrade')
            && $this->rootIsWritable($this->root())
            && $this->codePersists($this->root())
            && class_exists(\ZipArchive::class);
    }

    /**
     * Whether code written here would still be there afterwards.
     *
     * Outside a container the question does not arise: a directory on a disk
     * keeps what is put in it. Inside one it is the difference between an
     * upgrade and an illusion. A container's own writable layer is private to
     * that container and thrown away when it is recreated, so an upgrade
     * written there is invisible to the app container that has to serve it and
     * gone at the next `docker compose up -d` — succeeding loudly and
     * reverting silently, which is the worst way for this to fail.
     *
     * Asked of the filesystem rather than of a setting. A volume mounted at
     * the application root is a different device from the directory above it,
     * and that is an observable fact; a configuration flag saying the same
     * thing would only be somebody's belief about their deployment.
     */
    private function codePersists(string $root): bool
    {
        if (! $this->inContainer()) {
            return true;
        }

        return $this->isMountPoint($root);
    }

    private function inContainer(): bool
    {
        return is_file('/.dockerenv') || is_file('/run/.containerenv');
    }

    private function isMountPoint(string $path): bool
    {
        $parent = dirname($path);

        if ($parent === $path) {
            return true;
        }

        $here = @stat($path);
        $above = @stat($parent);

        if ($here === false || $above === false) {
            return false;
        }

        return $here['dev'] !== $above['dev'];
    }

    private function persistenceDetail(string $root): ?string
    {
        if (! $this->inContainer()) {
            return null;
        }

        return $this->isMountPoint($root)
            ? $root.' is on a volume'
            : $root.' is the container\'s own writable layer';
    }

    public function root(): string
    {
        return rtrim((string) config('ticktz.updates.root', base_path()), '/');
    }

    /**
     * @return array{key: string, ok: bool, blocking: bool, detail: string|null}
     */
    private function check(string $key, bool $ok, bool $blocking, ?string $detail = null): array
    {
        return ['key' => $key, 'ok' => $ok, 'blocking' => $blocking, 'detail' => $detail];
    }

    /**
     * Whether *this* process could write the code.
     *
     * Asked from wherever it runs, which is the point: on the worker a `true`
     * is the requirement, and on a web request the same `true` is the finding.
     */
    private function rootIsWritable(string $root): bool
    {
        return is_dir($root) && is_writable($root) && is_writable($root.'/app');
    }

    /**
     * Whether the web server can write the application's own code.
     *
     * Reported as a warning rather than a block, because it is a pre-existing
     * property of the deployment and not something this feature introduced —
     * and because refusing to upgrade an instance that is already exposed does
     * not make it less exposed. It is on the screen so somebody sees it.
     */
    private function webCanWriteCode(string $root): bool
    {
        // Only meaningful when asked from a web request. From the console the
        // answer is about the console user and says nothing about nginx.
        return PHP_SAPI !== 'cli' && $this->rootIsWritable($root);
    }

    private function databaseAnswers(): bool
    {
        try {
            return DB::connection()->getPdo() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Room for a release beside the installation.
     *
     * A flat floor, not a measurement of the tree here. The first version
     * multiplied the size of `vendor` and asked for twenty-four gigabytes on a
     * checkout whose vendor held the development dependencies — a number with
     * nothing to do with what a release artifact actually is. A release is a
     * production tree: a few hundred megabytes, archive and extraction
     * together well inside a gigabyte.
     *
     * The precise check happens where the precise number is known. The release
     * asset carries its own size, and the runner compares against that before
     * it downloads anything.
     */
    private function requiredBytes(): int
    {
        return (int) config('ticktz.updates.free_bytes_required', 1024 * 1024 * 1024);
    }

    private function freeBytes(string $root): int
    {
        $free = @disk_free_space($root);

        return is_float($free) ? (int) $free : 0;
    }

    private function diskDetail(string $root): string
    {
        return sprintf(
            '%s free, %s needed',
            $this->human($this->freeBytes($root)),
            $this->human($this->requiredBytes()),
        );
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
    }
}
