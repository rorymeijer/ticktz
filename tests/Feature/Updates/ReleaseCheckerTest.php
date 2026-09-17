<?php

declare(strict_types=1);

use App\Services\Updates\ReleaseChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<int, array<string, mixed>>  $releases
 */
function githubReturns(array $releases): void
{
    Http::fake(['api.github.com/*' => Http::response($releases)]);
}

function release(string $tag, array $overrides = []): array
{
    return array_merge([
        'tag_name' => $tag,
        'name' => "Ticktz {$tag}",
        'body' => "What changed in {$tag}.",
        'html_url' => "https://github.com/rorymeijer/ticktz/releases/tag/{$tag}",
        'published_at' => '2026-09-17T10:00:00Z',
        'tarball_url' => "https://api.github.com/repos/rorymeijer/ticktz/tarball/{$tag}",
        'draft' => false,
        'prerelease' => false,
    ], $overrides);
}

beforeEach(function (): void {
    Cache::flush();
    config(['ticktz.version' => '1.0.2', 'ticktz.updates.enabled' => true]);
});

// -----------------------------------------------------------------
// Off is off
// -----------------------------------------------------------------

/**
 * The only outbound request Ticktz would make that nobody configured. The
 * brief says no telemetry and no third-party trackers, so this one has to be
 * genuinely silent when it is off — not merely quiet about the answer.
 */
it('makes no request at all when the check is switched off', function (): void {
    config(['ticktz.updates.enabled' => false]);
    Http::fake();

    expect(app(ReleaseChecker::class)->latest())->toBeNull();

    Http::assertNothingSent();
});

it('sends nothing about this instance when it is on', function (): void {
    githubReturns([release('v1.1.0')]);

    app(ReleaseChecker::class)->latest();

    Http::assertSent(function ($request): bool {
        $body = (string) $request->body();
        $url = $request->url();

        return $request->method() === 'GET'
            && $body === ''
            && ! str_contains($url, '1.0.2')
            && ! $request->hasHeader('Authorization');
    });
});

// -----------------------------------------------------------------
// What it picks
// -----------------------------------------------------------------

it('finds the newest release', function (): void {
    githubReturns([release('v1.0.9'), release('v1.1.0'), release('v1.0.10')]);

    $latest = app(ReleaseChecker::class)->latest();

    expect((string) $latest->version)->toBe('1.1.0')
        ->and($latest->notes)->toBe('What changed in v1.1.0.');
});

/**
 * Not `/releases/latest`: that orders by publication date, so a release
 * republished after a fix would outrank a newer one.
 */
it('orders by version, not by publication date', function (): void {
    githubReturns([
        release('v1.0.9', ['published_at' => '2026-09-17T12:00:00Z']),
        release('v1.1.0', ['published_at' => '2026-09-01T09:00:00Z']),
    ]);

    expect((string) app(ReleaseChecker::class)->latest()->version)->toBe('1.1.0');
});

it('ignores a draft', function (): void {
    githubReturns([release('v2.0.0', ['draft' => true]), release('v1.1.0')]);

    expect((string) app(ReleaseChecker::class)->latest()->version)->toBe('1.1.0');
});

it('ignores a pre-release unless somebody asked for them', function (): void {
    githubReturns([release('v1.1.0-beta.1', ['prerelease' => true]), release('v1.0.3')]);

    expect((string) app(ReleaseChecker::class)->latest()->version)->toBe('1.0.3');

    config(['ticktz.updates.pre_releases' => true]);
    Cache::flush();

    expect((string) app(ReleaseChecker::class)->latest()->version)->toBe('1.1.0-beta.1');
});

it('ignores a tag that is not a version', function (): void {
    githubReturns([release('nightly'), release('v1.1.0')]);

    expect((string) app(ReleaseChecker::class)->latest()->version)->toBe('1.1.0');
});

// -----------------------------------------------------------------
// When it cannot ask
// -----------------------------------------------------------------

/**
 * An instance with no outbound access is a normal instance, not a broken one.
 */
it('says nothing rather than failing when github cannot be reached', function (): void {
    Http::fake(['api.github.com/*' => Http::response('', 503)]);

    expect(app(ReleaseChecker::class)->latest())->toBeNull();
});

it('survives a connection that never opens', function (): void {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    expect(app(ReleaseChecker::class)->latest())->toBeNull();
});

// -----------------------------------------------------------------
// Is it an upgrade
// -----------------------------------------------------------------

it('knows an older release is not an upgrade', function (string $version, bool $expected): void {
    config(['ticktz.version' => '1.0.2']);
    githubReturns([release('v'.$version)]);

    $checker = app(ReleaseChecker::class);

    expect($checker->isUpgrade($checker->latest()))->toBe($expected);
})->with([
    'newer patch' => ['1.0.3', true],
    'newer minor' => ['1.1.0', true],
    'the same' => ['1.0.2', false],
    'older' => ['1.0.1', false],
]);

it('asks once and remembers, so four administrators are one request', function (): void {
    githubReturns([release('v1.1.0')]);

    $checker = app(ReleaseChecker::class);
    $checker->latest();
    $checker->latest();
    $checker->latest();

    Http::assertSentCount(1);
});
