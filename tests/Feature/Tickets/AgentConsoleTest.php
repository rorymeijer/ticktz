<?php

declare(strict_types=1);

use App\Models\Label;
use App\Models\Queue;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;

beforeEach(function () {
    seedServiceDesk();
});

test('the ticket list requires a ticket permission', function () {
    $requester = User::factory()->requester()->create();

    $this->actingAs($requester)->get('/agent/tickets')->assertForbidden();
});

test('an agent sees the ticket list', function () {
    $agent = User::factory()->admin()->create();
    Ticket::factory()->count(3)->create();

    $this->actingAs($agent)
        ->get('/agent/tickets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agent/Tickets/Index')->has('tickets.data', 3));
});

test('the list only contains tickets the agent may see', function () {
    $agent = makeUserWithPermissions('tickets.view');
    $mine = Ticket::factory()->assignedTo($agent)->create();
    Ticket::factory()->count(2)->create();

    $this->actingAs($agent->fresh())
        ->get('/agent/tickets')
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1)
            ->where('tickets.data.0.key', $mine->key));
});

test('a queue applies its saved filter', function () {
    $agent = User::factory()->admin()->create();

    $unassigned = Ticket::factory()->create();
    Ticket::factory()->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->get('/agent/tickets?queue_slug=unassigned')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1)
            ->where('tickets.data.0.key', $unassigned->key)
            ->where('queue.slug', 'unassigned'));
});

test('searching by ticket key jumps straight to it', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();
    Ticket::factory()->count(3)->create();

    $this->actingAs($agent)
        ->get('/agent/tickets?search='.$ticket->key)
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1)
            ->where('tickets.data.0.key', $ticket->key));
});

test('searching by subject matches on text', function () {
    $agent = User::factory()->admin()->create();
    Ticket::factory()->create(['subject' => 'VPN verbinding valt weg']);
    Ticket::factory()->create(['subject' => 'Printer storing']);

    $this->actingAs($agent)
        ->get('/agent/tickets?search=VPN')
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1));
});

test('an agent creates a ticket through the console', function () {
    $agent = User::factory()->admin()->create();
    $requester = User::factory()->requester()->create();
    $label = Label::factory()->create();

    $this->actingAs($agent)
        ->post('/agent/tickets', [
            'subject' => 'Nieuw werkstation aanvragen',
            'description' => 'Voor de nieuwe collega per 1 oktober.',
            'requester_id' => $requester->id,
            'priority_id' => priority('high')->id,
            'label_ids' => [$label->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $ticket = Ticket::query()->sole();

    expect($ticket->subject)->toBe('Nieuw werkstation aanvragen')
        ->and($ticket->requester_id)->toBe($requester->id)
        ->and($ticket->priority->slug)->toBe('high')
        ->and($ticket->labels->pluck('id')->all())->toBe([$label->id])
        ->and($ticket->created_by)->toBe($agent->id);
});

test('creating a ticket validates its payload', function () {
    $agent = User::factory()->admin()->create();

    $this->actingAs($agent)
        ->post('/agent/tickets', ['subject' => '', 'requester_id' => 999999])
        ->assertSessionHasErrors(['subject', 'requester_id']);
});

test('an agent opens a ticket by its key', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->get("/agent/tickets/{$ticket->key}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agent/Tickets/Show')
            ->where('ticket.key', $ticket->key)
            ->has('transitions'));
});

test('an agent cannot open a ticket outside their scope', function () {
    $agent = makeUserWithPermissions('tickets.view');
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent->fresh())->get("/agent/tickets/{$ticket->key}")->assertForbidden();
});

test('an agent claims a ticket', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->post("/agent/tickets/{$ticket->key}/claim")->assertRedirect();

    expect($ticket->fresh()->assignee_id)->toBe($agent->id);
});

test('a requester cannot be made the assignee', function () {
    $agent = User::factory()->admin()->create();
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}/assignee", ['assignee_id' => $requester->id])
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

test('a transition that needs a comment is refused without one', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->transition($ticket, status('open'), $agent);

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/transition", ['status_id' => status('resolved')->id])
        ->assertSessionHasErrors('comment');

    expect($ticket->fresh()->status->slug)->toBe('open');

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/transition", [
            'status_id' => status('resolved')->id,
            'comment' => 'Nieuwe toner geplaatst.',
        ])
        ->assertRedirect();

    expect($ticket->fresh()->status->slug)->toBe('resolved');
});

test('an illegal transition is rejected by the endpoint too', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/transition", ['status_id' => status('closed')->id])
        ->assertSessionHasErrors('status_id');
});

test('an agent updates ticket fields', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}", ['priority_id' => priority('urgent')->id])
        ->assertRedirect();

    expect($ticket->fresh()->priority->slug)->toBe('urgent');
});

test('tickets can be linked to each other and the inverse is shown', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();
    $other = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/links", ['related_key' => $other->key, 'type' => 'duplicates'])
        ->assertRedirect();

    $this->actingAs($agent)
        ->get("/agent/tickets/{$other->key}")
        ->assertInertia(fn ($page) => $page->where('ticket.links.0.type', 'duplicated_by')
            ->where('ticket.links.0.ticket.key', $ticket->key));
});

test('a ticket cannot be linked to itself', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/links", ['related_key' => $ticket->key, 'type' => 'relates'])
        ->assertSessionHasErrors('related_key');
});

test('the queue overview counts tickets per queue', function () {
    $agent = User::factory()->admin()->create();
    Ticket::factory()->count(2)->create();

    $this->actingAs($agent)
        ->get('/agent/queues')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agent/Queues/Index')
            ->has('queues', Queue::query()->count()));
});
