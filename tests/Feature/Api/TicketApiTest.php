<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;

use function Pest\Laravel\withToken;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * The phase's acceptance criterion: an external script creates a ticket with a
 * scoped token.
 */
it('creates a ticket with a scoped token', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('deploy bot', ['tickets.write'])->plainTextToken;

    $response = withToken($token)->postJson('/api/v1/tickets', [
        'subject' => 'Deployment failed on node 3',
        'description' => 'Exit code 137 during the migrate step.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.subject', 'Deployment failed on node 3')
        ->assertJsonPath('data.source', 'api')
        ->assertJsonPath('data.requester.email', $agent->email);

    $ticket = Ticket::query()->sole();

    // Created through the service, so it has everything a ticket created in
    // the console has: a key, an opening status from the workflow, and the
    // requester on the watcher list.
    expect($ticket->key)->not->toBeEmpty()
        ->and($ticket->status->category)->toBe('new')
        ->and($ticket->watchers()->count())->toBe(1);
});

it('stamps the source as api even when the caller claims otherwise', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('bot', ['tickets.write'])->plainTextToken;

    withToken($token)->postJson('/api/v1/tickets', [
        'subject' => 'Pretending to be the portal',
        'source' => 'portal',
    ])->assertCreated();

    // Where the work comes from is a number the desk reports on. A caller that
    // can claim to be the portal makes it meaningless.
    expect(Ticket::query()->sole()->source)->toBe('api');
});

it('files a ticket on behalf of someone else only with the permission for it', function (): void {
    $customer = makeRequester(['email' => 'customer@example.com']);

    // `tickets.create` is what the endpoint checks for acting on behalf of
    // another person. A requester's own token does not have it.
    $requester = User::factory()->requester()->create();
    $requester->assignRole('requester');
    $selfToken = $requester->createToken('self', ['tickets.write'])->plainTextToken;

    withToken($selfToken)->postJson('/api/v1/tickets', [
        'subject' => 'On behalf of someone else',
        'requester_email' => 'customer@example.com',
    ])->assertStatus(403);

    $agent = User::factory()->agent()->create();
    $agentToken = $agent->createToken('desk', ['tickets.write'])->plainTextToken;

    app('auth')->forgetGuards();

    withToken($agentToken)->postJson('/api/v1/tickets', [
        'subject' => 'On behalf of someone else',
        'requester_email' => 'customer@example.com',
    ])->assertCreated()
        ->assertJsonPath('data.requester.email', 'customer@example.com');

    expect(Ticket::query()->sole()->requester_id)->toBe($customer->getKey());
});

it('only lists tickets the token owner could see in the browser', function (): void {
    $mine = makeRequester();
    $someoneElse = User::factory()->requester()->create();

    Ticket::factory()->create(['requester_id' => $mine->getKey(), 'subject' => 'Mine']);
    Ticket::factory()->create(['requester_id' => $someoneElse->getKey(), 'subject' => 'Theirs']);

    $token = $mine->createToken('portal bot', ['tickets.read'])->plainTextToken;

    withToken($token)->getJson('/api/v1/tickets')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'Mine');
});

