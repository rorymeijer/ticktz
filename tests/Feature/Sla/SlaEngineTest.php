<?php

declare(strict_types=1);

use App\Models\BusinessCalendar;
use App\Models\SlaEvent;
use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\Sla\SlaEngine;
use App\Services\Tickets\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();

    // An always-open calendar keeps these tests about the engine: a target of
    // 60 minutes is 60 minutes of wall time. The calendar itself is tested
    // separately.
    $this->calendar = BusinessCalendar::factory()->alwaysOpen()->utc()->create(['is_default' => true]);

    $this->policy = SlaPolicy::factory()->default()->on($this->calendar)->create();

    SlaGoal::factory()->for($this->policy, 'policy')->metric('first_response', 60)->create();
    SlaGoal::factory()->for($this->policy, 'policy')->resolution(480)->create();

    $this->engine = app(SlaEngine::class);
});

function slaTicket(array $attributes = []): Ticket
{
    return app(TicketService::class)->create(array_merge([
        'subject' => 'The lift is stuck between floors',
        'requester_id' => makeRequester()->getKey(),
    ], $attributes));
}

it('starts both clocks when a ticket is created', function (): void {
    $ticket = slaTicket();

    $timers = $ticket->slaTimers()->get();

    expect($timers)->toHaveCount(2)
        ->and($timers->pluck('metric')->sort()->values()->all())->toBe(['first_response', 'resolution'])
        ->and($timers->every(fn (SlaTimer $timer) => $timer->status === SlaTimer::STATUS_RUNNING))->toBeTrue()
        ->and($ticket->fresh()->sla_policy_id)->toBe($this->policy->getKey());
});

it('sets the due date from the target and the calendar', function (): void {
    $at = CarbonImmutable::parse('2025-06-02 10:00', 'UTC');
    $this->travelTo($at);

    $ticket = slaTicket();
    $timer = $ticket->slaTimers()->where('metric', 'first_response')->sole();

    expect($timer->due_at->utc()->format('Y-m-d H:i'))->toBe('2025-06-02 11:00');
});

it('mirrors the tightest clock onto the ticket for list queries', function (): void {
    $ticket = slaTicket();
    $first = $ticket->slaTimers()->where('metric', 'first_response')->sole();

    expect($ticket->fresh()->sla_due_at->utc()->format('Y-m-d H:i'))
        ->toBe($first->due_at->utc()->format('Y-m-d H:i'));
});

it('records why every clock started', function (): void {
    $ticket = slaTicket();

    expect(SlaEvent::query()->where('ticket_id', $ticket->getKey())->where('event', 'started')->count())->toBe(2);
});

it('does not start a second set of clocks for a ticket that has them', function (): void {
    $ticket = slaTicket();
    $ticket->load('slaTimers');

    $this->engine->start($ticket);

    expect($ticket->slaTimers()->count())->toBe(2);
});

it('starts nothing when no policy applies', function (): void {
    SlaPolicy::query()->update(['is_active' => false, 'is_default' => false]);

    $ticket = slaTicket();

    expect($ticket->slaTimers()->count())->toBe(0)
        ->and($ticket->fresh()->sla_due_at)->toBeNull();
});

// -----------------------------------------------------------------
// Which promise applies
// -----------------------------------------------------------------

it('picks the goal for the ticket priority over the catch-all', function (): void {
    $urgent = priority('urgent');
    SlaGoal::factory()->for($this->policy, 'policy')
        ->metric('first_response', 15)
        ->create(['priority_id' => $urgent->getKey()]);

    $ticket = slaTicket(['priority_id' => $urgent->getKey()]);

    expect($ticket->slaTimers()->where('metric', 'first_response')->value('target_minutes'))->toBe(15);
});

it('prefers a policy whose conditions match over the default', function (): void {
    $team = Team::factory()->create();
    $calendar = BusinessCalendar::factory()->alwaysOpen()->utc()->create();

    $specific = SlaPolicy::factory()->on($calendar)
        ->claiming(['team_ids' => [$team->getKey()]], position: 10)
        ->create();

    SlaGoal::factory()->for($specific, 'policy')->metric('first_response', 5)->create();

    $ticket = slaTicket(['team_id' => $team->getKey()]);

    expect($ticket->fresh()->sla_policy_id)->toBe($specific->getKey())
        ->and($ticket->slaTimers()->where('metric', 'first_response')->value('target_minutes'))->toBe(5);
});

