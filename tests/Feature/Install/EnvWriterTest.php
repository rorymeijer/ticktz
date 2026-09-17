<?php

declare(strict_types=1);

use App\Services\Install\EnvWriter;

beforeEach(function (): void {
    $this->file = sys_get_temp_dir().'/ticktz-env-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    @unlink($this->file);
});

function envFixture(string $path, string $contents): EnvWriter
{
    file_put_contents($path, $contents);

    return new EnvWriter($path);
}

it('replaces a value without disturbing anything else', function (): void {
    $writer = envFixture($this->file, <<<'ENV'
        # A comment somebody wrote
        APP_NAME=Ticktz
        DB_HOST=mysql

        # Injected by our platform, nothing to do with Ticktz
        OTEL_EXPORTER=http://collector:4318
        ENV);

    $writer->write(['DB_HOST' => 'db.internal']);

    $result = file_get_contents($this->file);

    expect($result)->toContain('DB_HOST=db.internal')
        ->and($result)->toContain('# A comment somebody wrote')
        ->and($result)->toContain('APP_NAME=Ticktz')
        // The key nobody told us about has to survive. Rewriting the file from
        // a template would drop it.
        ->and($result)->toContain('OTEL_EXPORTER=http://collector:4318');
});

it('appends a key that was not there', function (): void {
    $writer = envFixture($this->file, "APP_NAME=Ticktz\n");

    $writer->write(['TICKTZ_NEW_SETTING' => 'yes']);

    expect(file_get_contents($this->file))
        ->toContain('APP_NAME=Ticktz')
        ->toContain('TICKTZ_NEW_SETTING=yes');
});

it('quotes a value that would otherwise be misread', function (): void {
    $writer = envFixture($this->file, "DB_PASSWORD=old\n");

    // A '#' starts a comment and a space truncates: both give an instance that
    // cannot reach its database, with an error blaming the credentials.
    $writer->write(['DB_PASSWORD' => 'p@ss #1 with spaces']);

    expect(file_get_contents($this->file))->toContain('DB_PASSWORD="p@ss #1 with spaces"');
});

it('round-trips every awkward password through a real dotenv parse', function (): void {
    $passwords = [
        'simple',
        'with spaces',
        'hash#inside',
        'quote"inside',
        "single'inside",
        'dollar$sign',
        'back\\slash',
        'back`tick',
        'amp&pipe|',
        'paren(s)',
        'lt<gt>',
        '#leading-hash',
        'trailing ',
    ];

    foreach ($passwords as $password) {
        $writer = envFixture($this->file, "DB_PASSWORD=placeholder\n");
        $writer->write(['DB_PASSWORD' => $password]);

        // Parsed with the same library Laravel boots with, not with our own
        // reader — agreeing with ourselves proves nothing.
        $parsed = Dotenv\Dotenv::parse((string) file_get_contents($this->file));

        expect($parsed['DB_PASSWORD'] ?? null)->toBe($password, "password: {$password}");
    }
});

it('never leaves the file world-readable', function (): void {
    $writer = envFixture($this->file, "APP_KEY=secret\n");
    chmod($this->file, 0644);

    $writer->write(['DB_PASSWORD' => 'hunter2']);

    expect(substr(sprintf('%o', fileperms($this->file)), -3))->toBe('600');
});

it('refuses rather than silently doing nothing when the file cannot be written', function (): void {
    $writer = envFixture($this->file, "APP_NAME=Ticktz\n");
    chmod($this->file, 0400);

    expect(fn () => $writer->write(['APP_NAME' => 'Other']))
        ->toThrow(RuntimeException::class);
})->skip(fn () => posix_geteuid() === 0, 'root ignores the read-only bit');

it('reads values back', function (): void {
    $writer = envFixture($this->file, "APP_NAME=\"My Desk\"\nDB_PORT=3306\n# DB_HOST=commented\n");

    expect($writer->read())->toMatchArray(['APP_NAME' => 'My Desk', 'DB_PORT' => '3306'])
        ->and($writer->read())->not->toHaveKey('DB_HOST');
});
