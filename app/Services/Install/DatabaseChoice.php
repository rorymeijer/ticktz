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
        $host = self::connection('host');

        if ($host === '' || in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return false;
        }

        return self::connection('password') !== '' && self::connection('database') !== '';
    }

    /**
     * One value of the connection this instance is configured with.
     *
     * The configuration rather than `env()`, and the difference is not
     * academic. In production the boot compiles the configuration, and Laravel
     * skips loading `.env` entirely when it finds a compiled one — so `env()`
     * answers null for everything that is not also a real environment
     * variable. Since 1.1.7 the database settings are deliberately not real
     * environment variables: they are written into `.env` so that this very
     * wizard can change them. Asked through `env()`, the bundled database
     * would look absent on every production instance and the option would not
     * be offered at all.
     */
    private static function connection(string $key): string
    {
        return (string) config("database.connections.mysql.{$key}", '');
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
            'host' => self::connection('host') ?: '127.0.0.1',
            'port' => self::connection('port') ?: '3306',
            'database' => self::connection('database') ?: 'ticktz',
            'username' => self::connection('username') ?: 'ticktz',
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
        return self::defaults() + ['password' => self::connection('password')];
    }
}
