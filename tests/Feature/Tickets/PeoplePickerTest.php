<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\Queue;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| Searching for a person
|--------------------------------------------------------------------------
|
| The endpoint every picker asks. What it must never do is offer a name the
| server will then refuse — an operator reads that as a broken product rather
| than as a rule — so the assignable set is decided here and not by whichever
| page happens to be rendering.
*/

it('offers only the agents on the ticket\'s team', function (): void {
    $agent = makeAdmin();
    $member = User::factory()->agent()->create(['name' => 'Ada Lovelace']);
    $outsider = User::factory()->agent()->create(['name' => 'Alan Turing']);
    $team = Team::factory()->create();
    $team->members()->attach($member);

    $ticket = Ticket::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($agent)
        ->getJson("/people?scope=assignee&ticket={$ticket->key}")
        ->assertOk();

    $names = array_column($response->json('people'), 'name');

    expect($names)->toContain('Ada Lovelace')
        ->and($names)->not->toContain('Alan Turing');
});

it('reads the team off a ticket that does not exist yet', function (): void {
    // The ticket form, before anything is saved: the team is whatever has
    // been chosen in the form so far.
    $agent = makeAdmin();
    $member = User::factory()->agent()->create(['name' => 'Ada Lovelace']);
    User::factory()->agent()->create(['name' => 'Alan Turing']);
    $team = Team::factory()->create();
    $team->members()->attach($member);

    $response = $this->actingAs($agent)
        ->getJson("/people?scope=assignee&team_id={$team->id}")
        ->assertOk();

    expect(array_column($response->json('people'), 'name'))->toBe(['Ada Lovelace']);
});

it("falls back to the queue's team when the ticket has none of its own", function (): void {
    $agent = makeAdmin();
    $member = User::factory()->agent()->create(['name' => 'Ada Lovelace']);
    User::factory()->agent()->create(['name' => 'Alan Turing']);
    $team = Team::factory()->create();
    $team->members()->attach($member);
    $queue = Queue::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($agent)
        ->getJson("/people?scope=assignee&queue_id={$queue->id}")
        ->assertOk();

    expect(array_column($response->json('people'), 'name'))->toBe(['Ada Lovelace']);
});

it('offers every agent for a ticket that belongs to no team', function (): void {
    $agent = makeAdmin();
    User::factory()->agent()->create(['name' => 'Ada Lovelace']);
    User::factory()->agent()->create(['name' => 'Alan Turing']);

    $ticket = Ticket::factory()->create(['team_id' => null, 'queue_id' => null]);

    $response = $this->actingAs($agent)
        ->getJson("/people?scope=assignee&ticket={$ticket->key}")
        ->assertOk();

    expect(array_column($response->json('people'), 'name'))
        ->toContain('Ada Lovelace')
        ->toContain('Alan Turing');
});

it('never offers a requester or a deactivated agent', function (): void {
    $agent = makeAdmin();
    User::factory()->requester()->create(['name' => 'Rita Requester']);
    User::factory()->agent()->create(['name' => 'Gone Agent', 'is_active' => false]);

    $ticket = Ticket::factory()->create(['team_id' => null, 'queue_id' => null]);

    $response = $this->actingAs($agent)
        ->getJson("/people?scope=assignee&ticket={$ticket->key}")
        ->assertOk();

    $names = array_column($response->json('people'), 'name');

    expect($names)->not->toContain('Rita Requester')
        ->and($names)->not->toContain('Gone Agent');
});

it('matches on name and on e-mail', function (): void {
    $agent = makeAdmin();
    User::factory()->agent()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.org']);
    User::factory()->agent()->create(['name' => 'Alan Turing', 'email' => 'alan@example.org']);

    $ticket = Ticket::factory()->create(['team_id' => null, 'queue_id' => null]);

    expect(array_column(
        $this->actingAs($agent)
            ->getJson("/people?scope=assignee&ticket={$ticket->key}&q=lovel")
            ->assertOk()->json('people'),
        'name',
    ))->toBe(['Ada Lovelace']);

    expect(array_column(
        $this->actingAs($agent)
            ->getJson("/people?scope=assignee&ticket={$ticket->key}&q=alan@")
            ->assertOk()->json('people'),
        'name',
    ))->toBe(['Alan Turing']);
});

