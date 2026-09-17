<?php

declare(strict_types=1);

namespace App\Services\Updates;

use RuntimeException;

/**
 * Writes the script that replaces the application, and starts it.
 *
 * This is the one part that cannot happen inside the application, and the
 * reason is simple enough to state: `vendor` is being replaced, and the
 * process doing the replacing is running out of it. PHP loads files as it
 * needs them, so a job that moves `vendor` aside and then calls anything it
 * has not already loaded is a job that dies halfway, with the old tree gone
 * and the new one not yet in place.
 *
 * So the swap is a standalone PHP script — no framework, no autoloader,
 * nothing outside the functions PHP ships with — run as its own process. It
 * moves the old tree aside, moves the new one in, and then runs the
 * migrations from the *new* code. Its progress goes to a plain file, because
 * for the duration of it there is no database connection to write to and no
 * application to write it with.
 *
 * It is how Nextcloud's updater works, and for the same reason.
 */
class UpgradeSwap
{
    /**
     * Directories and files the release owns, and therefore replaces.
     *
     * Named rather than "everything except": an installation may hold things
     * nobody here knows about — an operator's own script, a certificate, a
     * directory a previous version created — and an upgrade that deletes what
     * it does not recognise is an upgrade that loses somebody's work.
     *
     * What is deliberately absent is what belongs to the instance: `.env`,
     * `storage`, and `public/storage`, which is a symlink into it.
     *
     * @var array<int, string>
     */
    private const OWNED = [
        'app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources',
        'routes', 'scripts', 'vendor', 'docs', 'docker',
        'artisan', 'composer.json', 'composer.lock', 'build.json',
    ];

    /**
     * What a release replaces.
     *
     * Public because the Docker entrypoint replaces the same set when it puts
     * a newer image's code into the volume, and a test asserts the two lists
     * have not drifted apart. Two ways of upgrading that leave different trees
     * behind would mean only one of them is the tested one.
     *
     * @return array<int, string>
     */
    public static function owned(): array
    {
        return self::OWNED;
    }

    /**
     * Write the script and start it, detached.
     *
     * Returns the path of the file the script reports into. The caller polls
     * that: it cannot wait for the process, because in a moment the caller's
     * own code will have been replaced underneath it.
     */
    public function start(string $root, string $newTree, string $workingDirectory): string
    {
        $status = $workingDirectory.'/swap.status';
        $script = $workingDirectory.'/swap.php';
        $backup = $workingDirectory.'/previous';

        $this->refuseMountedPaths($root);

        file_put_contents($script, $this->script($root, $newTree, $backup, $status));
        file_put_contents($status, "queued\n");

        $php = $this->php();

        // Detached, and deliberately so. The parent is about to lose the code
        // it is running from; waiting for the child would mean waiting with a
        // half-replaced tree underneath.
        $command = sprintf(
            '%s %s >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($workingDirectory.'/swap.output'),
        );

        if (function_exists('exec')) {
            exec($command);
        } else {
            throw new RuntimeException(
                'This installation cannot run a separate process, so it cannot replace its own code. '
                .'Upgrade from the command line instead.'
            );
        }

        return $status;
    }

    /**
     * Refuse before touching anything if the release owns a mount point.
     *
     * A directory somebody mounted a volume on cannot be moved aside: the
     * kernel refuses to rename it and refuses to remove it once emptied. The
     * swap would get halfway, roll back, and report a failure whose cause is
     * nowhere in the message. Better to notice here, where the tree is still
     * whole and the reason can be said plainly.
     */
    private function refuseMountedPaths(string $root): void
    {
        foreach (self::OWNED as $path) {
            $live = $root.'/'.$path;

            if (! is_dir($live)) {
                continue;
            }

            $here = @stat($live);
            $above = @stat($root);

            if ($here !== false && $above !== false && $here['dev'] !== $above['dev']) {
                throw new RuntimeException(
                    "The release replaces {$path}, and something is mounted there. "
                    .'Move that mount below `storage`, which an upgrade never replaces, or upgrade from the command line.'
                );
            }
        }
    }

    /** Whether this installation can start a process at all. */
    public static function isAvailable(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('exec') && ! in_array('exec', $disabled, true);
    }

    private function php(): string
    {
        $binary = PHP_BINDIR.'/php';

        return is_executable($binary) ? $binary : 'php';
    }

