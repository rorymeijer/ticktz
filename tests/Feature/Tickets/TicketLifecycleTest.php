<?php

declare(strict_types=1);

use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketCommented;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketTransitioned;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    seedServiceDesk();
});

test('a new ticket gets a readable key from the configured prefix', function () {
    $agent = User::factory()->agent()->create();
    $requester = User::factory()->requester()->create();

    $ticket = app(TicketService::class)->create([
        'subject' => 'Printer op 3e etage doet niets',
        'description' => 'Geen reactie op de aan-knop.',
        'requester_id' => $requester->id,
    ], $agent);

    expect($ticket->key)->toBe('SUP-1')
        ->and($ticket->prefix)->toBe('SUP')
        ->and($ticket->number)->toBe(1)
        ->and($ticket->status->slug)->toBe('new')
        ->and($ticket->priority->slug)->toBe('normal');

    $second = app(TicketService::class)->create([
        'subject' => 'Tweede melding',
        'requester_id' => $requester->id,
    ], $agent);

    expect($second->key)->toBe('SUP-2');
});

test('the ticket key prefix follows the setting', function () {
    app(SettingsRepository::class)->set('tickets.key_prefix', 'ICT');

    $ticket = app(TicketService::class)->create([
        'subject' => 'Test',
        'requester_id' => User::factory()->requester()->create()->id,
    ]);

    expect($ticket->key)->toStartWith('ICT-');
});

test('a ticket inherits the organisation of its requester', function () {
    $organization = Organization::factory()->create();
    $requester = User::factory()->requester()->create(['organization_id' => $organization->id]);

    $ticket = app(TicketService::class)->create([
        'subject' => 'Test',
        'requester_id' => $requester->id,
    ]);

    expect($ticket->organization_id)->toBe($organization->id);
});

test('the requester is watching their own ticket from the start', function () {
    $requester = User::factory()->requester()->create();

    $ticket = app(TicketService::class)->create([
        'subject' => 'Test',
        'requester_id' => $requester->id,
    ]);

    expect($ticket->watchers()->pluck('users.id')->all())->toContain($requester->id);
});

test('creating a ticket emits the domain event and writes an audit entry', function () {
    Event::fake([TicketCreated::class]);

    $ticket = app(TicketService::class)->create([
        'subject' => 'Test',
        'requester_id' => User::factory()->requester()->create()->id,
    ]);

    Event::assertDispatched(TicketCreated::class, fn (TicketCreated $event) => $event->ticket->is($ticket));

    expect(AuditLogEntry::query()->forSubject($ticket)->where('event', 'created')->exists())->toBeTrue();
});

test('a legal transition moves the ticket and stamps the lifecycle', function () {
    Event::fake([TicketTransitioned::class]);

    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->transition($ticket, status('open'), $agent);
    expect($ticket->fresh()->status->slug)->toBe('open');

    app(TicketService::class)->transition($ticket, status('resolved'), $agent);
    $ticket->refresh();

    expect($ticket->status->slug)->toBe('resolved')
        ->and($ticket->resolved_at)->not->toBeNull();

    Event::assertDispatchedTimes(TicketTransitioned::class, 2);
});

test('an illegal transition is refused', function () {
    $ticket = Ticket::factory()->create();

    // The default workflow has no direct New -> Closed step.
    expect(fn () => app(TicketService::class)->transition($ticket, status('closed')))
        ->toThrow(RuntimeException::class);

    expect($ticket->fresh()->status->slug)->toBe('new');
});

test('reopening a resolved ticket clears the resolution and counts', function () {
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->transition($ticket, status('open'), $agent);
    app(TicketService::class)->transition($ticket, status('resolved'), $agent);
    app(TicketService::class)->transition($ticket->fresh(), status('open'), $agent);

    $ticket->refresh();

    expect($ticket->resolved_at)->toBeNull()
        ->and($ticket->reopened_at)->not->toBeNull()
        ->and($ticket->reopen_count)->toBe(1);
});

test('assignment is recorded, audited and emitted', function () {
    Event::fake([TicketAssigned::class]);

    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->assign($ticket, $agent, $agent);

    expect($ticket->fresh()->assignee_id)->toBe($agent->id)
        ->and($ticket->watchers()->pluck('users.id')->all())->toContain($agent->id);

    Event::assertDispatched(TicketAssigned::class);

    expect(AuditLogEntry::query()->forSubject($ticket)->where('event', 'ticket.assigned')->exists())->toBeTrue();
});

test('the first non-requester reply sets the first response timestamp', function () {
    Event::fake([TicketCommented::class]);

    $requester = User::factory()->requester()->create();
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    // The requester talking to themselves is not a response.
    app(TicketService::class)->comment($ticket, 'Aanvulling van mijzelf', $requester);
    expect($ticket->fresh()->first_response_at)->toBeNull();

    app(TicketService::class)->comment($ticket, 'We kijken ernaar', $agent);
    $ticket->refresh();

    expect($ticket->first_response_at)->not->toBeNull()
        ->and($ticket->last_public_reply_at)->not->toBeNull();

    Event::assertDispatchedTimes(TicketCommented::class, 2);
});

test('an internal note never counts as a response to the requester', function () {
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->comment($ticket, 'Even met de leverancier bellen', $agent, internal: true);

    $ticket->refresh();

    expect($ticket->first_response_at)->toBeNull()
        ->and($ticket->last_public_reply_at)->toBeNull()
        ->and($ticket->last_activity_at)->not->toBeNull();
});

test('a transition that carries a comment records both', function () {
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->transition($ticket, status('open'), $agent, 'Opgepakt door de servicedesk');

    expect($ticket->comments()->count())->toBe(1)
        ->and($ticket->comments()->first()->is_internal)->toBeFalse();
});
