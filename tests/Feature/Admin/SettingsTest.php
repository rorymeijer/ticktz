<?php

declare(strict_types=1);

use App\Services\SettingsRepository;

test('changing settings requires the settings.manage permission', function () {
    $agent = makeAgent();

    $this->actingAs($agent)->get('/admin/settings')->assertForbidden();
    $this->actingAs($agent)->put('/admin/settings', [])->assertForbidden();
});

test('an administrator saves settings and they take effect immediately', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->put('/admin/settings', [
            'allow_self_registration' => true,
            'require_email_verification' => false,
            'portal_title' => 'Servicedesk Gemeente',
            'welcome_message' => 'Waarmee kunnen we helpen?',
            'primary_color' => '#0f766e',
            'support_email' => 'servicedesk@example.org',
            'key_prefix' => 'SD',
            'reopen_window_days' => 30,
            'auto_close_days' => 5,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $settings = app(SettingsRepository::class);

    expect($settings->bool('portal.allow_self_registration'))->toBeTrue()
        ->and($settings->string('portal.title'))->toBe('Servicedesk Gemeente')
        ->and($settings->int('tickets.reopen_window_days'))->toBe(30)
        ->and($settings->string('tickets.key_prefix'))->toBe('SD');

    // The registration route opens up as soon as the setting flips.
    auth()->logout();
    $this->get('/register')->assertOk();
});

test('invalid settings are rejected', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->put('/admin/settings', [
            'portal_title' => '',
            'primary_color' => 'teal',
            'key_prefix' => 'lower',
            'reopen_window_days' => -1,
            'auto_close_days' => 999,
        ])
        ->assertSessionHasErrors(['portal_title', 'primary_color', 'key_prefix', 'reopen_window_days', 'auto_close_days']);
});

test('settings fall back to their documented defaults', function () {
    $settings = app(SettingsRepository::class);

    expect($settings->bool('portal.allow_self_registration'))->toBeFalse()
        ->and($settings->string('tickets.key_prefix'))->toBe('SUP')
        ->and($settings->get('nonexistent.key', 'fallback'))->toBe('fallback');
});

test('the settings cache is invalidated on write', function () {
    $settings = app(SettingsRepository::class);

    expect($settings->string('tickets.key_prefix'))->toBe('SUP');

    $settings->set('tickets.key_prefix', 'ICT');

    expect($settings->string('tickets.key_prefix'))->toBe('ICT')
        ->and(app(SettingsRepository::class)->string('tickets.key_prefix'))->toBe('ICT');
});
