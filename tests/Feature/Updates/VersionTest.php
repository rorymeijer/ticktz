<?php

declare(strict_types=1);

use App\Services\Updates\Version;

it('parses the shapes a release tag comes in', function (string $input, string $expected): void {
    expect((string) Version::parse($input))->toBe($expected);
})->with([
    'plain' => ['1.2.3', '1.2.3'],
    'tagged' => ['v1.2.3', '1.2.3'],
    'pre-release' => ['v1.1.0-beta.1', '1.1.0-beta.1'],
    'padded' => ['  v2.0.0  ', '2.0.0'],
    // Build metadata is explicitly not part of precedence in semver.
    'build metadata' => ['1.2.3+20260917', '1.2.3'],
]);

it('refuses anything that is not a version rather than guessing at one', function (?string $input): void {
    expect(Version::parse($input))->toBeNull();
})->with([null, '', 'latest', '1.2', 'v1', 'main', '1.2.3.4', 'dev-main']);

it('orders releases', function (): void {
    $order = ['1.0.0', '1.0.1', '1.0.10', '1.1.0', '2.0.0'];

    foreach ($order as $index => $value) {
        if ($index === 0) {
            continue;
        }

        $newer = Version::parse($value);
        $older = Version::parse($order[$index - 1]);

        expect($newer->isNewerThan($older))->toBeTrue()
            ->and($older->isNewerThan($newer))->toBeFalse();
    }
});

/**
 * 1.0.10 after 1.0.9 is where a string comparison quietly gets it wrong, and a
 * desk that is told it is up to date when it is nine patches behind has no way
 * of finding out.
 */
it('compares numbers as numbers', function (): void {
    expect(Version::parse('1.0.10')->isNewerThan(Version::parse('1.0.9')))->toBeTrue();
});

/** Semver: a pre-release comes *before* the release it leads to. */
it('puts a pre-release before its own release', function (): void {
    expect(Version::parse('1.1.0')->isNewerThan(Version::parse('1.1.0-beta.1')))->toBeTrue()
        ->and(Version::parse('1.1.0-beta.2')->isNewerThan(Version::parse('1.1.0-beta.1')))->toBeTrue()
        ->and(Version::parse('1.1.0-beta.1')->isPreRelease())->toBeTrue()
        ->and(Version::parse('1.1.0')->isPreRelease())->toBeFalse();
});

it('says nothing is newer than itself', function (): void {
    expect(Version::parse('1.2.3')->isNewerThan(Version::parse('1.2.3')))->toBeFalse()
        ->and(Version::parse('1.2.3')->compare(Version::parse('v1.2.3')))->toBe(0);
});

/**
 * What the screen leads with. Within a major an upgrade never needs a manual
 * step; across one it sometimes does, and that is the case where somebody has
 * to read something before pressing anything.
 */
it('names how big a step an upgrade is', function (string $from, string $to, string $step): void {
    expect(Version::parse($to)->stepFrom(Version::parse($from)))->toBe($step);
})->with([
    'patch' => ['1.0.1', '1.0.3', 'patch'],
    'minor' => ['1.0.3', '1.1.0', 'minor'],
    'major' => ['1.9.9', '2.0.0', 'major'],
    'major even when the minor also moves' => ['1.2.3', '2.1.0', 'major'],
]);
