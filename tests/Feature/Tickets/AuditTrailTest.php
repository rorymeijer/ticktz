<?php

declare(strict_types=1);

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Services\Tickets\TicketService;

beforeEach(function () {
    seedServiceDesk();
});

test('ticket actions taken outside a request are still attributed to the actor', function () {
    // Seeders, console commands and inbound e-mail have no authenticated user
    // for the logger to pick up; the actor has to survive anyway or the trail
    // claims the system did work a person did.
    $agent = User::factory()->agent()->create();
    $requester = User::factory()->requester()->create();

    $ticket = app(TicketService::class)->create([
        'subject' => 'Console-created ticket',
        'requester_id' => $requester->id,
    ], $agent);

    app(TicketService::class)->assign($ticket, $agent, $agent);
    app(TicketService::class)->transition($ticket, status('open'), $agent);
    app(TicketService::class)->comment($ticket, 'Working on it', $agent);

    $entries = AuditLogEntry::query()->forSubject($ticket)->get();

    expect($entries)->toHaveCount(4)
        ->and($entries->pluck('user_id')->unique()->all())->toBe([$agent->id])
        ->and($entries->pluck('event')->all())->toEqual([
            'created', 'ticket.assigned', 'ticket.transitioned', 'ticket.replied',
        ]);
});

test('the ticket timeline interleaves comments and system events in order', function () {
    $agent = User::factory()->admin()->create();

    $ticket = app(TicketService::class)->create([
        'subject' => 'Timeline order',
        'requester_id' => User::factory()->requester()->create()->id,
    ], $agent);

    app(TicketService::class)->comment($ticket, 'First reply', $agent);
    app(TicketService::class)->transition($ticket->fresh(), status('open'), $agent);

    $this->actingAs($agent)
        ->get("/agent/tickets/{$ticket->key}")
        ->assertInertia(fn ($page) => $page
            ->where('timeline.0.type', 'event')
            ->where('timeline.0.event', 'created')
            ->where('timeline.1.type', 'comment')
            ->where('timeline.2.type', 'event')
            ->where('timeline.2.event', 'ticket.transitioned'));
});
