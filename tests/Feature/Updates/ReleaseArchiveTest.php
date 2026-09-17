<?php

declare(strict_types=1);

use App\Services\Updates\Release;
use App\Services\Updates\ReleaseArchive;
use App\Services\Updates\Version;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Build a release archive the way the workflow does, and hand back its bytes.
 *
 * Real zips rather than fixtures: what is being tested is whether the checks
 * would catch a bad one, and a fixture is a bad one somebody already decided
 * was fine.
 */
function makeArchive(array $build = [], array $omit = []): string
{
    $dir = sys_get_temp_dir().'/ticktz-archive-'.bin2hex(random_bytes(6));
    $tree = $dir.'/ticktz';

    mkdir($tree.'/vendor', 0777, true);
    mkdir($tree.'/public/build', 0777, true);

    $files = [
        'artisan' => "#!/usr/bin/env php\n",
        'composer.json' => '{"name":"laravel/laravel"}',
        'vendor/autoload.php' => "<?php\n",
        'public/build/manifest.json' => '{}',
        'build.json' => json_encode(array_merge([
            'product' => 'ticktz',
            'version' => '1.1.0',
            'commit' => 'abc123',
        ], $build)),
    ];

    foreach ($files as $path => $contents) {
        if (in_array($path, $omit, true)) {
            continue;
        }

        file_put_contents($tree.'/'.$path, $contents);
    }

    $zipPath = $dir.'/ticktz.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);

    foreach ($files as $path => $contents) {
        if (! in_array($path, $omit, true)) {
            $zip->addFile($tree.'/'.$path, 'ticktz/'.$path);
        }
    }

    $zip->close();

    $bytes = (string) file_get_contents($zipPath);
    exec('rm -rf '.escapeshellarg($dir));

    return $bytes;
}

function releaseWithAssets(string $version = '1.1.0'): Release
{
    Cache::put('ticktz:updates:latest', [
        'tag_name' => 'v'.$version,
        'name' => 'Ticktz '.$version,
        'body' => '',
        'html_url' => 'https://example.test',
        'published_at' => '2026-09-17T10:00:00Z',
        'tarball_url' => '',
        'assets' => [
            ['name' => "ticktz-{$version}.zip", 'browser_download_url' => 'https://example.test/a.zip', 'size' => 2048],
            ['name' => "ticktz-{$version}.zip.sha256", 'browser_download_url' => 'https://example.test/a.sha256'],
        ],
    ], now()->addHour());

    return new Release(
        version: Version::parse($version),
        name: 'Ticktz '.$version,
        notes: '',
        url: 'https://example.test',
        publishedAt: null,
        archiveUrl: '',
    );
}

/** Serve an archive and a digest, optionally lying about one of them. */
function serve(string $archive, ?string $digest = null): void
{
    Http::fake([
        'example.test/a.zip' => Http::response($archive, 200),
        'example.test/a.sha256' => Http::response($digest ?? hash('sha256', $archive), 200),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    $this->work = sys_get_temp_dir().'/ticktz-stage-'.bin2hex(random_bytes(6));
    $this->archive = app(ReleaseArchive::class);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->work));
});

// -----------------------------------------------------------------
// The happy path
// -----------------------------------------------------------------

it('downloads, checks and unpacks a release', function (): void {
    $bytes = makeArchive();
    serve($bytes);

    $tree = $this->archive->stage(releaseWithAssets(), $this->work);

    expect(is_file($tree.'/artisan'))->toBeTrue()
        ->and(is_file($tree.'/vendor/autoload.php'))->toBeTrue()
        ->and(is_dir($tree.'/public/build'))->toBeTrue()
        // The download is not left behind to be re-verified by nobody.
        ->and(is_file($this->work.'/release.zip'))->toBeFalse();
});

it('says what it is doing, step by step', function (): void {
    serve(makeArchive());

    $steps = [];
    $this->archive->stage(releaseWithAssets(), $this->work, function (string $line) use (&$steps): void {
        $steps[] = $line;
    });

    expect(implode(' | ', $steps))->toContain('Downloading')
        ->and(implode(' | ', $steps))->toContain('digest');
});

