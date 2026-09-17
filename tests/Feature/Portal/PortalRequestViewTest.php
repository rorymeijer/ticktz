<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;

beforeEach(function () {
    seedServiceDesk();
});

test('a requester sees only their own requests', function () {
    $requester = User::factory()->requester()->create();
    Ticket::factory()->forRequester($requester)->create();
    Ticket::factory()->count(2)->create();

    $this->actingAs($requester->fresh())
        ->get('/portal/requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Requests/Index')->has('requests.data', 1));
});

test('the portal request list can be filtered by open and closed', function () {
    $requester = User::factory()->requester()->create();
    $agent = User::factory()->admin()->create();

    Ticket::factory()->forRequester($requester)->create();
    $resolved = Ticket::factory()->forRequester($requester)->create();

    app(TicketService::class)->transition($resolved, status('open'), $agent);
    app(TicketService::class)->transition($resolved->fresh(), status('resolved'), $agent);

    $this->actingAs($requester->fresh())
        ->get('/portal/requests?status=open')
        ->assertInertia(fn ($page) => $page->has('requests.data', 1));

    $this->actingAs($requester->fresh())
        ->get('/portal/requests?status=closed')
        ->assertInertia(fn ($page) => $page->has('requests.data', 1)
            ->where('requests.data.0.key', $resolved->key));
});

test('the portal never shows an internal note', function () {
    $requester = User::factory()->requester()->create();
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    app(TicketService::class)->comment($ticket, 'Public answer for you', $agent);
    app(TicketService::class)->comment($ticket, 'CONFIDENTIAL vendor pricing', $agent, internal: true);

    $this->actingAs($requester->fresh())
        ->get("/portal/requests/{$ticket->key}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('comments', 1)
            ->where('comments.0.body', '<p>Public answer for you</p>'))
        ->assertDontSee('CONFIDENTIAL vendor pricing');
});

test('the portal hides agent-only custom fields', function () {
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    CustomField::factory()->create(['key' => 'system_name', 'label' => 'System']);
    CustomField::factory()->internal()->create(['key' => 'cost_centre', 'label' => 'Cost centre']);

    $ticket->setCustomFields(['system_name' => 'zaaksysteem', 'cost_centre' => '9999']);

    $this->actingAs($requester->fresh())
        ->get("/portal/requests/{$ticket->key}")
        ->assertInertia(fn ($page) => $page->has('request.fields', 1)
            ->where('request.fields.0.key', 'system_name'))
        ->assertDontSee('9999');
});

test('a requester cannot open someone else request', function () {
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($requester->fresh())->get("/portal/requests/{$ticket->key}")->assertForbidden();
});

test('a colleague can open a request when the organisation shares visibility', function () {
    $organization = Organization::factory()->withSharedVisibility()->create();
    $colleague = User::factory()->requester()->create(['organization_id' => $organization->id]);
    $author = User::factory()->requester()->create(['organization_id' => $organization->id]);

    $ticket = Ticket::factory()->forRequester($author)->create();

    $this->actingAs($colleague->fresh())
        ->get("/portal/requests/{$ticket->key}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('request.key', $ticket->key));
});

test('a requester replies from the portal and the reply is public', function () {
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $this->actingAs($requester->fresh())
        ->post("/portal/requests/{$ticket->key}/comments", ['body' => 'Any news on this?'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $comment = $ticket->comments()->sole();

    expect($comment->is_internal)->toBeFalse()
        ->and($comment->user_id)->toBe($requester->id)
        ->and($ticket->fresh()->last_requester_reply_at)->not->toBeNull();
});

test('a requester cannot post an internal note even by crafting the payload', function () {
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $this->actingAs($requester->fresh())
        ->post("/portal/requests/{$ticket->key}/comments", [
            'body' => 'Sneaky',
            'is_internal' => true,
        ])
        ->assertRedirect();

    expect($ticket->comments()->sole()->is_internal)->toBeFalse();
});

test('a requester cannot reach the agent console for their own ticket', function () {
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $this->actingAs($requester->fresh())->get("/agent/tickets/{$ticket->key}")->assertForbidden();
});
