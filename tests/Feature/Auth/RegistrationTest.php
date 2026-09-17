<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsRepository;

test('self-registration is disabled by default', function () {
    seedRbac();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test',
        'email' => 'test@example.org',
        'password' => 'Str0ng-passw0rd!',
        'password_confirmation' => 'Str0ng-passw0rd!',
    ])->assertNotFound();

    expect(User::query()->count())->toBe(0);
});

test('the registration screen renders when self-registration is enabled', function () {
    seedRbac();
    app(SettingsRepository::class)->set('portal.allow_self_registration', true);

    $this->get('/register')->assertOk();
});

test('a self-registered account lands in the default role and on the portal', function () {
    seedRbac();
    app(SettingsRepository::class)->set('portal.allow_self_registration', true);

    $this->post('/register', [
        'name' => 'Sanne Jansen',
        'email' => 'sanne@example.org',
        'password' => 'Str0ng-passw0rd!',
        'password_confirmation' => 'Str0ng-passw0rd!',
    ])->assertRedirect('/portal');

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'sanne@example.org')->sole();

    expect($user->roles->pluck('name')->all())->toBe([Role::REQUESTER])
        ->and($user->directory)->toBe('local')
        ->and($user->isAgent())->toBeFalse();
});

test('a self-registered account joins the organisation that owns its e-mail domain', function () {
    seedRbac();
    app(SettingsRepository::class)->set('portal.allow_self_registration', true);

    $organization = Organization::factory()->create([
        'email_domains' => ['gemeente.example'],
    ]);

    $this->post('/register', [
        'name' => 'Pieter Bakker',
        'email' => 'pieter@gemeente.example',
        'password' => 'Str0ng-passw0rd!',
        'password_confirmation' => 'Str0ng-passw0rd!',
    ])->assertRedirect();

    expect(User::query()->where('email', 'pieter@gemeente.example')->sole()->organization_id)
        ->toBe($organization->id);
});
