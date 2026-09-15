<?php

declare(strict_types=1);

use App\Events\Sla\SlaBreached;
use App\Events\Sla\SlaEscalated;
use App\Jobs\Sla\SweepSlaTimersJob;
use App\Models\AuditLogEntry;
use App\Models\BusinessCalendar;
use App\Models\SlaEvent;
use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\Sla\SlaEngine;
use App\Services\Sla\SlaEscalator;
use App\Services\Tickets\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();

    $this->calendar = BusinessCalendar::factory()->alwaysOpen()->utc()->create(['is_default' => true]);
    $this->policy = SlaPolicy::factory()->default()->on($this->calendar)->create();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));
});

/**
 * @param  array<int, array{at: int, actions: array<int, array<string, mixed>>}>|null  $escalations
 */
function goalWith(?array $escalations, int $minutes = 60): SlaGoal
{
    return SlaGoal::factory()
        ->for(test()->policy, 'policy')
        ->metric('first_response', $minutes)
        ->escalating($escalations ?? [])
        ->create();
}

function ticketWithSla(array $attributes = []): Ticket
{
    return app(TicketService::class)->create(array_merge([
        'subject' => 'Payroll export fails',
        'requester_id' => makeRequester()->getKey(),
    ], $attributes));
}

// -----------------------------------------------------------------
// Breach detection
// -----------------------------------------------------------------

it('marks a clock breached once the target has passed', function (): void {
    goalWith(null);
    $ticket = ticketWithSla();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));
    $result = app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    $timer = $ticket->slaTimers()->where('metric', 'first_response')->sole();

    expect($result['breached'])->toBe(1)
        ->and($timer->breached_at)->not->toBeNull()
        // Still running: "two hours late" and "two days late" differ.
        ->and($timer->status)->toBe(SlaTimer::STATUS_RUNNING)
        ->and($ticket->fresh()->sla_breached)->toBeTrue();
});

it('leaves a clock alone before its target', function (): void {
    goalWith(null);
    ticketWithSla();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:30', 'UTC'));
    $result = app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    expect($result['breached'])->toBe(0);
});

it('does not breach a paused clock', function (): void {
    goalWith(null);
    $ticket = ticketWithSla();

    app(TicketService::class)->transition($ticket, status('waiting-for-requester'), makeAgent());

    $this->travelTo(CarbonImmutable::parse('2025-06-02 13:00', 'UTC'));
    $result = app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    expect($result['breached'])->toBe(0)
        ->and($ticket->slaTimers()->where('metric', 'first_response')->value('breached_at'))->toBeNull();
});

/**
 * The sweep runs every minute. A breach must be announced once, however often
 * it is looked at.
 */
it('announces a breach once however many times the sweep runs', function (): void {
    Event::fake([SlaBreached::class]);
    goalWith(null);
    ticketWithSla();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));

    $first = app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));
    $second = app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));
    $third = app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    expect($first['breached'])->toBe(1)
        ->and($second['breached'])->toBe(0)
        ->and($third['breached'])->toBe(0);

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    expect(SlaEvent::query()->where('event', SlaEvent::BREACHED)->count())->toBe(1);
});

it('writes a breach to the audit log attributed to the system', function (): void {
    goalWith(null);
    $ticket = ticketWithSla();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));
    app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    $entry = AuditLogEntry::query()
        ->where('auditable_id', $ticket->getKey())
        ->where('event', 'sla.breached')
        ->sole();

    expect($entry->actor_type)->toBe('system')
        ->and($entry->actor_label)->toBe('sla');
});

// -----------------------------------------------------------------
// Escalation thresholds
// -----------------------------------------------------------------

it('fires an escalation at its threshold and not before', function (): void {
    Event::fake([SlaEscalated::class]);
    goalWith([['at' => 75, 'actions' => [['type' => 'notify', 'to' => 'assignee']]]]);
    ticketWithSla(['assignee_id' => makeAgent()->getKey()]);

    // 50% of a 60-minute target.
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:30', 'UTC'));
    expect(app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class))['escalated'])->toBe(0);

    // 75%.
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:45', 'UTC'));
    expect(app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class))['escalated'])->toBe(1);

    Event::assertDispatchedTimes(SlaEscalated::class, 1);
});

it('fires each threshold once however often the sweep runs', function (): void {
    goalWith([
        ['at' => 50, 'actions' => [['type' => 'notify', 'to' => 'assignee']]],
        ['at' => 75, 'actions' => [['type' => 'notify', 'to' => 'assignee']]],
    ]);
    ticketWithSla(['assignee_id' => makeAgent()->getKey()]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:50', 'UTC'));

    // Both thresholds are already behind us, so the first sweep fires both.
    expect(app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class))['escalated'])->toBe(2)
        ->and(app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class))['escalated'])->toBe(0);

    expect(SlaEvent::query()->where('event', SlaEvent::ESCALATED)->count())->toBe(2);
});

