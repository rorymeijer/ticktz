<?php

declare(strict_types=1);

namespace App\Jobs\Sla;

use App\Models\SlaTimer;
use App\Services\Sla\SlaEngine;
use App\Services\Sla\SlaEscalator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The minute hand.
 *
 * Nothing else notices that a deadline has passed: a breach is the absence of
 * an event, so something has to go and look. Every minute this job asks two
 * questions — which running clocks are past their due date, and which are past
 * an escalation threshold — and acts on the answers.
 *
 * It is safe to run twice. A breach writes `breached_at` before it announces
 * anything, and an escalation claims its threshold in `sla_events` first, so a
 * second pass over the same timer finds the work already done and moves on.
 *
 * `ShouldBeUnique` keeps a slow sweep on ten thousand open tickets from
 * stacking up behind itself when the scheduler fires again.
 */
class SweepSlaTimersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /**
     * How many approaching clocks are examined per run. A desk with more than
     * this many live timers still gets every breach — those are found by due
     * date and cleared as they go — but works through the warning thresholds
     * over several minutes rather than loading everything into one job.
     */
    private const ESCALATION_BATCH = 500;

    public function __construct()
    {
        $this->onQueue(config('ticktz.queues.default'));
    }

    public function uniqueId(): string
    {
        return 'sweep-sla-timers';
    }

    /**
     * @return array{breached: int, escalated: int}
     */
    public function handle(SlaEngine $engine, SlaEscalator $escalator): array
    {
        $now = CarbonImmutable::now();
        $breached = 0;
        $escalated = 0;

        foreach ($engine->overdueTimers($now) as $timer) {
            try {
                // A breach usually trips the threshold at 100% on its way
                // past, and those escalations belong in the same total.
                $escalated += $engine->breach($timer, $now);
                $breached++;
            } catch (Throwable $exception) {
                // One malformed timer must not stop the sweep: every other
                // ticket on the desk is waiting behind it.
                report($exception);
            }
        }

        foreach ($this->approachingTimers($now) as $timer) {
            try {
                $escalated += $escalator->escalate($timer, $now);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($breached > 0 || $escalated > 0) {
            Log::info('SLA sweep: {breached} breached, {escalated} escalations fired', [
                'breached' => $breached,
                'escalated' => $escalated,
            ]);
        }

        return ['breached' => $breached, 'escalated' => $escalated];
    }

    /**
     * Running clocks with an escalation threshold that might have been passed.
     *
     * Only timers whose goal actually defines escalations are loaded — on a
     * desk that configured none, this sweep costs one query that returns
     * nothing.
     *
     * @return Collection<int, SlaTimer>
     */
    private function approachingTimers(CarbonImmutable $now): Collection
    {
        return SlaTimer::query()
            ->where('status', SlaTimer::STATUS_RUNNING)
            ->whereHas('goal', fn ($query) => $query
                ->whereNotNull('escalations')
                ->where('escalations', '!=', '[]'))
            ->with(['ticket.assignee', 'ticket.team', 'ticket.priority', 'ticket.watchers', 'goal', 'calendar'])
            ->orderBy('due_at')
            ->limit(self::ESCALATION_BATCH)
            ->get();
    }
}
