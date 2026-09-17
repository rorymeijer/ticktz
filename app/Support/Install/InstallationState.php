<?php

declare(strict_types=1);

namespace App\Support\Install;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Throwable;

/**
 * Whether this instance has been set up yet.
 *
 * The answer has to be available before the database is known to exist, so it
 * lives in a file rather than a settings row: on a first boot there may be no
 * connection to read a setting from, and asking a database that is not there
 * is how the installer would deadlock on itself.
 *
 * Two ways to be installed:
 *
 *  - **The marker file exists.** Written at the end of a successful install,
 *    by either the wizard or `php artisan ticktz:install`.
 *  - **The instance is obviously already running.** A database with the
 *    migrations applied and at least one user in it was installed before this
 *    feature existed. Upgrading must not drop somebody's working desk into a
 *    setup wizard, so that case adopts the marker instead of asking.
 *
 * The second rule is the one that matters for anybody upgrading, and it is
 * deliberately conservative: *migrations applied* and *a user exists*, not one
 * or the other. A half-migrated database with no accounts is a failed install,
 * and the wizard is exactly what that wants.
 */
final class InstallationState
{
    /**
     * Cached for the request. Every route consults this through middleware, so
     * it must not cost a file stat and a database round trip per check.
     */
    private static ?bool $installed = null;

    public static function path(): string
    {
        return storage_path('app/ticktz-installed.json');
    }

    public static function isInstalled(): bool
    {
        if (self::$installed !== null) {
            return self::$installed;
        }

        if (is_file(self::path())) {
            return self::$installed = true;
        }

        if (self::looksAlreadyRunning()) {
            // Adopt it, so the check costs a file stat from here on rather
            // than a database query on every request.
            self::markInstalled(['adopted' => true]);

            return self::$installed = true;
        }

        return self::$installed = false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function markInstalled(array $context = []): void
    {
        $payload = $context + [
            'installed_at' => now()->toIso8601String(),
            'version' => config('ticktz.version'),
        ];

        @mkdir(dirname(self::path()), 0775, true);

        try {
            file_put_contents(self::path(), json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (JsonException) {
            file_put_contents(self::path(), '{"installed_at":"'.now()->toIso8601String().'"}');
        }

        self::$installed = true;
    }

    /**
     * Only for tests and for `ticktz:install --force`. There is deliberately
     * no way to reach this from the web: re-opening the installer on a live
     * instance would hand an unauthenticated visitor the database credentials
     * form.
     */
    public static function forget(): void
    {
        if (is_file(self::path())) {
            @unlink(self::path());
        }

        self::$installed = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function details(): ?array
    {
        if (! is_file(self::path())) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents(self::path()), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * A database that has been migrated and has a user in it.
     *
     * Every failure mode here — no connection, no such database, no tables —
     * means "not installed", so the whole thing is wrapped rather than
     * checked step by step. An exception escaping this method would take down
     * every request on an instance whose database is merely unreachable.
     */
    private static function looksAlreadyRunning(): bool
    {
        try {
            DB::connection()->getPdo();

            if (! Schema::hasTable('users') || ! Schema::hasTable('migrations')) {
                return false;
            }

            return User::query()->withTrashed()->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
