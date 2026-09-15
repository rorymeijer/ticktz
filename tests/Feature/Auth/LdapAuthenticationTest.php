<?php

declare(strict_types=1);

use App\Models\LdapConfig;
use App\Models\Role;
use App\Models\User;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Models\Entry;

/**
 * These tests run against LdapRecord's directory emulator: a real query
 * builder backed by a local database instead of a live LDAP server, so the
 * search/bind/sync flow is exercised end to end without one.
 */
function fakeDirectory(array $overrides = []): LdapConfig
{
    seedRbac();

    $config = LdapConfig::query()->create(array_merge([
        'name' => 'Test AD',
        'connection' => 'test-ad',
        'is_enabled' => true,
        'hosts' => ['ldap.test'],
        'port' => 389,
        'encryption' => 'none',
        'timeout' => 5,
        'base_dn' => 'dc=example,dc=org',
        'bind_dn' => 'cn=service,dc=example,dc=org',
        'bind_password' => 'service-secret',
        'login_attribute' => 'samaccountname',
        'attribute_map' => LdapConfig::defaultAttributeMap(),
        'sync_groups' => true,
        'group_member_attribute' => 'memberof',
        'group_role_map' => ['IT Servicedesk' => Role::AGENT],
        'auto_provision' => true,
    ], $overrides));

    Container::addConnection(new Connection($config->toConnectionConfig()), $config->connection);

    return $config;
}

function fakeDirectoryEntry(array $attributes): Entry
{
    /** @var Entry $entry */
    $entry = (new Entry)->setConnection('test-ad');
    $entry->fill(array_merge([
        'objectclass' => ['top', 'person', 'organizationalperson', 'user'],
    ], $attributes));
    $entry->save();

    return $entry;
}

/*
 * The emulator needs ext-ldap's DN helpers (ldap_explode_dn) even though it
 * never opens a socket, so the whole group is skipped when the extension is
 * absent. CI installs it; so does the bundled Docker image.
 */
beforeEach(function () {
    if (! extension_loaded('ldap')) {
        $this->markTestSkipped('The PHP LDAP extension is required for the directory emulator.');
    }
});

afterEach(function () {
    if (extension_loaded('ldap')) {
        DirectoryEmulator::tearDown();
    }
});

