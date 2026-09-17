<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * What has to be true before an install can succeed.
 *
 * Checked up front and shown all at once, rather than discovered one failure
 * at a time three screens in. Every entry says what is wrong in terms of the
 * thing an operator can act on — a missing extension names the extension, an
 * unwritable path names the path.
 *
 * The split between `required` and `recommended` is real: a required failure
 * blocks the install, a recommended one is a warning the operator can accept.
 * `ldap` is only needed by instances that authenticate against a directory,
 * and refusing to install without it would be wrong for everybody else.
 */
class RequirementsChecker
{
    private const PHP_FLOOR = '8.4.0';

    /**
     * Extensions without which this cannot run.
     *
     * Taken from what the dependency tree actually declares — the production
     * requires in `composer.lock`, plus `pdo_mysql`, which nothing declares
     * because no package knows which driver we chose.
     *
     * Not from the list everybody copies. `bcmath` was on that list here
     * until it was checked: nothing in the tree asks for it, no application
     * code calls it, and the full test suite passes without it. Listing it
     * would have blocked installs on servers that work perfectly.
     */
    private const REQUIRED_EXTENSIONS = [
        'ctype', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json',
        'libxml', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql',
        'session', 'tokenizer', 'zip',
    ];

    /**
     * Needed by a specific feature, named so the operator can judge.
     *
     * `ldap` sits here rather than above even though `ldaprecord` declares it:
     * by the time this screen renders, Composer has already installed, so an
     * instance that got this far without it is one that deliberately ignored
     * that requirement — and it will work fine as long as nobody configures a
     * directory.
     */
    private const OPTIONAL_EXTENSIONS = [
        'curl' => 'Faster outgoing HTTP for webhooks (falls back to streams)',
        'gd' => 'Image thumbnails for attachments',
        'intl' => 'Locale-aware dates and number formatting',
        'ldap' => 'Signing in against LDAP or Active Directory',
        'redis' => 'Redis cache, sessions and queues (the default)',
    ];

    /**
     * @return array{ok: bool, required: array<int, array<string, mixed>>, recommended: array<int, array<string, mixed>>}
     */
    public function check(): array
    {
        $required = [
            $this->result(
                'php',
                'PHP '.self::PHP_FLOOR.' or newer',
                version_compare(PHP_VERSION, self::PHP_FLOOR, '>='),
                PHP_VERSION,
            ),
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $required[] = $this->result(
                'ext.'.$extension,
                'PHP extension: '.$extension,
                extension_loaded($extension),
            );
        }

        foreach ($this->writablePaths() as $label => $path) {
            $required[] = $this->result(
                'writable.'.$label,
                $label,
                is_writable($path),
                $this->displayPath($path),
            );
        }

        $recommended = [];

        foreach (self::OPTIONAL_EXTENSIONS as $extension => $why) {
            $recommended[] = $this->result(
                'ext.'.$extension,
                'PHP extension: '.$extension,
                extension_loaded($extension),
                $why,
            );
        }

        return [
            'ok' => collect($required)->every(fn (array $item) => $item['ok']),
            'required' => $required,
            'recommended' => $recommended,
        ];
    }

    /**
     * The paths the installer itself has to write to. `.env` is checked
     * through its directory as well, because a missing file in a writable
     * directory is fine and `is_writable` on a path that does not exist is
     * not.
     *
     * @return array<string, string>
     */
    private function writablePaths(): array
    {
        $env = base_path('.env');

        return array_filter([
            'The .env file' => is_file($env) ? $env : base_path(),
            'storage/' => storage_path(),
            'storage/framework/' => storage_path('framework'),
            'storage/logs/' => storage_path('logs'),
            'bootstrap/cache/' => base_path('bootstrap/cache'),
        ], 'file_exists');
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $key, string $label, bool $ok, ?string $detail = null): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * Relative to the application root. An absolute container path tells an
     * operator less than `storage/logs` does, and on a shared host it leaks
     * the directory layout onto a page nobody has authenticated for.
     */
    private function displayPath(string $path): string
    {
        $base = base_path();

        return str_starts_with($path, $base) ? '.'.substr($path, strlen($base)) : basename($path);
    }
}
