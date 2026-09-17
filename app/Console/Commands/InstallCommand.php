<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Install\DatabaseChoice;
use App\Services\Install\Installer;
use App\Support\Install\InstallationState;
use Illuminate\Console\Command;
use Throwable;

/**
 * The installer, for people and platforms that do not want a browser.
 *
 * Same service as the wizard, so there is one install path and not two that
 * drift. Interactive when run by hand, fully non-interactive when every option
 * is supplied — which is what an Ansible role or a first-boot script needs.
 */
class InstallCommand extends Command
{
    protected $signature = 'ticktz:install
        {--name= : What to call this service desk}
        {--url= : The address people reach it on}
        {--locale= : Default language (en, nl)}
        {--timezone= : IANA time zone}
        {--prefix= : Ticket key prefix, e.g. SUP}
        {--db-host=} {--db-port=} {--db-name=} {--db-user=} {--db-password=}
        {--admin-name=} {--admin-email=} {--admin-password=}
        {--force : Install again over an instance that is already set up}';

    protected $description = 'Set this instance up: configure the database, migrate, and create an administrator';

    public function handle(Installer $installer): int
    {
        if (InstallationState::isInstalled() && ! $this->option('force')) {
            $this->components->error('This instance is already installed. Pass --force to run it again.');
            $this->line('  Re-running writes .env and re-applies migrations. Take a backup first.');

            return self::FAILURE;
        }

        $this->components->info('Installing Ticktz '.config('ticktz.version'));

        $database = [
            'host' => $this->value('db-host', 'Database host', (string) env('DB_HOST', '127.0.0.1')),
            'port' => $this->value('db-port', 'Database port', (string) env('DB_PORT', '3306')),
            'database' => $this->value('db-name', 'Database name', (string) env('DB_DATABASE', 'ticktz')),
            'username' => $this->value('db-user', 'Database username', (string) env('DB_USERNAME', 'ticktz')),
            'password' => $this->hidden('db-password', 'Database password', (string) env('DB_PASSWORD', '')),
        ];

        $payload = [
            'database' => $database,
            'database_choice' => DatabaseChoice::bundledIsAvailable() && $database['host'] === env('DB_HOST')
                ? DatabaseChoice::Bundled->value
                : DatabaseChoice::External->value,
            'app' => [
                'name' => $this->value('name', 'Name of this service desk', (string) config('app.name')),
                'url' => rtrim($this->value('url', 'Address (including https)', (string) config('app.url')), '/'),
                'locale' => $this->value('locale', 'Default language', (string) config('app.locale')),
                'timezone' => $this->value('timezone', 'Time zone', (string) config('app.timezone')),
                'ticket_prefix' => $this->value('prefix', 'Ticket prefix', 'SUP'),
            ],
            'admin' => [
                'name' => $this->value('admin-name', 'Your name', ''),
                'email' => $this->value('admin-email', 'Your e-mail address', ''),
                'password' => $this->hidden('admin-password', 'Choose a password (12+ characters)', ''),
            ],
            // Mail is left to the mailbox screen. Asking for SMTP credentials
            // on a command line is a good way to put them in a shell history.
            'mail' => ['enabled' => false],
        ];

        foreach (['name' => 'admin.name', 'email' => 'admin.email', 'password' => 'admin.password'] as $field => $label) {
            if (($payload['admin'][$field] ?? '') === '') {
                $this->components->error("Missing {$label}.");

                return self::FAILURE;
            }
        }

        if (mb_strlen($payload['admin']['password']) < 12) {
            $this->components->error('The administrator password must be at least 12 characters.');

            return self::FAILURE;
        }

        try {
            $result = $this->components->task(
                'Configuring, migrating and seeding',
                fn () => (bool) $installer->run($payload),
            );
        } catch (Throwable $exception) {
            $this->newLine();
            $this->components->error($this->explain($exception));

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Ticktz is installed.');
        $this->components->bulletList([
            'Sign in at '.$payload['app']['url'].'/login as '.$payload['admin']['email'],
            'Set up mailboxes, service levels and request types under Administration',
            'Keep .env safe: APP_KEY decrypts the credentials stored in the database',
        ]);

        return $result === false ? self::FAILURE : self::SUCCESS;
    }

    private function value(string $option, string $question, string $default): string
    {
        $given = $this->option($option);

        if ($given !== null && $given !== '') {
            return (string) $given;
        }

        // Non-interactive and nothing supplied: fall back rather than hang.
        // A first-boot script blocked on a hidden prompt is indistinguishable
        // from a crash.
        if (! $this->input->isInteractive()) {
            return $default;
        }

        return (string) $this->ask($question, $default !== '' ? $default : null);
    }

    private function hidden(string $option, string $question, string $default): string
    {
        $given = $this->option($option);

        if ($given !== null && $given !== '') {
            return (string) $given;
        }

        if (! $this->input->isInteractive()) {
            return $default;
        }

        return (string) ($this->components->secret($question) ?? $default);
    }

    private function explain(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_starts_with($message, 'database:')) {
            return match (substr($message, 9)) {
                'credentials' => 'The database rejected that username or password.',
                'unknown_database' => 'No database with that name. Create it first — Ticktz will not.',
                'no_access' => 'That user has no access to that database.',
                'unreachable' => 'Nothing answered on that host and port.',
                'unknown_host' => 'That hostname could not be resolved.',
                default => 'The database connection failed.',
            };
        }

        return $message;
    }
}
