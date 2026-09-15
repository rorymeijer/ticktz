<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Baseline seed for any Ticktz instance: permissions, roles and settings.
 *
 * It is idempotent — running it against a live instance is safe and is in fact
 * how an upgrade picks up new permissions. Demo content lives in
 * DemoSeeder (php artisan ticktz:demo) and is never part of this seeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SettingsSeeder::class,
        ]);
    }
}
