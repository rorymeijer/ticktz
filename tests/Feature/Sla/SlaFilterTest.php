<?php

declare(strict_types=1);

use App\Models\BusinessCalendar;
use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Services\Tickets\TicketFilter;
use App\Services\Tickets\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();

    $this->calendar = BusinessCalendar::factory()->alwaysOpen()->utc()->create(['is_default' => true]);
    $this->policy = SlaPolicy::factory()->default()->on($this->calendar)->create();
    SlaGoal::factory()->for($this->policy, 'policy')->metric('first_response', 60)->create();

    $this->agent = makeAgent();
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));
});

function filterTickets(string $state): array
{
    $query = Ticket::query()->visibleTo(test()->agent);

    app(TicketFilter::class)->apply($query, ['sla' => $state], test()->agent);

    return $query->pluck('key')->all();
}

function newTicket(): Ticket
{
    return app(TicketService::class)->create([
        'subject' => 'Something is broken',
        'requester_id' => makeRequester()->getKey(),
    ]);
}

it('finds breached tickets', function (): void {
    $breached = newTicket();
    $healthy = newTicket();

    SlaTimer::query()->where('ticket_id', $breached->getKey())->update(['breached_at' => now()]);
    $breached->forceFill(['sla_breached' => true])->save();

    expect(filterTickets('breached'))->toBe([$breached->key]);
});

it('finds tickets about to breach but not those with hours to go', function (): void {
    $soon = newTicket();
    $later = newTicket();

    $soon->forceFill(['sla_due_at' => now()->addMinutes(20)])->save();
    $later->forceFill(['sla_due_at' => now()->addHours(6)])->save();

    expect(filterTickets('at_risk'))->toBe([$soon->key]);
});

it('does not count an already breached ticket as at risk', function (): void {
    $ticket = newTicket();
    $ticket->forceFill(['sla_due_at' => now()->addMinutes(20), 'sla_breached' => true])->save();

    expect(filterTickets('at_risk'))->toBe([]);
});

it('finds paused tickets', function (): void {
    $paused = newTicket();
    newTicket();

    app(TicketService::class)->transition($paused, status('waiting-for-requester'), $this->agent);

    expect(filterTickets('paused'))->toBe([$paused->key]);
});

it('finds tickets nothing is being measured on', function (): void {
    $measured = newTicket();

    SlaPolicy::query()->update(['is_active' => false, 'is_default' => false]);
    $unmeasured = newTicket();

    expect(filterTickets('none'))->toBe([$unmeasured->key])
        ->and(filterTickets('running'))->toBe([$measured->key]);
});

it('ignores a state it does not recognise rather than widening the result', function (): void {
    newTicket();
    newTicket();

    // An unknown value must not silently return everything *or* nothing by
    // accident — it is simply not a filter.
    expect(filterTickets('nonsense'))->toHaveCount(2);
});

it('sorts a list by what breaches first', function (): void {
    $later = newTicket();
    $sooner = newTicket();

    $later->forceFill(['sla_due_at' => now()->addHours(5)])->save();
    $sooner->forceFill(['sla_due_at' => now()->addHour()])->save();

    $query = Ticket::query()->visibleTo($this->agent);
    app(TicketFilter::class)->applySort($query, 'sla_due_at', 'asc');

    expect($query->pluck('key')->all())->toBe([$sooner->key, $later->key]);
});

it('shows the badge on the ticket list without a query per row', function (): void {
    newTicket();
    newTicket();
    newTicket();

    $response = $this->actingAs($this->agent)->get('/agent/tickets')->assertOk();

    $tickets = $response->viewData('page')['props']['tickets']['data'];

    expect($tickets)->toHaveCount(3)
        ->and($tickets[0]['sla'])->not->toBeNull()
        ->and($tickets[0]['sla']['metric'])->toBe('first_response');
});

it('puts the clocks and their history on the ticket detail', function (): void {
    $ticket = newTicket();

    $response = $this->actingAs($this->agent)->get("/agent/tickets/{$ticket->key}")->assertOk();

    $payload = $response->viewData('page')['props']['ticket'];

    expect($payload['sla_timers'])->toHaveCount(1)
        ->and($payload['sla_policy']['name'])->toBe($this->policy->name)
        ->and(collect($payload['sla_events'])->pluck('event')->all())->toContain('started');
});

/**
 * A ticket the breached filter returns must say so in the list. Once both
 * clocks have finished there is nothing live to show, and an empty cell on a
 * row the filter matched reads as a bug.
 */
it('still badges a ticket whose breached clock has since finished', function (): void {
    $ticket = newTicket();

    SlaTimer::query()->where('ticket_id', $ticket->getKey())->update([
        'status' => SlaTimer::STATUS_BREACHED,
        'breached_at' => now(),
        'completed_at' => now(),
    ]);
    $ticket->forceFill(['sla_breached' => true, 'sla_due_at' => null])->save();

    $response = $this->actingAs($this->agent)->get('/agent/tickets?sla=breached')->assertOk();

    $row = $response->viewData('page')['props']['tickets']['data'][0];

    expect($row['sla'])->not->toBeNull()
        ->and($row['sla']['status'])->toBe('breached');
});
