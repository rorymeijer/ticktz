<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Models\BusinessCalendar;
use App\Models\SlaEvent;
use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts, pauses, resumes and closes the clocks on a ticket.
 *
 * Everything that changes a timer goes through here, so there is exactly one
 * place that knows the rules:
 *
 * - A clock is created when a ticket is created, and only then. A ticket whose
 *   priority changes gets its target recalculated, not replaced.
 * - A clock pauses when the ticket moves to a status flagged `pauses_sla`, and
 *   resumes when it leaves. The pause is *paid back* into the due date: five
 *   working hours waiting on the customer push the deadline five working hours
 *   out, which is the whole point of pausing rather than stopping.
 * - A clock completes on the event it measures — the first public reply from
 *   anyone but the requester, or the move into a resolved status — and is
 *   marked met or breached by comparing that moment to the due date.
 * - A clock that has already finished is never reopened by a later event. The
 *   exception is a reopened ticket, which gets a fresh resolution clock,
 *   because a promise to fix it again is a new promise.
 */
class SlaEngine
{
    public function __construct(private readonly SlaEscalator $escalator) {}

    /**
     * Give a new ticket its clocks. Idempotent: a ticket that already has a
     * timer for a metric keeps it.
     *
     * @return Collection<int, SlaTimer>
     */
    public function start(Ticket $ticket, ?CarbonImmutable $at = null): Collection
    {
        $policy = $this->policyFor($ticket);

        if (! $policy) {
            return collect();
        }

        $at ??= CarbonImmutable::now();
        $calendar = $policy->calendar;
        $started = collect();

        foreach (SlaGoal::METRICS as $metric) {
            $goal = $policy->goalFor($metric, $ticket);

            if (! $goal) {
                continue;
            }

            $existing = $ticket->slaTimers->firstWhere('metric', $metric);

            if ($existing) {
                continue;
            }

            $timer = SlaTimer::query()->create([
                'ticket_id' => $ticket->getKey(),
                'sla_policy_id' => $policy->getKey(),
                'sla_goal_id' => $goal->getKey(),
                'business_calendar_id' => $calendar?->getKey(),
                'metric' => $metric,
                'target_minutes' => $goal->target_minutes,
                'started_at' => $at,
                'due_at' => $this->dueAt($calendar, $at, $goal->target_minutes),
                'status' => SlaTimer::STATUS_RUNNING,
            ]);

            $this->record($timer, SlaEvent::STARTED, $at, [
                'policy' => $policy->slug,
                'target_minutes' => $goal->target_minutes,
            ]);

            $started->push($timer);
        }

        if ($started->isNotEmpty()) {
            $ticket->forceFill(['sla_policy_id' => $policy->getKey()])->save();
            $ticket->load('slaTimers');
            $this->syncTicket($ticket);
        }

        // A ticket filed into a waiting status is already stopped.
        if ($ticket->status?->pauses_sla) {
            $this->pause($ticket, $at);
        }

        return $started;
    }

    /**
     * Stop the clocks. Called when a ticket enters a status that pauses them.
     */
    public function pause(Ticket $ticket, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        foreach ($this->liveTimers($ticket) as $timer) {
            if ($timer->status === SlaTimer::STATUS_PAUSED) {
                continue;
            }

            $timer->forceFill([
                'status' => SlaTimer::STATUS_PAUSED,
                'paused_at' => $at,
            ])->save();

            $this->record($timer, SlaEvent::PAUSED, $at);
        }

        $ticket->load('slaTimers');
        $this->syncTicket($ticket);
    }

    /**
     * Restart the clocks, pushing the due date out by however long they were
     * stopped. Time spent waiting on someone else is not time the desk owes.
     */
    public function resume(Ticket $ticket, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        foreach ($this->liveTimers($ticket) as $timer) {
            if ($timer->status !== SlaTimer::STATUS_PAUSED || ! $timer->paused_at) {
                continue;
            }

            $calendar = $timer->calendar;
            $pausedFor = $calendar
                ? $calendar->workingMinutesBetween($timer->paused_at, $at)
                : (int) round($timer->paused_at->diffInMinutes($at));

            $timer->forceFill([
                'status' => SlaTimer::STATUS_RUNNING,
                'paused_at' => null,
                'paused_minutes' => $timer->paused_minutes + $pausedFor,
                'due_at' => $this->dueAt($calendar, $timer->due_at, $pausedFor),
            ])->save();

            $this->record($timer, SlaEvent::RESUMED, $at, ['paused_minutes' => $pausedFor]);
        }

        $ticket->load('slaTimers');
        $this->syncTicket($ticket);
    }

