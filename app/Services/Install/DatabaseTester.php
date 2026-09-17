<?php

declare(strict_types=1);

namespace App\Services\Install;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Tries a database connection and says what went wrong in words.
 *
 * The whole point is that an operator finds out the password is wrong *now*,
 * on the screen where they typed it, rather than after the installer has
 * rewritten `.env` and the container is restarting into a broken state.
 *
 * So the connection is made on a throwaway named connection rather than by
 * mutating the default one. A failed test must leave the application exactly
 * as it was; nothing here is allowed to be a side effect.
 */
class DatabaseTester
{
    private const CONNECTION = 'ticktz_install_probe';

    /**
     * MySQL's own error codes, turned into something an operator can act on.
     * The raw driver message names an internal hostname and the server
     * version, which is more than an unauthenticated setup page should say out
     * loud — and less use than "the password is wrong".
     */
    private const REASONS = [
        1045 => 'credentials',
        1049 => 'unknown_database',
        // 1044 is what MariaDB and MySQL return when the database does not
        // exist *and* the user has no global privileges — the server will not
        // tell an unprivileged client which of the two it is, deliberately. So
        // this one reason has to carry both meanings, and its message says so.
        1044 => 'no_access',
        2005 => 'unknown_host',
        2006 => 'unreachable',
    ];

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, reason: string, detail: string|null, server: string|null, writable: bool, empty: bool}
     */
    public function test(array $credentials): array
    {
        Config::set('database.connections.'.self::CONNECTION, [
            'driver' => 'mysql',
            'host' => (string) ($credentials['host'] ?? '127.0.0.1'),
            'port' => (string) ($credentials['port'] ?? 3306),
            'database' => (string) ($credentials['database'] ?? ''),
            'username' => (string) ($credentials['username'] ?? ''),
            'password' => (string) ($credentials['password'] ?? ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                // Fail fast. A wrong host would otherwise hold the request
                // open for the driver's default timeout, and an installer that
                // appears to hang is one people reload and double-submit.
                \PDO::ATTR_TIMEOUT => 5,
            ],
        ]);

        DB::purge(self::CONNECTION);

        try {
            $connection = DB::connection(self::CONNECTION);
            $connection->getPdo();

            $server = (string) $connection->selectOne('SELECT VERSION() AS v')->v;
            $tables = $connection->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?',
                [$credentials['database'] ?? ''],
            );

            return [
                'ok' => true,
                'reason' => 'connected',
                'detail' => null,
                'server' => $server,
                // Reported rather than enforced: installing into a database
                // that already has tables is usually a mistake (pointing at
                // the wrong schema), but it is legitimately what a re-install
                // over a wiped instance looks like. The screen warns; the
                // operator decides.
                'empty' => (int) $tables->c === 0,
                'writable' => $this->canCreateTables($connection),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'reason' => $this->reasonFor($exception),
                'detail' => $this->safeDetail($exception),
                'server' => null,
                'empty' => false,
                'writable' => false,
            ];
        } finally {
            DB::purge(self::CONNECTION);
            Config::set('database.connections.'.self::CONNECTION, null);
        }
    }

    /**
     * Connecting proves the credentials; it does not prove the grant. A user
     * with SELECT and nothing else connects happily and then fails on the
     * first migration, which is a much worse place to find out.
     */
    private function canCreateTables(Connection $connection): bool
    {
        $probe = 'ticktz_install_probe_'.bin2hex(random_bytes(4));

        try {
            $connection->statement("CREATE TABLE `{$probe}` (id INT)");
            $connection->statement("DROP TABLE `{$probe}`");

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function reasonFor(Throwable $exception): string
    {
        $code = $exception instanceof PDOException ? (int) ($exception->errorInfo[1] ?? 0) : 0;
        $message = strtolower($exception->getMessage());

        // The message is consulted before the code table, because 2002 covers
        // two very different problems — a name that does not resolve and a
        // port that refused — and telling somebody to check their firewall
        // when their hostname is misspelled sends them the wrong way.
        if (str_contains($message, 'getaddrinfo') || str_contains($message, 'name or service not known')
            || str_contains($message, 'nodename nor servname')) {
            return 'unknown_host';
        }

        if (isset(self::REASONS[$code])) {
            return self::REASONS[$code];
        }

        // Not every driver failure carries a code.
        return match (true) {
            str_contains($message, 'access denied') => 'credentials',
            str_contains($message, 'unknown database') => 'unknown_database',
            str_contains($message, 'connection refused'), str_contains($message, 'timed out'),
            str_contains($message, "can't connect") => 'unreachable',
            default => 'failed',
        };
    }

    /**
     * A trimmed driver message, for the cases the codes above do not cover.
     *
     * Kept short and only shown for `failed`: the full message names the
     * server version and internal hostnames, which is more than a page nobody
     * has authenticated for should volunteer.
     */
    private function safeDetail(Throwable $exception): ?string
    {
        $reason = $this->reasonFor($exception);

        if ($reason !== 'failed') {
            return null;
        }

        return mb_substr(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '', 0, 200);
    }
}
