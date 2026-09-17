<?php

declare(strict_types=1);

use App\Services\Updates\UpgradeSwap;

/**
 * The swap is tested by running it.
 *
 * It is a generated PHP script that moves directories about, and the only
 * assertion worth making about such a thing is what the disk looks like
 * afterwards. So these build two small trees in a temporary directory, run the
 * real script against them, and look.
 *
 * The one thing that cannot be exercised here is the swap replacing the code
 * the test itself is running from — which is precisely why the script is a
 * separate process with no dependencies.
 */
function swapTree(string $name, array $files): string
{
    $root = sys_get_temp_dir().'/ticktz-swap-'.bin2hex(random_bytes(6)).'/'.$name;

    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        file_put_contents($full, $contents);
    }

    return $root;
}

/** Run the generated script in the foreground and hand back what it reported. */
function runSwap(string $root, string $new, string $work): array
{
    $status = (new class extends UpgradeSwap
    {
        /** Write the script but run it here, so the test can wait for it. */
        public function startAndWait(string $root, string $newTree, string $workingDirectory): string
        {
            $reflection = new ReflectionMethod(UpgradeSwap::class, 'script');
            $status = $workingDirectory.'/swap.status';
            $script = $workingDirectory.'/swap.php';

            file_put_contents($script, $reflection->invoke($this, $root, $newTree, $workingDirectory.'/previous', $status));

            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1');

            return $status;
        }
    })->startAndWait($root, $new, $work);

    $lines = preg_split('/\R/', trim((string) @file_get_contents($status))) ?: [];

    return [trim($lines[0] ?? ''), trim(implode("\n", array_slice($lines, 1)))];
}

function swapWorkspace(): string
{
    $work = sys_get_temp_dir().'/ticktz-swap-work-'.bin2hex(random_bytes(6));
    mkdir($work, 0777, true);

    return $work;
}

/**
 * A stand-in for `artisan` that exits with the code it is given.
 *
 * A real PHP script rather than a placeholder, and the difference caught a
 * hole in this test: a file that is not PHP is still run by `php` without
 * complaint — it prints itself and exits 0 — so a fixture holding the words
 * "new artisan" made every migration appear to succeed.
 */
function fakeArtisan(int $exitCode, string $marker = ''): string
{
    return "<?php\n// {$marker}\nfwrite(STDERR, 'migrating'.PHP_EOL);\nexit({$exitCode});\n";
}

it('replaces the application and keeps the previous version', function () {
    $root = swapTree('live', [
        'app/Old.php' => 'old',
        'artisan' => fakeArtisan(0, 'old'),
        'composer.json' => '{"old":true}',
    ]);
    $new = swapTree('new', [
        'app/New.php' => 'new',
        'artisan' => fakeArtisan(0, 'new'),
        'composer.json' => '{"new":true}',
    ]);
    $work = swapWorkspace();

    [$state] = runSwap($root, $new, $work);

    expect($state)->toBe('installed')
        ->and(file_get_contents($root.'/artisan'))->toContain('// new')
        ->and(file_get_contents($root.'/composer.json'))->toBe('{"new":true}')
        ->and(is_file($root.'/app/New.php'))->toBeTrue()
        ->and(is_file($root.'/app/Old.php'))->toBeFalse()
        // The previous version is kept rather than deleted: it is what an
        // operator puts back when the new one turns out not to work.
        ->and(file_get_contents($work.'/previous/artisan'))->toContain('// old')
        ->and(is_file($work.'/previous/app/Old.php'))->toBeTrue();
});

it('reports a failed migration and says where the previous version is', function () {
    $root = swapTree('live', ['app/Old.php' => 'old', 'artisan' => fakeArtisan(0, 'old')]);
    $new = swapTree('new', ['app/New.php' => 'new', 'artisan' => fakeArtisan(1, 'new')]);
    $work = swapWorkspace();

    [$state, $line] = runSwap($root, $new, $work);

    expect($state)->toBe('failed')
        ->and($line)->toContain('migrations did not run')
        // Deliberately *not* rolled back. Migrations that failed halfway have
        // already touched the schema, and putting the old code back in front
        // of a half-migrated database is not a recovery — it is a second
        // broken state. The new code stays, the failure is loud, and the
        // message says where the previous version is.
        ->and($line)->toContain($work.'/previous')
        ->and(is_file($root.'/app/New.php'))->toBeTrue();
});

it('leaves alone what the release does not own', function () {
    $root = swapTree('live', [
        'app/Old.php' => 'old',
        'artisan' => fakeArtisan(0),
        '.env' => 'APP_KEY=secret',
        'storage/app/attachment.txt' => 'somebody-uploaded-this',
        'operator-script.sh' => 'mine',
    ]);
    $new = swapTree('new', ['app/New.php' => 'new', 'artisan' => fakeArtisan(0)]);

    runSwap($root, $new, swapWorkspace());

    expect(file_get_contents($root.'/.env'))->toBe('APP_KEY=secret')
        ->and(file_get_contents($root.'/storage/app/attachment.txt'))->toBe('somebody-uploaded-this')
        // Not in the owned list and not in the release: an upgrade that deleted
        // what it did not recognise would lose somebody's work.
        ->and(file_get_contents($root.'/operator-script.sh'))->toBe('mine');
});

it('puts everything back when the move fails halfway', function () {
    $root = swapTree('live', [
        'app/Old.php' => 'old',
        'config/app.php' => 'old config',
        'artisan' => fakeArtisan(0, 'old'),
    ]);
    $new = swapTree('new', ['app/New.php' => 'new', 'artisan' => fakeArtisan(0, 'new')]);
    $work = swapWorkspace();

    /*
     * Make the third entry impossible to move.
     *
     * A file where the backup of `config` has to go: renaming a directory onto
     * an existing file fails, and so does creating a directory there. The
     * script has moved `app` by then, which is the situation the rollback
     * exists for — a tree half taken apart.
     *
     * Provoked this way rather than with permissions because the test suite
     * runs as root often enough, and root can write into a directory it has
     * taken the write bit off. A test that only runs for some users is a test
     * for the property nobody checks.
     */
    mkdir($work.'/previous', 0755, true);
    file_put_contents($work.'/previous/config', 'in the way');

    [$state, $line] = runSwap($root, $new, $work);

    expect($state)->toBe('failed')
        ->and($line)->toContain('putting it back')
        // Whole again: what had already moved is back where it was, and
        // nothing from the release was installed over a half-dismantled tree.
        ->and(file_get_contents($root.'/app/Old.php'))->toBe('old')
        ->and(file_get_contents($root.'/config/app.php'))->toBe('old config')
        ->and(file_get_contents($root.'/artisan'))->toContain('// old')
        ->and(is_file($root.'/app/New.php'))->toBeFalse();
});

it('refuses to run where a process cannot be started', function () {
    expect(UpgradeSwap::isAvailable())->toBe(function_exists('exec'));
});