    /**
     * Close one clock, deciding met or breached by the moment it finished.
     */
    public function complete(Ticket $ticket, string $metric, ?CarbonImmutable $at = null): ?SlaTimer
    {
        $at ??= CarbonImmutable::now();
        $timer = $this->liveTimers($ticket)->firstWhere('metric', $metric);

        if (! $timer) {
            return null;
        }

        $met = $at->lessThanOrEqualTo($timer->due_at);

        $timer->forceFill([
            'status' => $met ? SlaTimer::STATUS_MET : SlaTimer::STATUS_BREACHED,
            'completed_at' => $at,
            'paused_at' => null,
            'breached_at' => $met ? $timer->breached_at : ($timer->breached_at ?? $at),
        ])->save();

        $this->record($timer, $met ? SlaEvent::MET : SlaEvent::BREACHED, $at, [
            'on_completion' => true,
        ]);

        $ticket->load('slaTimers');
        $this->syncTicket($ticket);

        return $timer;
    }

    /**
     * Mark a running clock breached without finishing it: the target has
     * passed and the work has not been done. The clock keeps running, because
     * "two hours late" and "two days late" are different conversations.
     *
     * @return int how many escalation thresholds the breach fired
     */
    public function breach(SlaTimer $timer, ?CarbonImmutable $at = null): int
    {
        if ($timer->breached_at !== null) {
            return 0;
        }

        $at ??= CarbonImmutable::now();

        $timer->forceFill(['breached_at' => $at])->save();
        $this->record($timer, SlaEvent::BREACHED, $at);

        $timer->loadMissing(['ticket', 'calendar', 'goal']);
        $ticket = $timer->ticket;

        if ($ticket) {
            $ticket->load('slaTimers');
            $this->syncTicket($ticket);
        }

        return $this->escalator->onBreach($timer);
    }

    /**
     * Stop measuring: the ticket no longer qualifies, or it was closed with a
     * clock that never applied. Cancelled clocks are kept, not deleted.
     */
    public function cancel(Ticket $ticket, ?string $metric = null, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        foreach ($this->liveTimers($ticket) as $timer) {
            if ($metric !== null && $timer->metric !== $metric) {
                continue;
            }

            $timer->forceFill([
                'status' => SlaTimer::STATUS_CANCELLED,
                'completed_at' => $at,
                'paused_at' => null,
            ])->save();

            $this->record($timer, SlaEvent::CANCELLED, $at);
        }

        $ticket->load('slaTimers');
        $this->syncTicket($ticket);
    }

    /**
     * The ticket changed in a way that changes what was promised — usually a
     * priority change. The clock keeps its start and its elapsed time; only
     * the target moves.
     */
    public function recalculate(Ticket $ticket, ?CarbonImmutable $at = null): void
    {
        $policy = $this->policyFor($ticket);

        if (! $policy) {
            return;
        }

        $at ??= CarbonImmutable::now();
        $calendar = $policy->calendar;
        $changed = false;

        foreach ($this->liveTimers($ticket) as $timer) {
            $goal = $policy->goalFor($timer->metric, $ticket);

            if (! $goal || $goal->target_minutes === $timer->target_minutes) {
                continue;
            }

            $previous = $timer->target_minutes;

            // Measured from the original start, so time already spent counts
            // against the new target rather than being forgiven.
            $timer->forceFill([
                'sla_policy_id' => $policy->getKey(),
                'sla_goal_id' => $goal->getKey(),
                'business_calendar_id' => $calendar?->getKey(),
                'target_minutes' => $goal->target_minutes,
                'due_at' => $this->dueAt(
                    $calendar,
                    $timer->started_at,
                    $goal->target_minutes + $timer->paused_minutes,
                ),
            ])->save();

            $this->record($timer, SlaEvent::RECALCULATED, $at, [
                'from_minutes' => $previous,
                'to_minutes' => $goal->target_minutes,
            ]);

            $changed = true;
        }

        if ($changed) {
            $ticket->load('slaTimers');
            $this->syncTicket($ticket);
        }
    }

