<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;

test('managing teams requires the teams.manage permission', function () {
    $agent = makeAgent();

    $this->actingAs($agent)->post('/admin/teams', [])->assertForbidden();
});

test('an administrator creates a team with members and leads', function () {
    $admin = makeAdmin();
    $lead = User::factory()->agent()->create();
    $member = User::factory()->agent()->create();

    $this->actingAs($admin)
        ->post('/admin/teams', [
            'name' => 'Servicedesk',
            'slug' => 'servicedesk',
            'email' => 'servicedesk@example.org',
            'is_active' => true,
            'member_ids' => [$lead->id, $member->id],
            'lead_ids' => [$lead->id],
        ])
        ->assertRedirect('/admin/teams');

    $team = Team::query()->where('slug', 'servicedesk')->sole();

    expect($team->members)->toHaveCount(2)
        ->and($team->leads()->pluck('users.id')->all())->toBe([$lead->id]);
});

test('a team slug must be unique and url safe', function () {
    $admin = makeAdmin();
    Team::factory()->create(['slug' => 'taken']);

    $this->actingAs($admin)
        ->post('/admin/teams', ['name' => 'x', 'slug' => 'taken'])
        ->assertSessionHasErrors('slug');

    $this->actingAs($admin)
        ->post('/admin/teams', ['name' => 'x', 'slug' => 'Not A Slug'])
        ->assertSessionHasErrors('slug');
});

test('an administrator creates an organisation with e-mail domains', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->post('/admin/organizations', [
            'name' => 'Gemeente Voorbeeld',
            'slug' => 'gemeente-voorbeeld',
            'email_domains' => ['Voorbeeld.NL', 'voorbeeld.nl', 'example.org'],
            'is_active' => true,
            'shared_ticket_visibility' => true,
        ])
        ->assertRedirect('/admin/organizations');

    $organization = Organization::query()->where('slug', 'gemeente-voorbeeld')->sole();

    // Domains are normalised to lower case and de-duplicated.
    expect($organization->email_domains)->toBe(['voorbeeld.nl', 'example.org'])
        ->and($organization->shared_ticket_visibility)->toBeTrue();
});

test('an organisation claims a new requester by e-mail domain', function () {
    seedRbac();
    $organization = Organization::factory()->create(['email_domains' => ['example.org']]);

    expect(Organization::matchingEmail('someone@EXAMPLE.org')?->id)->toBe($organization->id)
        ->and(Organization::matchingEmail('someone@elsewhere.test'))->toBeNull()
        ->and(Organization::matchingEmail('not-an-address'))->toBeNull();
});

test('an inactive organisation does not claim new requesters', function () {
    seedRbac();
    Organization::factory()->create(['email_domains' => ['example.org'], 'is_active' => false]);

    expect(Organization::matchingEmail('someone@example.org'))->toBeNull();
});