it('falls back to the default policy when no conditions match', function (): void {
    $calendar = BusinessCalendar::factory()->alwaysOpen()->utc()->create();
    SlaPolicy::factory()->on($calendar)
        ->claiming(['team_ids' => [999]], position: 10)
        ->create();

    $ticket = slaTicket();

    expect($ticket->fresh()->sla_policy_id)->toBe($this->policy->getKey());
});

// -----------------------------------------------------------------
// Pausing
// -----------------------------------------------------------------

it('stops the clock on a waiting status and restarts it on the way out', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));

    $ticket = slaTicket();
    $agent = makeAgent();

    app(TicketService::class)->transition($ticket, status('waiting-for-requester'), $agent);

    $timer = $ticket->slaTimers()->where('metric', 'resolution')->sole();
    expect($timer->status)->toBe(SlaTimer::STATUS_PAUSED)
        ->and($timer->paused_at)->not->toBeNull();

    // Three hours of the customer's time, which the desk does not owe.
    $this->travelTo(CarbonImmutable::parse('2025-06-02 13:00', 'UTC'));
    app(TicketService::class)->transition($ticket->fresh(), status('open'), $agent);

    $timer->refresh();

    expect($timer->status)->toBe(SlaTimer::STATUS_RUNNING)
        ->and($timer->paused_at)->toBeNull()
        ->and($timer->paused_minutes)->toBe(180)
        // 10:00 + 8h = 18:00, pushed out by the three-hour pause.
        ->and($timer->due_at->utc()->format('Y-m-d H:i'))->toBe('2025-06-02 21:00');
});

it('does not count a pause twice when the ticket moves between waiting statuses', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));

    $ticket = slaTicket();
    $agent = makeAgent();

    app(TicketService::class)->transition($ticket, status('waiting-for-requester'), $agent);
    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:00', 'UTC'));

    // Both statuses pause, so nothing should resume in between.
    app(TicketService::class)->transition($ticket->fresh(), status('open'), $agent);
    app(TicketService::class)->transition($ticket->fresh(), status('waiting-for-third-party'), $agent);

    $timer = $ticket->slaTimers()->where('metric', 'resolution')->sole();

    expect($timer->status)->toBe(SlaTimer::STATUS_PAUSED)
        ->and($timer->paused_minutes)->toBe(60);
});

it('freezes the countdown while paused', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));

    $ticket = slaTicket();
    app(TicketService::class)->transition($ticket, status('waiting-for-requester'), makeAgent());

    $timer = $ticket->slaTimers()->where('metric', 'first_response')->sole();
    $atPause = $timer->minutesRemaining();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 12:00', 'UTC'));

    expect($timer->fresh()->minutesRemaining())->toBe($atPause);
});

// -----------------------------------------------------------------
// Completing
// -----------------------------------------------------------------

it('closes the first response clock on the first public agent reply', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));
    $ticket = slaTicket();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:30', 'UTC'));
    app(TicketService::class)->comment($ticket, 'Looking into it now.', makeAgent());

    $timer = $ticket->slaTimers()->where('metric', 'first_response')->sole();

    expect($timer->status)->toBe(SlaTimer::STATUS_MET)
        ->and($timer->completed_at->utc()->format('H:i'))->toBe('10:30')
        // The resolution clock is still running.
        ->and($ticket->slaTimers()->where('metric', 'resolution')->value('status'))
        ->toBe(SlaTimer::STATUS_RUNNING);
});

it('does not count an internal note as a first response', function (): void {
    $ticket = slaTicket();

    app(TicketService::class)->comment($ticket, 'Anyone know who owns this lift?', makeAgent(), internal: true);

    expect($ticket->slaTimers()->where('metric', 'first_response')->value('status'))
        ->toBe(SlaTimer::STATUS_RUNNING);
});

it('does not count the requester writing again as a response', function (): void {
    $requester = makeRequester();
    $ticket = slaTicket(['requester_id' => $requester->getKey()]);

    app(TicketService::class)->comment($ticket, 'It is still stuck.', $requester);

    expect($ticket->slaTimers()->where('metric', 'first_response')->value('status'))
        ->toBe(SlaTimer::STATUS_RUNNING);
});

it('marks a late first response as breached', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));
    $ticket = slaTicket();

    // The 60-minute target passed two hours ago.
    $this->travelTo(CarbonImmutable::parse('2025-06-02 13:00', 'UTC'));
    app(TicketService::class)->comment($ticket, 'Sorry for the delay.', makeAgent());

    $timer = $ticket->slaTimers()->where('metric', 'first_response')->sole();

    expect($timer->status)->toBe(SlaTimer::STATUS_BREACHED)
        ->and($timer->breached_at)->not->toBeNull()
        ->and($ticket->fresh()->sla_breached)->toBeTrue();
});

