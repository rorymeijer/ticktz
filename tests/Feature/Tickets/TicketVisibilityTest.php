<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;

beforeEach(function () {
    seedServiceDesk();
});

test('an agent with view.all sees every ticket', function () {
    $admin = User::factory()->admin()->create();
    Ticket::factory()->count(3)->create();

    expect(Ticket::query()->visibleTo($admin->fresh())->count())->toBe(3);
});

test('an agent without view.all sees only their own work and their teams', function () {
    $agent = makeUserWithPermissions('tickets.view');
    $team = Team::factory()->create();
    $agent->teams()->attach($team);

    $mine = Ticket::factory()->assignedTo($agent)->create();
    $teams = Ticket::factory()->create(['team_id' => $team->id]);
    $theirs = Ticket::factory()->create();

    $visible = Ticket::query()->visibleTo($agent->fresh())->pluck('id')->all();

    expect($visible)->toContain($mine->id)
        ->and($visible)->toContain($teams->id)
        ->and($visible)->not->toContain($theirs->id);
});

test('an agent sees a ticket they were added to as a watcher', function () {
    $agent = makeUserWithPermissions('tickets.view');
    $ticket = Ticket::factory()->create();

    expect(Ticket::query()->visibleTo($agent)->count())->toBe(0);

    $ticket->watchers()->attach($agent);

    expect(Ticket::query()->visibleTo($agent->fresh())->pluck('id')->all())->toContain($ticket->id);
});

test('a requester sees only their own tickets', function () {
    $requester = User::factory()->requester()->create();
    $mine = Ticket::factory()->forRequester($requester)->create();
    Ticket::factory()->count(2)->create();

    expect(Ticket::query()->visibleTo($requester->fresh())->pluck('id')->all())->toBe([$mine->id]);
});

test('an organisation with shared visibility lets colleagues see each other tickets', function () {
    $organization = Organization::factory()->withSharedVisibility()->create();
    $colleague = User::factory()->requester()->create(['organization_id' => $organization->id]);
    $other = User::factory()->requester()->create(['organization_id' => $organization->id]);

    $ticket = Ticket::factory()->forRequester($other)->create();

    expect(Ticket::query()->visibleTo($colleague->fresh())->pluck('id')->all())->toContain($ticket->id);
});

test('without shared visibility colleagues stay separated', function () {
    $organization = Organization::factory()->create(['shared_ticket_visibility' => false]);
    $colleague = User::factory()->requester()->create(['organization_id' => $organization->id]);
    $other = User::factory()->requester()->create(['organization_id' => $organization->id]);

    Ticket::factory()->forRequester($other)->create();

    expect(Ticket::query()->visibleTo($colleague->fresh())->count())->toBe(0);
});

test('the policy and the query scope agree on every ticket', function () {
    // The scope filters lists and the policy guards single records; a
    // disagreement between them is a data leak, so assert they match.
    $organization = Organization::factory()->withSharedVisibility()->create();
    $team = Team::factory()->create();

    $agent = makeUserWithPermissions('tickets.view');
    $agent->teams()->attach($team);
    $agent = $agent->fresh();

    $tickets = collect([
        Ticket::factory()->assignedTo($agent)->create(),
        Ticket::factory()->create(['team_id' => $team->id]),
        Ticket::factory()->create(),
        Ticket::factory()->forRequester(
            User::factory()->requester()->create(['organization_id' => $organization->id])
        )->create(),
    ]);

    $visibleIds = Ticket::query()->visibleTo($agent)->pluck('id')->all();

    foreach ($tickets as $ticket) {
        expect($agent->can('view', $ticket))->toBe(
            in_array($ticket->id, $visibleIds, true),
            "policy and scope disagree about ticket {$ticket->key}"
        );
    }
});
