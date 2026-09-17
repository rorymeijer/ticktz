<?php

declare(strict_types=1);

use App\Models\LdapConfig;
use App\Models\Role;
use App\Models\User;
use App\Services\Ldap\LdapUserSynchronizer;

/**
 * The synchronizer on its own, fed the raw attribute bags a directory hands
 * back. No emulator and no ext-ldap here: these are the shapes the mapping has
 * to survive, not the protocol.
 */
function directoryConfig(array $overrides = []): LdapConfig
{
    seedRbac();

    return LdapConfig::query()->create(array_merge([
        'name' => 'Sync AD',
        'connection' => 'sync-ad',
        'is_enabled' => true,
        'hosts' => ['ldap.test'],
        'port' => 389,
        'encryption' => 'none',
        'timeout' => 5,
        'base_dn' => 'dc=example,dc=org',
        'login_attribute' => 'samaccountname',
        'attribute_map' => LdapConfig::defaultAttributeMap(),
        'sync_groups' => true,
        'group_member_attribute' => 'memberof',
        'group_role_map' => ['IT Servicedesk' => Role::AGENT],
        'auto_provision' => true,
    ], $overrides));
}

test('the distinguished name is read whether it arrives as a string or an array', function (mixed $dn) {
    $config = directoryConfig();

    $user = app(LdapUserSynchronizer::class)->sync($config, [
        'dn' => $dn,
        'cn' => ['Anja de Boer'],
        'mail' => ['anja@example.org'],
        'samaccountname' => ['adeboer'],
    ]);

    expect($user)->not->toBeNull()
        ->and($user->ldap_dn)->toBe('cn=Anja de Boer,ou=staff,dc=example,dc=org');
})->with([
    'string' => 'cn=Anja de Boer,ou=staff,dc=example,dc=org',
    'single-valued array' => [['cn=Anja de Boer,ou=staff,dc=example,dc=org']],
    'counted array' => [['count' => 1, 0 => 'cn=Anja de Boer,ou=staff,dc=example,dc=org']],
]);

test('losing every mapped group drops the account back to the default role', function () {
    $config = directoryConfig();
    $sync = app(LdapUserSynchronizer::class);

    $entry = [
        'dn' => ['cn=Karel,ou=staff,dc=example,dc=org'],
        'cn' => ['Karel Smit'],
        'mail' => ['karel@example.org'],
        'samaccountname' => ['ksmit'],
        'memberof' => ['cn=IT Servicedesk,ou=groups,dc=example,dc=org'],
    ];

    $user = $sync->sync($config, $entry);
    expect($user->roles->pluck('name')->all())->toBe([Role::AGENT]);

    $entry['memberof'] = ['cn=Finance,ou=groups,dc=example,dc=org'];

    $user = $sync->sync($config, $entry);
    expect($user->fresh()->roles->pluck('name')->all())->toBe([Role::REQUESTER]);
});

test('an empty group map never demotes anyone', function () {
    // Group sync switched on before the mapping was filled in: there is
    // nothing to recompute from, so existing roles are left alone.
    $config = directoryConfig(['group_role_map' => []]);

    $existing = User::factory()->create(['email' => 'chef@example.org']);
    $existing->syncRoleNames([Role::ADMIN]);

    $user = app(LdapUserSynchronizer::class)->sync($config, [
        'dn' => 'cn=Chef,ou=staff,dc=example,dc=org',
        'cn' => ['Grote Chef'],
        'mail' => ['chef@example.org'],
        'samaccountname' => ['chef'],
    ]);

    expect($user->is($existing))->toBeTrue()
        ->and($user->fresh()->roles->pluck('name')->all())->toBe([Role::ADMIN]);
});
