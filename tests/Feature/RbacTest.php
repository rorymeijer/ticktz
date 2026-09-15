<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Gate;

test('the permission seeder mirrors the catalogue exactly', function () {
    seedRbac();

    expect(Permission::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionCatalog::all())->sort()->values()->all());
});

test('a super admin passes every gate without holding the permission', function () {
    $admin = makeAdmin();

    foreach (PermissionCatalog::all() as $permission) {
        expect(Gate::forUser($admin)->allows($permission))->toBeTrue("failed for {$permission}");
    }

    expect($admin->permissionNames())->toBe(['*']);
});

test('an agent holds the agent permission set and nothing more', function () {
    $agent = makeAgent();

    expect($agent->hasPermission('tickets.view'))->toBeTrue()
        ->and($agent->hasPermission('users.manage'))->toBeFalse()
        ->and($agent->hasPermission('settings.manage'))->toBeFalse();
});

test('a requester may only use the portal and read public articles', function () {
    $requester = makeRequester();

    expect($requester->hasPermission('portal.submit'))->toBeTrue()
        ->and($requester->hasPermission('kb.view'))->toBeTrue()
        ->and($requester->hasPermission('tickets.view'))->toBeFalse()
        ->and($requester->isAgent())->toBeFalse();
});

test('deactivating an account revokes every permission immediately', function () {
    $admin = makeAdmin();

    $admin->forceFill(['is_active' => false])->save();

    $reloaded = User::query()->find($admin->id);

    expect($reloaded->permissionNames())->toBe([])
        ->and(Gate::forUser($reloaded)->allows('users.manage'))->toBeFalse();
});

test('permissions are the union of every role a user holds', function () {
    seedRbac();

    $user = User::factory()->create();
    $reader = Role::factory()->create();
    $reader->syncPermissionNames(['kb.view']);
    $writer = Role::factory()->create();
    $writer->syncPermissionNames(['kb.manage']);

    $user->roles()->attach([$reader->id, $writer->id]);

    expect($user->fresh()->permissionNames())->toContain('kb.view', 'kb.manage');
});

test('the landing shell follows the widest role scope', function () {
    seedRbac();

    $user = User::factory()->create();
    $user->syncRoleNames([Role::REQUESTER]);
    expect($user->fresh()->scopeLevel())->toBe('requester');

    $user->syncRoleNames([Role::REQUESTER, Role::AGENT]);
    expect($user->fresh()->scopeLevel())->toBe('agent');

    $user->syncRoleNames([Role::REQUESTER, Role::AGENT, Role::ADMIN]);
    expect($user->fresh()->scopeLevel())->toBe('admin');
});

test('every catalogue permission is registered as a gate', function () {
    seedRbac();

    $user = makeUserWithPermissions('tickets.view');

    foreach (PermissionCatalog::all() as $permission) {
        expect(Gate::forUser($user)->has($permission))->toBeTrue("missing gate for {$permission}");
    }

    expect(Gate::forUser($user)->allows('tickets.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('tickets.delete'))->toBeFalse();
});

test('every permission in the catalogue has a translation in both locales', function () {
    foreach (['en', 'nl'] as $locale) {
        $messages = require lang_path("{$locale}/admin.php");

        foreach (PermissionCatalog::all() as $permission) {
            expect($messages['permissions'][$permission] ?? null)
                ->not->toBeNull("missing [{$locale}] label for {$permission}");
        }
    }
});
