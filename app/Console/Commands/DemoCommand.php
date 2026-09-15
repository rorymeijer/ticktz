<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Fills an instance with recognisable demo content so a new self-hoster can
 * click around before configuring anything.
 */
class DemoCommand extends Command
{
    protected $signature = 'ticktz:demo {--force : Allow running outside local/testing environments}';

    protected $description = 'Seed the instance with demo data (people, teams, organisations and sample tickets)';

    public function handle(): int
    {
        if ($this->getLaravel()->environment('production') && ! $this->option('force')) {
            $this->components->error('Refusing to seed demo data in production. Pass --force if you are certain.');

            return self::FAILURE;
        }

        $this->components->info('Seeding demo data…');

        $this->callSilent('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->newLine();
        $this->components->info('Demo data ready. Sign in with:');
        $this->table(
            ['Role', 'E-mail', 'Password'],
            [
                ['Administrator', 'rianne@ticktz.test', DemoSeeder::PASSWORD],
                ['Agent', 'joost@ticktz.test', DemoSeeder::PASSWORD],
                ['Requester', 'm.visser@zandvliet.test', DemoSeeder::PASSWORD],
            ],
        );

        return self::SUCCESS;
    }
}