it('sends a handful rather than a directory', function (): void {
    // The bug this endpoint exists for: the old list took the first five
    // hundred users and dropped the rest without saying so. Sending ten and
    // searching the rest is the whole difference.
    $agent = makeAdmin();
    User::factory()->agent()->count(25)->create();

    $ticket = Ticket::factory()->create(['team_id' => null, 'queue_id' => null]);

    $response = $this->actingAs($agent)
        ->getJson("/people?scope=assignee&ticket={$ticket->key}")
        ->assertOk();

    expect($response->json('people'))->toHaveCount(10);
});

/*
|--------------------------------------------------------------------------
| Who may ask
|--------------------------------------------------------------------------
*/

it('is closed to somebody with no screen that shows a list of people', function (): void {
    $requester = makeRequester();

    $this->actingAs($requester)->getJson('/people?scope=agent')->assertForbidden();
});

it('answers an administrator who never touches a ticket', function (): void {
    // Half the screens that pick a person are administrative — team
    // membership, a user's manager, an approval step, an automation action —
    // and none of those permissions implies tickets.view. The endpoint used to
    // sit behind that gate, which would have left every one of those pickers
    // answering 403 for exactly the people who need them.
    $administrator = makeUserWithPermissions('teams.manage');
    User::factory()->agent()->create(['name' => 'Ada Lovelace']);

    $response = $this->actingAs($administrator)
        ->getJson('/people?scope=agent&q=Ada')
        ->assertOk();

    expect(array_column($response->json('people'), 'name'))->toBe(['Ada Lovelace']);
});

it('needs the assign permission to ask who a ticket could go to', function (): void {
    $agent = makeUserWithPermissions('tickets.view');
    $ticket = Ticket::factory()->create(['team_id' => null, 'queue_id' => null]);

    $this->actingAs($agent)
        ->getJson("/people?scope=assignee&ticket={$ticket->key}")
        ->assertForbidden();
});

it('refuses to answer for a ticket the asker cannot see', function (): void {
    // Otherwise the picker is a way to read a team's staffing from outside it:
    // name a ticket in somebody else's queue and read back who works it.
    $team = Team::factory()->create();
    $outsider = makeUserWithPermissions('tickets.view', 'tickets.assign');
    $ticket = Ticket::factory()->create(['team_id' => $team->id]);

    $this->actingAs($outsider)
        ->getJson("/people?scope=assignee&ticket={$ticket->key}")
        ->assertForbidden();
});

it('does not hand the customer directory to somebody who only reads tickets', function (): void {
    // The wider set: `user` includes customers, so seeing tickets is not
    // enough. Filing one for somebody, or managing accounts, is.
    $agent = makeUserWithPermissions('tickets.view');

    $this->actingAs($agent)->getJson('/people?scope=user')->assertForbidden();

    // ... and the staff directory is a different question, which that same
    // person may ask: they read it off the assignee column already.
    $this->actingAs($agent)->getJson('/people?scope=agent')->assertOk();
});

it('lets somebody who files tickets find a requester', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.create');
    User::factory()->requester()->create(['name' => 'Rita Requester']);

    $response = $this->actingAs($agent)->getJson('/people?scope=user&q=Rita')->assertOk();

    expect(array_column($response->json('people'), 'name'))->toBe(['Rita Requester']);
});

it('insists on being told which set is being searched', function (): void {
    $agent = makeAdmin();

    $this->actingAs($agent)->getJson('/people')->assertStatus(422);
    $this->actingAs($agent)->getJson('/people?scope=everybody')->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Two endpoints that had no way to be reached
|--------------------------------------------------------------------------
|
| Both of these were built, validated and documented, and neither had anything
| on a screen that called them. A feature nobody can reach is a feature that
| does not exist, however well it is tested.
*/

it('puts somebody on a ticket as a watcher', function (): void {
    $agent = makeAdmin();
    $watcher = makeRequester();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/watchers", ['user_id' => $watcher->getKey()])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->watchers->pluck('id'))->toContain($watcher->getKey());
});

it('records who holds an asset', function (): void {
    $agent = makeAdmin();
    $holder = makeRequester();
    $asset = Asset::factory()->create(['assigned_to' => null]);

    $this->actingAs($agent)
        ->put("/agent/assets/{$asset->asset_tag}", [
            'asset_type_id' => $asset->asset_type_id,
            'asset_tag' => $asset->asset_tag,
            'name' => $asset->name,
            'status' => $asset->status,
            'assigned_to' => $holder->getKey(),
        ])
        ->assertSessionHasNoErrors();

    expect($asset->fresh()->assigned_to)->toBe($holder->getKey());
});