    /**
     * A reopened ticket gets a fresh resolution clock: the desk has promised
     * again. The original is kept as it finished — it was met or breached on
     * its own terms, and rewriting it would lose the fact that the ticket
     * came back.
     */
    public function restartResolution(Ticket $ticket, ?CarbonImmutable $at = null): ?SlaTimer
    {
        $policy = $this->policyFor($ticket);
        $goal = $policy?->goalFor(SlaGoal::METRIC_RESOLUTION, $ticket);

        if (! $policy || ! $goal) {
            return null;
        }

        $at ??= CarbonImmutable::now();
        $calendar = $policy->calendar;

        // The unique index is on (ticket, metric), so the finished clock is
        // moved aside by the new one rather than living beside it. Its events
        // survive: they point at the timer row, which is updated in place.
        $timer = SlaTimer::query()->updateOrCreate(
            ['ticket_id' => $ticket->getKey(), 'metric' => SlaGoal::METRIC_RESOLUTION],
            [
                'sla_policy_id' => $policy->getKey(),
                'sla_goal_id' => $goal->getKey(),
                'business_calendar_id' => $calendar?->getKey(),
                'target_minutes' => $goal->target_minutes,
                'started_at' => $at,
                'due_at' => $this->dueAt($calendar, $at, $goal->target_minutes),
                'paused_at' => null,
                'paused_minutes' => 0,
                'completed_at' => null,
                'breached_at' => null,
                'status' => SlaTimer::STATUS_RUNNING,
            ],
        );

        $this->record($timer, SlaEvent::STARTED, $at, ['reopened' => true]);

        $ticket->load('slaTimers');
        $this->syncTicket($ticket);

        return $timer;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The first active policy whose conditions the ticket satisfies, then the
     * default. Ordered by position, so a narrow policy placed above a broad
     * one wins.
     */
    public function policyFor(Ticket $ticket): ?SlaPolicy
    {
        $policies = SlaPolicy::query()->active()->with(['goals', 'calendar'])->get();

        $matched = $policies->first(fn (SlaPolicy $policy): bool => $policy->matches($ticket));

        return $matched ?? $policies->firstWhere('is_default', true);
    }

    /**
     * Mirror the tightest live clock onto the ticket, so a list of a thousand
     * tickets can sort and colour by SLA without a join per row.
     */
    public function syncTicket(Ticket $ticket): void
    {
        $timers = $ticket->relationLoaded('slaTimers')
            ? $ticket->slaTimers
            : $ticket->slaTimers()->get();

        $live = $timers->filter(fn (SlaTimer $timer): bool => $timer->isLive());

        $ticket->forceFill([
            'sla_due_at' => $live->min('due_at'),
            'sla_breached' => $timers->contains(fn (SlaTimer $timer): bool => $timer->breached_at !== null),
        ])->save();
    }

    /**
     * The clocks that can still change.
     *
     * The calendar is eager-loaded because pausing and resuming both need it
     * to do their arithmetic, and a lazy load inside a loop over a hundred
     * timers is a hundred queries.
     *
     * @return Collection<int, SlaTimer>
     */
    private function liveTimers(Ticket $ticket): Collection
    {
        $timers = $ticket->relationLoaded('slaTimers')
            ? $ticket->slaTimers
            : $ticket->slaTimers()->get();

        $timers->loadMissing('calendar');

        return $timers->filter(fn (SlaTimer $timer): bool => $timer->isLive())->values();
    }

    private function dueAt(?BusinessCalendar $calendar, CarbonImmutable $from, int $minutes): CarbonImmutable
    {
        // No calendar means round-the-clock: plain wall time.
        return $calendar
            ? $calendar->addWorkingMinutes($from, $minutes)
            : $from->addMinutes($minutes);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(SlaTimer $timer, string $event, CarbonImmutable $at, array $context = []): SlaEvent
    {
        return SlaEvent::query()->create([
            'sla_timer_id' => $timer->getKey(),
            'ticket_id' => $timer->ticket_id,
            'event' => $event,
            'occurred_at' => $at,
            'context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * Every live timer that is past its due date and not yet marked breached.
     * The sweep reads this; the index on (status, due_at) is what makes it
     * cheap at ten thousand open tickets.
     *
     * @return Collection<int, SlaTimer>
     */
    public function overdueTimers(?CarbonImmutable $now = null): Collection
    {
        return SlaTimer::query()
            ->where('status', SlaTimer::STATUS_RUNNING)
            ->whereNull('breached_at')
            ->where('due_at', '<=', $now ?? CarbonImmutable::now())
            ->with(['ticket.assignee', 'ticket.team', 'goal', 'calendar'])
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Rebuild the denormalised SLA columns for a set of tickets. Used after a
     * bulk change, and by the sweep as a cheap self-heal.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public function syncMany(Collection $tickets): void
    {
        DB::transaction(function () use ($tickets): void {
            $tickets->loadMissing('slaTimers');

            foreach ($tickets as $ticket) {
                $this->syncTicket($ticket);
            }
        });
    }
}
