<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;

test('the command creates the first administrator', function () {
    seedRbac();

    $this->artisan('ticktz:admin', [
        '--name' => 'Eerste Beheerder',
        '--email' => 'beheer@example.org',
        '--password' => 'correct-horse-battery-1',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'beheer@example.org')->sole();

    expect($user->isAdmin())->toBeTrue()
        ->and($user->isSuperAdmin())->toBeTrue()
        ->and($user->is_active)->toBeTrue();
});

test('the command promotes an existing account', function () {
    seedRbac();
    $user = User::factory()->requester()->create(['email' => 'iemand@example.org']);

    $this->artisan('ticktz:admin', ['--email' => 'iemand@example.org', '--promote' => true])
        ->assertSuccessful();

    expect($user->fresh()->hasRole(Role::ADMIN))->toBeTrue();
});

test('the command refuses a weak password', function () {
    seedRbac();

    $this->artisan('ticktz:admin', [
        '--name' => 'Zwak',
        '--email' => 'zwak@example.org',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('the command explains itself when the roles have not been seeded', function () {
    $this->artisan('ticktz:admin', ['--email' => 'x@example.org'])->assertFailed();
});