test('a directory user can sign in and is provisioned on first login', function () {
    fakeDirectory();
    $fake = DirectoryEmulator::setup('test-ad');

    $entry = fakeDirectoryEntry([
        'dn' => 'cn=Anja de Boer,ou=staff,dc=example,dc=org',
        'cn' => 'Anja de Boer',
        'mail' => 'anja@example.org',
        'samaccountname' => 'adeboer',
        'title' => 'Service manager',
        'memberof' => ['cn=IT Servicedesk,ou=groups,dc=example,dc=org'],
    ]);

    $fake->actingAs($entry);

    $this->post('/login', ['email' => 'adeboer', 'password' => 'directory-secret'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'anja@example.org')->sole();

    expect($user->directory)->toBe('ldap')
        ->and($user->name)->toBe('Anja de Boer')
        ->and($user->username)->toBe('adeboer')
        ->and($user->job_title)->toBe('Service manager')
        ->and($user->password)->toBeNull()
        ->and($user->roles->pluck('name')->all())->toBe([Role::AGENT]);
});

test('a wrong directory password is rejected', function () {
    fakeDirectory();
    $fake = DirectoryEmulator::setup('test-ad');

    fakeDirectoryEntry([
        'dn' => 'cn=Anja,ou=staff,dc=example,dc=org',
        'cn' => 'Anja',
        'mail' => 'anja@example.org',
        'samaccountname' => 'adeboer',
    ]);

    // Nobody is allowed to bind, so the password check fails.
    $fake->actingAs('cn=someone-else,dc=example,dc=org');

    $this->post('/login', ['email' => 'adeboer', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('an unknown directory account is not provisioned', function () {
    fakeDirectory();
    DirectoryEmulator::setup('test-ad');

    $this->post('/login', ['email' => 'nobody', 'password' => 'secret'])
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(0);
});

test('directory group membership is re-evaluated on every sign-in', function () {
    fakeDirectory();
    $fake = DirectoryEmulator::setup('test-ad');

    $entry = fakeDirectoryEntry([
        'dn' => 'cn=Karel,ou=staff,dc=example,dc=org',
        'cn' => 'Karel Smit',
        'mail' => 'karel@example.org',
        'samaccountname' => 'ksmit',
        'memberof' => ['cn=IT Servicedesk,ou=groups,dc=example,dc=org'],
    ]);
    $fake->actingAs($entry);

    $this->post('/login', ['email' => 'ksmit', 'password' => 'secret'])->assertRedirect();
    expect(User::query()->sole()->roles->pluck('name')->all())->toBe([Role::AGENT]);

    $this->post('/logout');

    // The person leaves the service desk group.
    $entry->memberof = ['cn=Finance,ou=groups,dc=example,dc=org'];
    $entry->save();

    $this->post('/login', ['email' => 'ksmit', 'password' => 'secret'])->assertRedirect();

    // No mapped group left, so they drop back to the default role.
    expect(User::query()->sole()->fresh()->roles->pluck('name')->all())->toBe([Role::REQUESTER]);
});

test('auto provisioning can be switched off', function () {
    fakeDirectory(['auto_provision' => false]);
    $fake = DirectoryEmulator::setup('test-ad');

    $entry = fakeDirectoryEntry([
        'dn' => 'cn=Nieuw,ou=staff,dc=example,dc=org',
        'cn' => 'Nieuw Persoon',
        'mail' => 'nieuw@example.org',
        'samaccountname' => 'nieuw',
    ]);
    $fake->actingAs($entry);

    $this->post('/login', ['email' => 'nieuw', 'password' => 'secret'])
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(0);
});

test('an existing local account is adopted by the directory rather than duplicated', function () {
    fakeDirectory();
    $fake = DirectoryEmulator::setup('test-ad');

    $existing = User::factory()->create(['email' => 'anja@example.org', 'directory' => 'local']);

    $entry = fakeDirectoryEntry([
        'dn' => 'cn=Anja,ou=staff,dc=example,dc=org',
        'cn' => 'Anja de Boer',
        'mail' => 'anja@example.org',
        'samaccountname' => 'adeboer',
    ]);
    $fake->actingAs($entry);

    $this->post('/login', ['email' => 'adeboer', 'password' => 'secret'])->assertRedirect();

    expect(User::query()->count())->toBe(1)
        ->and($existing->fresh()->directory)->toBe('ldap');
});

test('local and directory authentication work side by side', function () {
    fakeDirectory();
    $fake = DirectoryEmulator::setup('test-ad');

    $local = User::factory()->create(['email' => 'local@example.org']);
    $entry = fakeDirectoryEntry([
        'dn' => 'cn=Directory,ou=staff,dc=example,dc=org',
        'cn' => 'Directory Person',
        'mail' => 'directory@example.org',
        'samaccountname' => 'dirperson',
    ]);
    $fake->actingAs($entry);

    $this->post('/login', ['email' => $local->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($local->fresh());
    $this->post('/logout');

    $this->post('/login', ['email' => 'dirperson', 'password' => 'secret'])->assertRedirect();
    $this->assertAuthenticated();

    expect(auth()->user()->email)->toBe('directory@example.org');
});

test('the bind password is stored encrypted', function () {
    $config = fakeDirectory();

    $raw = DB::table('ldap_configs')->where('id', $config->id)->value('bind_password');

    expect($raw)->not->toBe('service-secret')
        ->and($config->fresh()->bind_password)->toBe('service-secret')
        ->and($config->toAdminArray())->not->toHaveKey('bind_password');
});