it('closes both clocks when the ticket is resolved', function (): void {
    $ticket = slaTicket();

    app(TicketService::class)->transition($ticket, status('resolved'), makeAgent());

    expect($ticket->slaTimers()->pluck('status')->unique()->all())->toBe([SlaTimer::STATUS_MET]);
});

it('gives a reopened ticket a fresh resolution clock', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));
    $ticket = slaTicket();
    $agent = makeAgent();

    app(TicketService::class)->transition($ticket, status('resolved'), $agent);

    $this->travelTo(CarbonImmutable::parse('2025-06-03 10:00', 'UTC'));
    app(TicketService::class)->transition($ticket->fresh(), status('open'), $agent);

    $timer = $ticket->slaTimers()->where('metric', 'resolution')->sole();

    expect($timer->status)->toBe(SlaTimer::STATUS_RUNNING)
        ->and($timer->started_at->utc()->format('Y-m-d H:i'))->toBe('2025-06-03 10:00')
        ->and($timer->due_at->utc()->format('Y-m-d H:i'))->toBe('2025-06-03 18:00')
        ->and($timer->breached_at)->toBeNull();
});

it('does not reopen a first response clock that was already answered', function (): void {
    $ticket = slaTicket();
    $agent = makeAgent();

    app(TicketService::class)->comment($ticket, 'On it.', $agent);
    app(TicketService::class)->transition($ticket->fresh(), status('resolved'), $agent);
    app(TicketService::class)->transition($ticket->fresh(), status('open'), $agent);

    expect($ticket->slaTimers()->where('metric', 'first_response')->value('status'))
        ->toBe(SlaTimer::STATUS_MET);
});

// -----------------------------------------------------------------
// Recalculating
// -----------------------------------------------------------------

it('moves the target when the priority changes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));

    $urgent = priority('urgent');
    SlaGoal::factory()->for($this->policy, 'policy')
        ->metric('first_response', 15)
        ->create(['priority_id' => $urgent->getKey()]);

    $ticket = slaTicket();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:05', 'UTC'));
    app(TicketService::class)->update($ticket, ['priority_id' => $urgent->getKey()], makeAgent());

    $timer = $ticket->slaTimers()->where('metric', 'first_response')->sole();

    expect($timer->target_minutes)->toBe(15)
        // Measured from when the ticket was filed, not from the change: the
        // five minutes already spent count against the new target.
        ->and($timer->due_at->utc()->format('H:i'))->toBe('10:15');
});

it('records a recalculation rather than silently rewriting the clock', function (): void {
    $urgent = priority('urgent');
    SlaGoal::factory()->for($this->policy, 'policy')
        ->metric('first_response', 15)
        ->create(['priority_id' => $urgent->getKey()]);

    $ticket = slaTicket();
    app(TicketService::class)->update($ticket, ['priority_id' => $urgent->getKey()], makeAgent());

    $event = SlaEvent::query()->where('ticket_id', $ticket->getKey())
        ->where('event', SlaEvent::RECALCULATED)->sole();

    expect($event->context['from_minutes'])->toBe(60)
        ->and($event->context['to_minutes'])->toBe(15);
});

it('leaves a finished clock alone when the priority changes', function (): void {
    $urgent = priority('urgent');
    SlaGoal::factory()->for($this->policy, 'policy')
        ->metric('first_response', 15)
        ->create(['priority_id' => $urgent->getKey()]);

    $ticket = slaTicket();
    app(TicketService::class)->comment($ticket, 'On it.', makeAgent());

    app(TicketService::class)->update($ticket->fresh(), ['priority_id' => $urgent->getKey()], makeAgent());

    expect($ticket->slaTimers()->where('metric', 'first_response')->value('target_minutes'))->toBe(60);
});

/**
 * An administrator who shortens a target on Tuesday has not thereby breached
 * every ticket opened on Monday.
 */
it('keeps the promise a ticket was given when the goal is edited afterwards', function (): void {
    $ticket = slaTicket();

    SlaGoal::query()->where('metric', 'first_response')->update(['target_minutes' => 5]);

    $timer = $ticket->slaTimers()->where('metric', 'first_response')->sole();

    expect($timer->target_minutes)->toBe(60);
});

it('keeps a timer when the policy that made it is deleted', function (): void {
    $ticket = slaTicket();
    $this->policy->delete();

    $timer = $ticket->slaTimers()->where('metric', 'first_response')->sole();

    expect($timer->target_minutes)->toBe(60)
        ->and($timer->sla_policy_id)->toBeNull();
});
