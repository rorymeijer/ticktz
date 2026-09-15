<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Seed the permission catalogue, the three system roles and the default
 * settings. Anything touching RBAC needs this first.
 */
function seedRbac(): void
{
    app(PermissionSeeder::class)->run();
    app(RoleSeeder::class)->run();
    app(SettingsSeeder::class)->run();
}

function makeAdmin(array $attributes = []): User
{
    seedRbac();

    return User::factory()->admin()->create($attributes)->fresh();
}

function makeAgent(array $attributes = []): User
{
    seedRbac();

    return User::factory()->agent()->create($attributes)->fresh();
}

function makeRequester(array $attributes = []): User
{
    seedRbac();

    return User::factory()->requester()->create($attributes)->fresh();
}

/**
 * A user holding exactly the given permissions and nothing else — the fastest
 * way to assert that an endpoint really checks the permission it claims to.
 */
function makeUserWithPermissions(string ...$permissions): User
{
    seedRbac();

    $role = Role::factory()->create(['scope' => 'agent']);
    $role->syncPermissionNames($permissions);

    $user = User::factory()->create();
    $user->roles()->attach($role);

    return $user->fresh();
}
