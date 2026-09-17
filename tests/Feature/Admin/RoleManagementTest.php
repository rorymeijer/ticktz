<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;

test('managing roles requires the roles.manage permission', function () {
    $operator = makeUserWithPermissions('users.manage');

    $this->actingAs($operator)->get('/admin/roles')->assertForbidden();
    $this->actingAs($operator)->post('/admin/roles', [])->assertForbidden();
});

test('an administrator creates a role with a permission subset', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->post('/admin/roles', [
            'name' => 'kb-editor',
            'display_name' => 'Knowledge base editor',
            'scope' => 'agent',
            'permissions' => ['kb.view', 'kb.manage'],
        ])
        ->assertRedirect('/admin/roles');

    $role = Role::query()->where('name', 'kb-editor')->sole();

    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['kb.manage', 'kb.view'])
        ->and($role->is_system)->toBeFalse()
        ->and($role->is_super)->toBeFalse();
});

test('an unknown permission is rejected rather than silently dropped', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->post('/admin/roles', [
            'name' => 'sneaky',
            'display_name' => 'Sneaky',
            'scope' => 'agent',
            'permissions' => ['tickets.view', 'everything.everywhere'],
        ])
        ->assertSessionHasErrors('permissions.1');
});

test('a role slug must be a lower-case identifier', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->post('/admin/roles', ['name' => 'Not A Slug', 'display_name' => 'x', 'scope' => 'agent'])
        ->assertSessionHasErrors('name');
});

test('a system role keeps its slug and scope but can be re-permissioned', function () {
    $admin = makeAdmin();
    $agentRole = Role::query()->where('name', Role::AGENT)->sole();

    $this->actingAs($admin)
        ->put("/admin/roles/{$agentRole->id}", [
            'name' => 'renamed',
            'display_name' => 'Service agent',
            'scope' => 'admin',
            'permissions' => ['tickets.view'],
        ])
        ->assertRedirect('/admin/roles');

    $agentRole->refresh();

    expect($agentRole->name)->toBe(Role::AGENT)
        ->and($agentRole->scope)->toBe('agent')
        ->and($agentRole->display_name)->toBe('Service agent')
        ->and($agentRole->permissions->pluck('name')->all())->toBe(['tickets.view']);
});

test('a system role cannot be deleted', function () {
    $admin = makeAdmin();
    $agentRole = Role::query()->where('name', Role::AGENT)->sole();

    $this->actingAs($admin)->delete("/admin/roles/{$agentRole->id}")->assertForbidden();

    expect(Role::query()->whereKey($agentRole->id)->exists())->toBeTrue();
});

test('a role that still has members cannot be deleted', function () {
    $admin = makeAdmin();
    $role = Role::factory()->create();
    User::factory()->create()->roles()->attach($role);

    $this->actingAs($admin)
        ->delete("/admin/roles/{$role->id}")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
});

test('only one role can be the default for new accounts', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/admin/roles', [
        'name' => 'visitor',
        'display_name' => 'Visitor',
        'scope' => 'requester',
        'is_default' => true,
        'permissions' => ['portal.submit'],
    ])->assertRedirect();

    expect(Role::query()->where('is_default', true)->count())->toBe(1)
        ->and(Role::query()->where('is_default', true)->sole()->name)->toBe('visitor');
});

test('the super admin role ignores permission edits', function () {
    $admin = makeAdmin();
    $adminRole = Role::query()->where('name', Role::ADMIN)->sole();

    $this->actingAs($admin)
        ->put("/admin/roles/{$adminRole->id}", [
            'name' => Role::ADMIN,
            'display_name' => 'Administrator',
            'scope' => 'admin',
            'permissions' => ['kb.view'],
        ])
        ->assertRedirect();

    expect($adminRole->fresh()->permissions()->count())->toBeGreaterThan(1);
});
