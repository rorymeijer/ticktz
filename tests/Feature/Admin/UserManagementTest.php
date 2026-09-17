<?php

declare(strict_types=1);

use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;

test('an agent cannot reach any part of the user admin', function () {
    $agent = makeAgent();

    $this->actingAs($agent)->get('/admin/users')->assertForbidden();
    $this->actingAs($agent)->get('/admin/users/create')->assertForbidden();
    $this->actingAs($agent)->post('/admin/users', [])->assertForbidden();
});

test('a guest is redirected to the login screen', function () {
    $this->get('/admin/users')->assertRedirect('/login');
});

test('an administrator sees the user list', function () {
    $admin = makeAdmin();
    User::factory()->count(3)->create();

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users.data', 4)
        );
});

test('the user list can be filtered by search term, role and status', function () {
    $admin = makeAdmin();

    // The search term has to be one Faker cannot produce for the two accounts
    // created alongside it. "Wouter" is in Faker's own en_US name pool — about
    // one generated name in three hundred contains it — so searching for it
    // failed roughly once in a hundred and fifty full-suite runs, which is
    // exactly the kind of flake that gets a real failure dismissed later.
    $needle = 'Zzyzxdottir';

    User::factory()->create(['name' => "Wouter {$needle}", 'email' => 'wouter@example.org']);
    User::factory()->inactive()->create(['name' => 'Inactive Person']);

    $this->actingAs($admin)
        ->get('/admin/users?search='.$needle)
        ->assertInertia(fn ($page) => $page->has('users.data', 1)
            ->where('users.data.0.name', "Wouter {$needle}"));

    $this->actingAs($admin)
        ->get('/admin/users?status=inactive')
        ->assertInertia(fn ($page) => $page->has('users.data', 1));

    $this->actingAs($admin)
        ->get('/admin/users?role='.Role::ADMIN)
        ->assertInertia(fn ($page) => $page->has('users.data', 1)
            ->where('users.data.0.id', $admin->id));
});

test('an administrator creates a user with roles and teams', function () {
    $admin = makeAdmin();
    $team = Team::factory()->create();
    $organization = Organization::factory()->create();
    $agentRole = Role::query()->where('name', Role::AGENT)->sole();

    $this->actingAs($admin)
        ->post('/admin/users', [
            'name' => 'Fatima El Amrani',
            'email' => 'fatima@example.org',
            'password' => 'Str0ng-passw0rd!',
            'password_confirmation' => 'Str0ng-passw0rd!',
            'locale' => 'nl',
            'organization_id' => $organization->id,
            'is_active' => true,
            'role_ids' => [$agentRole->id],
            'team_ids' => [$team->id],
        ])
        ->assertRedirect('/admin/users')
        ->assertSessionHas('success');

    $user = User::query()->where('email', 'fatima@example.org')->sole();

    expect($user->roles->pluck('name')->all())->toBe([Role::AGENT])
        ->and($user->teams->pluck('id')->all())->toBe([$team->id])
        ->and($user->locale)->toBe('nl')
        ->and($user->directory)->toBe('local');

    expect(AuditLogEntry::query()->where('event', 'created')->where('auditable_id', $user->id)->exists())
        ->toBeTrue();
});

test('creating a user validates the payload', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->post('/admin/users', ['name' => '', 'email' => 'not-an-email', 'password' => 'short'])
        ->assertSessionHasErrors(['name', 'email', 'password']);
});

test('an administrator updates a user without touching the password when left blank', function () {
    $admin = makeAdmin();
    $user = User::factory()->create(['name' => 'Old Name']);
    $hash = $user->password;

    $this->actingAs($admin)
        ->put("/admin/users/{$user->id}", [
            'name' => 'New Name',
            'email' => $user->email,
            'password' => '',
            'is_active' => true,
            'role_ids' => [],
            'team_ids' => [],
        ])
        ->assertRedirect('/admin/users');

    $user->refresh();

    expect($user->name)->toBe('New Name')
        ->and($user->password)->toBe($hash);
});

test('a directory-managed account keeps its name and e-mail address', function () {
    $admin = makeAdmin();
    $user = User::factory()->fromDirectory()->create([
        'name' => 'Directory Person',
        'email' => 'directory@example.org',
    ]);

    $this->actingAs($admin)
        ->put("/admin/users/{$user->id}", [
            'name' => 'Hacked Name',
            'email' => 'hacked@example.org',
            'is_active' => true,
            'role_ids' => [],
            'team_ids' => [],
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->name)->toBe('Directory Person')
        ->and($user->email)->toBe('directory@example.org');
});

test('an operator who may manage users cannot hand out roles', function () {
    $operator = makeUserWithPermissions('users.manage');
    $user = User::factory()->create();
    $adminRole = Role::query()->where('name', Role::ADMIN)->sole();

    $this->actingAs($operator)
        ->put("/admin/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => true,
            'role_ids' => [$adminRole->id],
            'team_ids' => [],
        ])
        ->assertRedirect();

    expect($user->fresh()->roles)->toBeEmpty();
});

test('deactivating an account is reversible and audited', function () {
    $admin = makeAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)->patch("/admin/users/{$user->id}/active")->assertRedirect();
    expect($user->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch("/admin/users/{$user->id}/active")->assertRedirect();
    expect($user->fresh()->is_active)->toBeTrue();

    expect(AuditLogEntry::query()->whereIn('event', ['activated', 'deactivated'])->count())->toBe(2);
});

test('an administrator cannot deactivate or delete their own account', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->patch("/admin/users/{$admin->id}/active")->assertForbidden();
    $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertForbidden();

    expect($admin->fresh()->is_active)->toBeTrue();
});

test('deleting a user archives rather than erases it', function () {
    $admin = makeAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)->delete("/admin/users/{$user->id}")->assertRedirect('/admin/users');

    expect(User::query()->find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id)->is_active)->toBeFalse();
});