it('answers 404 rather than 403 for a ticket the caller may not see', function (): void {
    $mine = makeRequester();
    $theirs = Ticket::factory()->create([
        'requester_id' => User::factory()->requester()->create()->getKey(),
    ]);

    $token = $mine->createToken('portal bot', ['tickets.read'])->plainTextToken;

    // Telling 403 and 404 apart is a way to find out which ticket keys exist.
    withToken($token)->getJson('/api/v1/tickets/'.$theirs->key)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('paginates and caps the page size', function (): void {
    $agent = makeAgent();
    Ticket::factory()->count(5)->create(['requester_id' => $agent->getKey()]);

    $token = $agent->createToken('bot', ['tickets.read'])->plainTextToken;

    withToken($token)->getJson('/api/v1/tickets?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 5);

    // A caller cannot lift the ceiling by asking for a bigger number.
    withToken($token)->getJson('/api/v1/tickets?per_page=5000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('moves a ticket through the workflow and refuses an illegal move', function (): void {
    $agent = makeAgent();
    $ticket = Ticket::factory()->create([
        'requester_id' => $agent->getKey(),
        'status_id' => status('new')->getKey(),
    ]);

    $token = $agent->createToken('bot', ['tickets.write'])->plainTextToken;

    withToken($token)->postJson('/api/v1/tickets/'.$ticket->key.'/transition', [
        'status' => 'open',
    ])->assertOk()->assertJsonPath('data.status.slug', 'open');

    withToken($token)->postJson('/api/v1/tickets/'.$ticket->key.'/transition', [
        'status' => 'nonexistent-status',
    ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_status');
});

it('posts a public reply and refuses an internal note without the permission', function (): void {
    $customer = makeRequester();
    $ticket = Ticket::factory()->create(['requester_id' => $customer->getKey()]);

    $token = $customer->createToken('portal bot', ['comments.write'])->plainTextToken;

    withToken($token)->postJson('/api/v1/tickets/'.$ticket->key.'/comments', [
        'body' => 'Still happening this morning.',
    ])->assertCreated()->assertJsonPath('data.is_internal', false);

    withToken($token)->postJson('/api/v1/tickets/'.$ticket->key.'/comments', [
        'body' => 'Not for the customer',
        'internal' => true,
    ])->assertStatus(403)->assertJsonPath('error.code', 'internal_not_allowed');

    // Refused, not silently downgraded to a public reply.
    expect(Comment::query()->count())->toBe(1);
});

it('hides internal notes from a requester reading the conversation', function (): void {
    $customer = makeRequester();
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->create(['requester_id' => $customer->getKey()]);

    app(TicketService::class)
        ->comment($ticket, 'Public answer', $agent, internal: false);
    app(TicketService::class)
        ->comment($ticket, 'Internal: escalate to networking', $agent, internal: true);

    $token = $customer->createToken('portal bot', ['tickets.read'])->plainTextToken;

    $response = withToken($token)->getJson('/api/v1/tickets/'.$ticket->key.'/comments')->assertOk();

    // Both shapes: the markup as stored, and the same content flattened for
    // a caller with no room for it.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.body'))->toBe('<p>Public answer</p>')
        ->and($response->json('data.0.body_text'))->toBe('Public answer');
});

it('returns a validation error in the shared error shape', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('bot', ['tickets.write'])->plainTextToken;

    withToken($token)->postJson('/api/v1/tickets', ['description' => 'no subject'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['subject']]]);
});

/*
|--------------------------------------------------------------------------
| Who may hold a ticket, over the API
|--------------------------------------------------------------------------
|
| The same rule as the console. It is worth its own tests rather than trusting
| that both call the same validator, because the API is the door with no
| dropdown behind it: whatever it accepts is what somebody's script will send.
*/

it('refuses to file a ticket into hands outside its team', function (): void {
    $agent = makeAgent();
    $outsider = makeAgent();
    $team = Team::factory()->create();
    $token = $agent->createToken('deploy bot', ['tickets.write'])->plainTextToken;

    withToken($token)->postJson('/api/v1/tickets', [
        'subject' => 'Node 3 is unreachable',
        'team_id' => $team->id,
        'assignee_id' => $outsider->id,
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['assignee_id']]]);

    expect(Ticket::query()->count())->toBe(0);
});

it('files a ticket into hands on its team', function (): void {
    $agent = makeAgent();
    $member = makeAgent();
    $team = Team::factory()->create();
    $team->members()->attach($member);
    $token = $agent->createToken('deploy bot', ['tickets.write'])->plainTextToken;

    withToken($token)->postJson('/api/v1/tickets', [
        'subject' => 'Node 3 is unreachable',
        'team_id' => $team->id,
        'assignee_id' => $member->id,
    ])->assertCreated();

    expect(Ticket::query()->sole()->assignee_id)->toBe($member->id);
});

it('refuses to patch a ticket into hands outside its team', function (): void {
    $agent = makeAgent();
    $outsider = makeAgent();
    $team = Team::factory()->create();
    $ticket = Ticket::factory()->create(['team_id' => $team->id]);
    $token = $agent->createToken('deploy bot', ['tickets.write'])->plainTextToken;

    withToken($token)->patchJson("/api/v1/tickets/{$ticket->key}", [
        'assignee_id' => $outsider->id,
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['assignee_id']]]);

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

it('lets a patch move a ticket and assign it in one call', function (): void {
    $agent = makeAgent();
    $member = makeAgent();
    $team = Team::factory()->create();
    $team->members()->attach($member);
    $ticket = Ticket::factory()->create(['team_id' => null]);
    $token = $agent->createToken('deploy bot', ['tickets.write'])->plainTextToken;

    withToken($token)->patchJson("/api/v1/tickets/{$ticket->key}", [
        'team_id' => $team->id,
        'assignee_id' => $member->id,
    ])->assertOk();

    expect($ticket->fresh()->assignee_id)->toBe($member->id);
});
