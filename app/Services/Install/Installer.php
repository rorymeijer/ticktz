<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Models\Role;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Support\Install\InstallationState;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SlaSeeder;
use Database\Seeders\TicketWorkflowSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns the answers from the wizard into a running instance.
 *
 * The order is the whole design. Every step that can fail happens before the
 * first durable change to the filesystem, so a failure leaves nothing to clean
 * up:
 *
 *   1. test the database             (fails harmlessly)
 *   2. point this process at it      (in memory only — no file touched)
 *   3. migrate                       (fails harmlessly: nothing else changed)
 *   4. seed the base data
 *   5. create the administrator
 *   6. write .env                    (first durable change)
 *   7. mark it installed             (last)
 *
 * Writing `.env` last rather than before the migrations is deliberate, and was
 * learned the hard way. Anything watching that file — `artisan serve`, a
 * config reloader, a supervisor with a watch — restarts the process the moment
 * it changes, and a restart in the middle of step 3 leaves a half-migrated
 * database, no marker, and a wizard that will try again against a database
 * that is no longer empty.
 *
 * With the write at the end, the worst case is a fully prepared database and a
 * configuration that has to be written by hand, which is recoverable. The
 * other way round is not.
 */
class Installer
{
    public function __construct(
        private readonly DatabaseTester $tester,
        private readonly EnvWriter $env,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{key: string, admin: User}
     */
    public function run(array $payload): array
    {
        $database = $payload['database'];

        $result = $this->tester->test($database);

        if (! $result['ok']) {
            throw new RuntimeException('database:'.$result['reason']);
        }

        // Seeding writes settings, and the settings repository caches. The
        // default cache is Redis, which at install time is a hostname nobody
        // has verified — on a first run outside Docker it is usually not there
        // at all. The install must not depend on a service it is not
        // configuring, so it runs against an in-memory store and leaves the
        // real cache to the operator's .env.
        Config::set('cache.default', 'array');
        Config::set('session.driver', 'array');
        Config::set('queue.default', 'sync');
        Cache::purge();

        $key = $this->applicationKey();

        // Point this request at the new database without writing anything.
        // Everything up to the .env write happens in memory, so a failure
        // here leaves the instance exactly as it was found.
        $this->useDatabase($database);
        Config::set('app.key', $key);
        Config::set('app.name', $payload['app']['name']);
        Config::set('app.url', $payload['app']['url']);

        Artisan::call('migrate', ['--force' => true]);

        $this->seedBaseData();

        $admin = $this->createAdministrator($payload['admin']);

        $this->applySettings($payload['app']);

        // Everything that could fail has not. Now make it permanent.
        $this->env->write($this->environment($payload, $key));

        InstallationState::markInstalled([
            'database' => $payload['database_choice'] ?? 'external',
            'installed_by' => $admin->email,
        ]);

        return ['key' => $key, 'admin' => $admin];
    }

    /**
     * Reuse the existing key when there is one.
     *
     * Generating a fresh key on an instance that already had one would make
     * every previously encrypted value — mailbox and directory passwords —
     * unreadable. That only matters on a re-install over an existing `.env`,
     * which is exactly the case somebody hits when their first attempt failed.
     */
    private function applicationKey(): string
    {
        $existing = (string) ($this->env->read()['APP_KEY'] ?? '');

        if (str_starts_with($existing, 'base64:') && strlen($existing) > 20) {
            return $existing;
        }

        return 'base64:'.base64_encode(
            Str::random(32)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string|bool|null>
     */
    private function environment(array $payload, string $key): array
    {
        $app = $payload['app'];
        $database = $payload['database'];
        $mail = $payload['mail'] ?? null;

        $values = [
            'APP_NAME' => $app['name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => false,
            'APP_URL' => rtrim((string) $app['url'], '/'),
            'APP_LOCALE' => $app['locale'],
            'APP_TIMEZONE' => $app['timezone'],

            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'] ?? '',
        ];

        if ($mail !== null && ($mail['enabled'] ?? false)) {
            $values += [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => $mail['host'],
                'MAIL_PORT' => (string) $mail['port'],
                'MAIL_USERNAME' => $mail['username'] ?? '',
                'MAIL_PASSWORD' => $mail['password'] ?? '',
                'MAIL_SCHEME' => $mail['scheme'] ?? '',
                'MAIL_FROM_ADDRESS' => $mail['from_address'],
                'MAIL_FROM_NAME' => $app['name'],
            ];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $database
     */
    private function useDatabase(array $database): void
    {
        Config::set('database.connections.mysql', array_merge(
            config('database.connections.mysql'),
            [
                'host' => $database['host'],
                'port' => (string) $database['port'],
                'database' => $database['database'],
                'username' => $database['username'],
                'password' => (string) ($database['password'] ?? ''),
            ],
        ));

        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    /**
     * The data an empty instance cannot function without: the permission
     * catalogue, the three system roles, the default settings, the ticket
     * vocabulary and a starting SLA policy.
     *
     * Not the demo data. An instance somebody is about to put real tickets in
     * should not arrive carrying fictional ones.
     */
    private function seedBaseData(): void
    {
        foreach ([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class,
            TicketWorkflowSeeder::class, SlaSeeder::class] as $seeder) {
            app($seeder)->run();
        }
    }

    /**
     * @param  array<string, mixed>  $admin
     */
    private function createAdministrator(array $admin): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $admin['email']],
            [
                'name' => $admin['name'],
                'password' => Hash::make($admin['password']),
                'locale' => $admin['locale'] ?? config('app.locale'),
                'directory' => 'local',
                'is_active' => true,
            ],
        );

        // Not mass-assignable, and deliberately so — verification state is not
        // something a form should be able to set. The first administrator is
        // verified by construction: they are the person who just proved they
        // control this server.
        $user->forceFill(['email_verified_at' => now()])->save();

        $user->syncRoleNames([Role::ADMIN]);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function applySettings(array $app): void
    {
        app(SettingsRepository::class)->setMany(array_filter([
            'portal.title' => $app['name'],
            'tickets.key_prefix' => $app['ticket_prefix'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));
    }
}