// -----------------------------------------------------------------
// What must be refused
// -----------------------------------------------------------------

/**
 * The digest answers one question — did the bytes change on the way — and it
 * is the one check standing between a truncated download and an instance
 * replacing itself with half a file.
 */
it('refuses an archive that does not match its digest', function (): void {
    serve(makeArchive(), hash('sha256', 'something else entirely'));

    expect(fn () => $this->archive->stage(releaseWithAssets(), $this->work))
        ->toThrow(RuntimeException::class, 'does not match its published digest');
});

it('refuses a digest that is not a digest', function (string $digest): void {
    serve(makeArchive(), $digest);

    expect(fn () => $this->archive->stage(releaseWithAssets(), $this->work))
        ->toThrow(RuntimeException::class, 'not a SHA-256');
})->with([
    'empty' => '',
    'an error page' => '<html>404</html>',
    'too short' => 'abc123',
]);

/**
 * An archive attached to the wrong release, by a mistake in a workflow or by
 * somebody editing a release by hand.
 */
it('refuses an archive that is a different version from the release', function (): void {
    $bytes = makeArchive(['version' => '1.0.9']);
    serve($bytes);

    expect(fn () => $this->archive->stage(releaseWithAssets('1.1.0'), $this->work))
        ->toThrow(RuntimeException::class, 'says it is 1.0.9');
});

it('refuses an archive that is not this product', function (): void {
    serve(makeArchive(['product' => 'something-else']));

    expect(fn () => $this->archive->stage(releaseWithAssets(), $this->work))
        ->toThrow(RuntimeException::class, 'does not say it is Ticktz');
});

/**
 * An archive missing any of these installs and then fails to boot — with the
 * old tree already moved aside.
 */
it('refuses an incomplete archive', function (string $missing): void {
    serve(makeArchive(omit: [$missing]));

    expect(fn () => $this->archive->stage(releaseWithAssets(), $this->work))
        ->toThrow(RuntimeException::class, 'not a complete release');
})->with(['artisan', 'vendor/autoload.php', 'build.json']);

it('refuses a release with nothing attached to it', function (): void {
    Http::fake();
    // The release first, because building it is what fills the cache; the
    // assets are taken away afterwards.
    $release = releaseWithAssets();
    Cache::put('ticktz:updates:latest', ['tag_name' => 'v1.1.0', 'assets' => []], now()->addHour());

    expect(fn () => $this->archive->stage($release, $this->work))
        ->toThrow(RuntimeException::class, 'no installable archive');

    Http::assertNothingSent();
});

it('refuses a release whose archive has no digest beside it', function (): void {
    Http::fake();
    $release = releaseWithAssets();
    Cache::put('ticktz:updates:latest', [
        'tag_name' => 'v1.1.0',
        'assets' => [['name' => 'ticktz-1.1.0.zip', 'browser_download_url' => 'https://example.test/a.zip', 'size' => 10]],
    ], now()->addHour());

    expect(fn () => $this->archive->stage($release, $this->work))
        ->toThrow(RuntimeException::class, 'no digest');

    // Refused before a byte is fetched: an archive nothing can check is not
    // worth downloading.
    Http::assertNothingSent();
});

it('refuses a download that arrives empty', function (): void {
    Http::fake([
        'example.test/a.zip' => Http::response('', 200),
        'example.test/a.sha256' => Http::response(hash('sha256', ''), 200),
    ]);

    expect(fn () => $this->archive->stage(releaseWithAssets(), $this->work))
        ->toThrow(RuntimeException::class);
});

it('says so when the archive cannot be fetched at all', function (): void {
    Http::fake([
        'example.test/a.zip' => Http::response('nope', 404),
        'example.test/a.sha256' => Http::response('x', 200),
    ]);

    expect(fn () => $this->archive->stage(releaseWithAssets(), $this->work))
        ->toThrow(RuntimeException::class, 'Could not download');
});
