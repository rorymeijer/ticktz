<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * Where this instance keeps its data.
 *
 * Two answers, and the difference is who runs the server rather than anything
 * technical:
 *
 * **Bundled** — the MySQL container that ships with the Docker stack. Its
 * credentials are already in the environment before anybody opens a browser,
 * so the installer fills them in, tests them, and gets out of the way. This is
 * the right answer for most self-hosters and it should feel like no decision
 * at all.
 *
 * **External** — somebody else's MySQL: a managed instance, a cluster, the
 * database server the organisation already backs up. Every field is asked for
 * and the connection is tested before anything is written.
 *
 * The bundled option is only *offered* when there is visibly a bundled server
 * to point at, which is what `isAvailable()` decides. Offering it outside
 * Docker would be offering an instance that cannot start.
 */
enum DatabaseChoice: string
{
    case Bundled = 'bundled';
    case External = 'external';

    /**
     * Whether the environment looks like it has a database container waiting.
     *
     * The compose files set `DB_HOST=mysql` and inject the credentials, so a
     * host that is not a loopback address plus a password already present is
     * the signal. Deliberately conservative: a false negative means the
     * operator fills in four fields they could have skipped, while a false
     * positive means the installer recommends a database that is not there.
     */
    public static function bundledIsAvailable(): bool
    {
        $host = (string) env('DB_HOST', '');

        if ($host === '' || in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return false;
        }

        return (string) env('DB_PASSWORD', '') !== '' && (string) env('DB_DATABASE', '') !== '';
    }

    /**
     * What the bundled database is, for showing on the screen.
     *
     * The password is deliberately absent. This is served to an
     * unauthenticated page — there is nobody to authenticate as yet — and a
     * live database password in that HTML would be a secret handed to whoever
     * reaches the installer first. The rest is not secret: it is the compose
     * file, which ships in the repository.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '3306'),
            'database' => (string) env('DB_DATABASE', 'ticktz'),
            'username' => (string) env('DB_USERNAME', 'ticktz'),
        ];
    }

    /**
     * What to actually connect with, read on the server and never sent out.
     *
     * The bundled database is not the operator's to configure: the compose
     * file created it, named it and set its password, and every one of those
     * values is already in this process's environment. Asking somebody to
     * retype them is asking them to reproduce something the server can simply
     * read — five chances to make a typo, and one of them, the password, was
     * not even shown to them to copy.
     *
     * @return array<string, string>
     */
    public static function bundledCredentials(): array
    {
        return self::defaults() + ['password' => (string) env('DB_PASSWORD', '')];
    }
}
