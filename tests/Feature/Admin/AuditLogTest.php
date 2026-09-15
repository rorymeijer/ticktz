<?php

declare(strict_types=1);

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Services\AuditLogger;

test('reading the audit log requires the audit.view permission', function () {
    $agent = makeAgent();

    $this->actingAs($agent)->get('/admin/audit-log')->assertForbidden();
});

test('an administrator can read the audit log', function () {
    $admin = makeAdmin();
    User::factory()->count(2)->create();

    $this->actingAs($admin)
        ->get('/admin/audit-log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/AuditLog/Index')->has('entries.data'));
});

test('an update entry records only the attributes that changed', function () {
    seedRbac();
    $actor = makeAdmin();
    $user = User::factory()->create(['name' => 'Before', 'job_title' => 'Analyst']);

    $user->name = 'After';
    $user->save();

    $entry = app(AuditLogger::class)->actingAs($actor)->updated($user);

    expect($entry)->not->toBeNull()
        ->and($entry->old_values)->toBe(['name' => 'Before'])
        ->and($entry->new_values)->toBe(['name' => 'After'])
        ->and($entry->new_values)->not->toHaveKey('job_title');
});

test('a save that changed nothing writes no entry', function () {
    seedRbac();
    $user = User::factory()->create();

    $user->save();

    expect(app(AuditLogger::class)->updated($user))->toBeNull();
});

test('secrets never reach the audit trail', function () {
    seedRbac();
    $user = User::factory()->create();

    $entry = app(AuditLogger::class)->created($user);

    expect($entry->new_values['password'])->toBe('••••')
        ->and($entry->new_values)->not->toHaveKey('remember_token_plain');
});

test('non-human actors are labelled', function () {
    seedRbac();
    $user = User::factory()->create();

    $entry = app(AuditLogger::class)->as('automation', 'Rule #12')->log($user, 'assigned');

    expect($entry->actor_type)->toBe('automation')
        ->and($entry->actor_label)->toBe('Rule #12')
        ->and($entry->user_id)->toBeNull();
});

test('the audit log can be filtered by event and actor type', function () {
    $admin = makeAdmin();
    $user = User::factory()->create();

    app(AuditLogger::class)->actingAs($admin)->log($user, 'custom.event');
    app(AuditLogger::class)->as('system')->log($user, 'system.event');

    $this->actingAs($admin)
        ->get('/admin/audit-log?event=custom.event')
        ->assertInertia(fn ($page) => $page->has('entries.data', 1)
            ->where('entries.data.0.event', 'custom.event'));

    $this->actingAs($admin)
        ->get('/admin/audit-log?actor_type=system')
        ->assertInertia(fn ($page) => $page->has('entries.data', 1)
            ->where('entries.data.0.event', 'system.event'));
});

test('audit entries are never updated', function () {
    seedRbac();
    $user = User::factory()->create();

    app(AuditLogger::class)->created($user);

    // The model has no updated_at; writing one would mean the trail was edited.
    expect(AuditLogEntry::UPDATED_AT)->toBeNull()
        ->and(AuditLogEntry::query()->sole()->created_at)->not->toBeNull();
});
