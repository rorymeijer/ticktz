<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Events\Sla\SlaBreached;
use App\Events\Sla\SlaEscalated;
use App\Models\Priority;
use App\Models\SlaEvent;
use App\Models\SlaGoal;
use App\Models\SlaTimer;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What happens as a deadline approaches, and when it passes.
 *
 * Two guarantees hold here, and both are enforced by the `sla_events` table
 * rather than by being careful:
 *
 * 1. **An escalation fires once.** The sweep runs every minute; a threshold
 *    that has already produced an `escalated` event for this timer is skipped.
 *    The check and the write happen in one transaction, so two workers racing
 *    on the same timer produce one escalation, not two.
 * 2. **A breach is announced once.** `breached_at` is set before anything is
 *    dispatched, so a job that dies after notifying does not notify again.
 *
 * The actions themselves are deliberately few. A breach also emits a domain
 * event, which is where the automation engine (phase 6) hangs anything more
 * elaborate — this class is not a rule engine and should not grow into one.
 */
class SlaEscalator
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Run any escalation thresholds this timer has passed.
     *
     * @return int how many thresholds fired
     */
    public function escalate(SlaTimer $timer, ?CarbonImmutable $now = null): int
    {
        $goal = $timer->goal;
        $ticket = $timer->ticket;

        if (! $goal || ! $ticket || ! $timer->isLive()) {
            return 0;
        }

        $now ??= CarbonImmutable::now();
        $percent = $timer->percentElapsed($now);
        $fired = 0;

        foreach ($goal->escalationSteps() as $step) {
            if ($percent < $step['at']) {
                continue;
            }

            if ($this->claim($timer, $step['at'], $now) === false) {
                continue;
            }

            foreach ($step['actions'] as $action) {
                $this->apply($ticket, $timer, $action);
            }

            SlaEscalated::dispatch($timer, $ticket, $step['at'], $step['actions']);
            $fired++;
        }

        return $fired;
    }

    /**
     * The target has passed. Announce it, then run any threshold the breach
     * itself has crossed — usually the one at 100%.
     *
     * @return int how many thresholds fired, so the sweep can report a number
     *             that includes the escalations it caused indirectly
     */
    public function onBreach(SlaTimer $timer): int
    {
        $ticket = $timer->ticket;

        if (! $ticket) {
            return 0;
        }

        $this->audit->as('system', 'sla')->log(
            $ticket,
            'sla.breached',
            "{$ticket->key} missed its {$timer->metric} target of {$timer->target_minutes} minutes",
            null,
            ['metric' => $timer->metric, 'due_at' => $timer->due_at->toIso8601String()],
        );

        SlaBreached::dispatch($timer, $ticket);

        return $this->escalate($timer);
    }

    /**
     * Claim a threshold: write the `escalated` event if no one else has.
     *
     * Returns false when the threshold has already fired, which is the normal
     * path on every sweep after the first.
     */
    private function claim(SlaTimer $timer, int $threshold, CarbonImmutable $at): bool
    {
        return DB::transaction(function () use ($timer, $threshold, $at): bool {
            $already = SlaEvent::query()
                ->where('sla_timer_id', $timer->getKey())
                ->where('event', SlaEvent::ESCALATED)
                ->lockForUpdate()
                ->get()
                ->contains(fn (SlaEvent $event): bool => (int) ($event->context['threshold'] ?? 0) === $threshold);

            if ($already) {
                return false;
            }

            SlaEvent::query()->create([
                'sla_timer_id' => $timer->getKey(),
                'ticket_id' => $timer->ticket_id,
                'event' => SlaEvent::ESCALATED,
                'occurred_at' => $at,
                'context' => ['threshold' => $threshold, 'metric' => $timer->metric],
            ]);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function apply(Ticket $ticket, SlaTimer $timer, array $action): void
    {
        match ($action['type']) {
            'notify' => $this->notify($ticket, $timer, (string) ($action['to'] ?? 'assignee')),
            'raise_priority' => $this->raisePriority($ticket, $timer),
            'assign_team' => $this->assignTeam($ticket, $timer, (int) ($action['team_id'] ?? 0)),
            default => null,
        };
    }

    /**
     * Notification is a watcher-level concern: the people who should know are
     * added as watchers, and the mail listener from phase 4 does the rest.
     * That keeps one path to an inbox rather than two.
     */
    private function notify(Ticket $ticket, SlaTimer $timer, string $target): void
    {
        foreach ($this->audienceFor($ticket, $target) as $user) {
            $ticket->watchers()->syncWithoutDetaching([
                $user->getKey() => ['is_automatic' => true],
            ]);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function audienceFor(Ticket $ticket, string $target): Collection
    {
        $audience = match ($target) {
            'assignee' => collect([$ticket->assignee])->filter(),
            'team_leads' => $ticket->team
                ? $ticket->team->members()->wherePivot('role', 'lead')->get()
                : collect(),
            'watchers' => $ticket->watchers()->get(),
            default => collect(),
        };

        return $audience->filter(fn (User $user): bool => $user->is_active)->values();
    }

    /**
     * One step up the priority ladder. `level` is 1 for the most urgent, so
     * the next priority up is the highest level below this one.
     */
    private function raisePriority(Ticket $ticket, SlaTimer $timer): void
    {
        $current = $ticket->priority;

        if (! $current) {
            return;
        }

        $next = Priority::query()
            ->where('level', '<', $current->level)
            ->orderByDesc('level')
            ->first();

        if (! $next) {
            return;
        }

        $ticket->forceFill(['priority_id' => $next->getKey()])->save();
        $ticket->setRelation('priority', $next);

        $this->audit->as('system', 'sla')->log(
            $ticket,
            'sla.priority_raised',
            "Raised {$ticket->key} from {$current->name} to {$next->name} on an SLA escalation",
            ['priority_id' => $current->getKey()],
            ['priority_id' => $next->getKey()],
        );
    }

    private function assignTeam(Ticket $ticket, SlaTimer $timer, int $teamId): void
    {
        $team = $teamId > 0 ? Team::query()->find($teamId) : null;

        if (! $team || (int) $ticket->team_id === $team->getKey()) {
            return;
        }

        $previous = $ticket->team_id;
        $ticket->forceFill(['team_id' => $team->getKey()])->save();

        $this->audit->as('system', 'sla')->log(
            $ticket,
            'sla.team_changed',
            "Moved {$ticket->key} to {$team->name} on an SLA escalation",
            ['team_id' => $previous],
            ['team_id' => $team->getKey()],
        );
    }

    /**
     * The metrics an escalation can target, for the admin UI.
     *
     * @return array<int, string>
     */
    public static function metrics(): array
    {
        return SlaGoal::METRICS;
    }
}