it('records which threshold fired', function (): void {
    goalWith([['at' => 75, 'actions' => [['type' => 'notify', 'to' => 'assignee']]]]);
    ticketWithSla(['assignee_id' => makeAgent()->getKey()]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:45', 'UTC'));
    app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    $event = SlaEvent::query()->where('event', SlaEvent::ESCALATED)->sole();

    expect($event->context['threshold'])->toBe(75)
        ->and($event->context['metric'])->toBe('first_response');
});

it('does not escalate a clock that has already been answered', function (): void {
    goalWith([['at' => 75, 'actions' => [['type' => 'notify', 'to' => 'assignee']]]]);
    $ticket = ticketWithSla(['assignee_id' => makeAgent()->getKey()]);

    app(TicketService::class)->comment($ticket, 'Looking now.', makeAgent());

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:00', 'UTC'));

    expect(app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class))['escalated'])->toBe(0);
});

it('ignores a malformed escalation step rather than failing the sweep', function (): void {
    goalWith([
        ['at' => 0, 'actions' => [['type' => 'notify']]],
        ['at' => 75, 'actions' => [['type' => 'not_a_real_action']]],
        ['at' => 80, 'actions' => [['type' => 'notify', 'to' => 'assignee']]],
    ]);
    ticketWithSla(['assignee_id' => makeAgent()->getKey()]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:00', 'UTC'));

    // Only the well-formed step survives.
    expect(app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class))['escalated'])->toBe(1);
});

// -----------------------------------------------------------------
// What an escalation does
// -----------------------------------------------------------------

it('puts the assignee on the ticket as a watcher so the mail goes out', function (): void {
    $agent = makeAgent();
    goalWith([['at' => 75, 'actions' => [['type' => 'notify', 'to' => 'assignee']]]]);
    $ticket = ticketWithSla(['assignee_id' => $agent->getKey()]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:45', 'UTC'));
    app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    expect($ticket->watchers()->whereKey($agent->getKey())->exists())->toBeTrue();
});

it('notifies the team leads rather than the whole team', function (): void {
    $team = Team::factory()->create();
    $lead = makeAgent();
    $member = makeAgent();

    $team->members()->attach($lead, ['role' => 'lead']);
    $team->members()->attach($member, ['role' => 'member']);

    goalWith([['at' => 100, 'actions' => [['type' => 'notify', 'to' => 'team_leads']]]]);
    $ticket = ticketWithSla(['team_id' => $team->getKey()]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));
    app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    expect($ticket->watchers()->whereKey($lead->getKey())->exists())->toBeTrue()
        ->and($ticket->watchers()->whereKey($member->getKey())->exists())->toBeFalse();
});

it('raises the priority one step and records why', function (): void {
    goalWith([['at' => 100, 'actions' => [['type' => 'raise_priority']]]]);
    $ticket = ticketWithSla(['priority_id' => priority('normal')->getKey()]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));
    app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    expect($ticket->fresh()->priority_id)->toBe(priority('high')->getKey());

    expect(AuditLogEntry::query()
        ->where('auditable_id', $ticket->getKey())
        ->where('event', 'sla.priority_raised')
        ->exists())->toBeTrue();
});

it('leaves the most urgent priority where it is', function (): void {
    goalWith([['at' => 100, 'actions' => [['type' => 'raise_priority']]]]);
    $ticket = ticketWithSla(['priority_id' => priority('urgent')->getKey()]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));
    app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    expect($ticket->fresh()->priority_id)->toBe(priority('urgent')->getKey());
});

it('hands the ticket to another team and records the move', function (): void {
    $escalationTeam = Team::factory()->create();
    goalWith([['at' => 100, 'actions' => [
        ['type' => 'assign_team', 'team_id' => $escalationTeam->getKey()],
    ]]]);
    $ticket = ticketWithSla();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));
    app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    expect($ticket->fresh()->team_id)->toBe($escalationTeam->getKey())
        ->and(AuditLogEntry::query()
            ->where('auditable_id', $ticket->getKey())
            ->where('event', 'sla.team_changed')
            ->exists())->toBeTrue();
});

// -----------------------------------------------------------------
// The sweep itself
// -----------------------------------------------------------------

it('carries on past a timer it cannot process', function (): void {
    goalWith(null);
    ticketWithSla();
    ticketWithSla();

    // A timer whose ticket has been hard-deleted underneath it.
    $orphan = SlaTimer::query()->first();
    Ticket::query()->whereKey($orphan->ticket_id)->forceDelete();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));

    $result = app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class));

    // The surviving ticket's clocks were still processed.
    expect($result['breached'])->toBeGreaterThan(0);
});

it('asks only for timers whose goal actually escalates', function (): void {
    goalWith(null);
    ticketWithSla();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));

    expect(app(SweepSlaTimersJob::class)->handle(app(SlaEngine::class), app(SlaEscalator::class))['escalated'])->toBe(0);
});