    /**
     * The script itself.
     *
     * Plain PHP with no dependencies, because by the time it matters there is
     * nothing to depend on. Everything it needs is baked in as a literal —
     * nothing is read from a config it can no longer load.
     */
    private function script(string $root, string $newTree, string $backup, string $status): string
    {
        // Baked in rather than read at run time: by the time this matters the
        // application that knows its own environment has been replaced.
        $isProduction = var_export(app()->environment('production'), true);
        $owned = var_export(self::OWNED, true);
        $root = var_export($root, true);
        $newTree = var_export($newTree, true);
        $backup = var_export($backup, true);
        $statusFile = var_export($status, true);

        return <<<PHP
        <?php

        /**
         * Written by Ticktz to replace itself. Safe to delete afterwards.
         *
         * No framework and no autoloader: this runs while `vendor` is being
         * moved, so anything it needed to load later would not be there.
         */
        declare(strict_types=1);

        \$root = {$root};
        \$new = {$newTree};
        \$backup = {$backup};
        \$status = {$statusFile};
        \$owned = {$owned};
        \$production = {$isProduction};

        function say(string \$state, string \$line = ''): void
        {
            global \$status;
            file_put_contents(\$status, \$state . "\\n" . \$line . "\\n");
        }

        function move(string \$from, string \$to): bool
        {
            if (@rename(\$from, \$to)) {
                return true;
            }

            // rename() fails across filesystems, which a container bind mount
            // very often is. Falling back to a copy is slower and correct.
            return copyTree(\$from, \$to) && deleteTree(\$from);
        }

        function copyTree(string \$from, string \$to): bool
        {
            if (is_link(\$from)) {
                return @symlink((string) readlink(\$from), \$to);
            }

            if (is_file(\$from)) {
                return @copy(\$from, \$to);
            }

            if (! is_dir(\$from)) {
                return false;
            }

            if (! is_dir(\$to) && ! @mkdir(\$to, 0755, true) && ! is_dir(\$to)) {
                return false;
            }

            foreach (array_diff(scandir(\$from) ?: [], ['.', '..']) as \$entry) {
                if (! copyTree(\$from . '/' . \$entry, \$to . '/' . \$entry)) {
                    return false;
                }
            }

            return true;
        }

        function deleteTree(string \$path): bool
        {
            if (is_link(\$path) || is_file(\$path)) {
                return @unlink(\$path);
            }

            if (! is_dir(\$path)) {
                return true;
            }

            foreach (array_diff(scandir(\$path) ?: [], ['.', '..']) as \$entry) {
                deleteTree(\$path . '/' . \$entry);
            }

            return @rmdir(\$path);
        }

        say('running', 'Putting the previous version aside');

        if (! is_dir(\$backup) && ! @mkdir(\$backup, 0750, true)) {
            say('failed', 'Could not create ' . \$backup);
            exit(1);
        }

        \$moved = [];

        foreach (\$owned as \$path) {
            \$live = \$root . '/' . \$path;

            if (! file_exists(\$live)) {
                continue;
            }

            if (! move(\$live, \$backup . '/' . \$path)) {
                // Put back whatever has already been moved. A tree half taken
                // apart is worse than one that was never touched.
                say('failed', 'Could not move ' . \$path . '; putting it back');

                foreach (array_reverse(\$moved) as \$done) {
                    move(\$backup . '/' . \$done, \$root . '/' . \$done);
                }

                exit(1);
            }

            \$moved[] = \$path;
        }

        say('running', 'Putting the new version in place');

        foreach (\$owned as \$path) {
            \$source = \$new . '/' . \$path;

            if (! file_exists(\$source)) {
                continue;
            }

            if (! move(\$source, \$root . '/' . \$path)) {
                say('failed', 'Could not install ' . \$path . '; putting the previous version back');

                foreach (\$owned as \$undo) {
                    deleteTree(\$root . '/' . \$undo);

                    if (file_exists(\$backup . '/' . \$undo)) {
                        move(\$backup . '/' . \$undo, \$root . '/' . \$undo);
                    }
                }

                exit(1);
            }
        }

        say('running', 'Running the database migrations');

        \$artisan = escapeshellarg(\$root . '/artisan');
        \$php = escapeshellarg(PHP_BINDIR . '/php');
        \$output = [];
        \$code = 0;

        exec("cd " . escapeshellarg(\$root) . " && \$php \$artisan migrate --force --no-interaction 2>&1", \$output, \$code);

        if (\$code !== 0) {
            // Deliberately not rolled back. Migrations that failed halfway have
            // already touched the schema, and putting the old code back in
            // front of a half-migrated database is not a recovery — it is a
            // second broken state on top of the first. So the new code stays,
            // the failure is loud, and the message says where the previous
            // version is for an operator who decides otherwise.
            say('failed', "The migrations did not run. The new version is installed and the previous one is in "
                . \$backup . ":\\n" . implode("\\n", array_slice(\$output, -20)));
            exit(1);
        }

        exec("cd " . escapeshellarg(\$root) . " && \$php \$artisan storage:link --no-interaction 2>&1");
        exec("cd " . escapeshellarg(\$root) . " && \$php \$artisan optimize:clear 2>&1");

        // Rebuilding the caches the line above cleared. Best-effort on purpose:
        // the application runs without them, just slower, so a failure here is
        // not worth failing a successful upgrade over.
        if (\$production) {
            foreach (['config:cache', 'route:cache', 'event:cache'] as \$command) {
                exec("cd " . escapeshellarg(\$root) . " && \$php \$artisan " . \$command . " 2>&1");
            }
        }

        say('installed', 'Done. The previous version is in ' . \$backup);
        PHP;
    }
}
