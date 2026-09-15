<?php

declare(strict_types=1);

namespace App\Jobs\Automation;

use App\Models\AutomationRule;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationGuard;
use Cron\CronExpression;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The other kind of trigger: nothing happened, and that is the point.
 *
 * "Close resolved tickets nobody has come back to in a week" cannot hang off
 * an event, because the event is the absence of one. So a scheduled rule walks
 * the open tickets and evaluates itself against each.
 *
 * Two limits keep that affordable. Only tickets that are still on somebody's
 * plate are considered — a scheduled rule has nothing useful to say about a
 * ticket closed last March — and each rule looks at a bounded batch per run.
 * A desk with more matching tickets than the batch works through them over
 * several runs rather than loading fifty thousand rows into one job.
 */
class RunScheduledRulesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    private const BATCH = 500;

    public function __construct()
    {
        $this->onQueue(config('ticktz.queues.low'));
    }

    public function uniqueId(): string
    {
        return 'automation-scheduled';
    }

    /**
     * @return array{rules: int, tickets: int, matched: int}
     */
    public function handle(AutomationEngine $engine, AutomationGuard $guard): array
    {
        $summary = ['rules' => 0, 'tickets' => 0, 'matched' => 0];

        $rules = AutomationRule::query()->for(AutomationRule::TRIGGER_SCHEDULED)->get();

        foreach ($rules as $rule) {
            if (! $this->isDue($rule)) {
                continue;
            }

            $summary['rules']++;

            $tickets = Ticket::query()
                ->whereHas('status', fn ($status) => $status->whereIn('category', TicketStatus::OPEN_CATEGORIES))
                ->with(['status', 'priority', 'labels', 'assignee', 'team', 'requester'])
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get();

            foreach ($tickets as $ticket) {
                $summary['tickets']++;

                // A fresh cascade per ticket: one scheduled rule matching two
                // hundred tickets is two hundred independent runs, not one
                // cascade two hundred deep.
                $guard->enter(0);

                try {
                    $execution = $engine->apply($rule, $ticket, AutomationRule::TRIGGER_SCHEDULED);

                    if ($execution?->status === 'matched') {
                        $summary['matched']++;
                    }
                } catch (Throwable $exception) {
                    // One bad ticket must not stop the sweep for the rest.
                    report($exception);
                } finally {
                    $guard->reset();
                }
            }
        }

        if ($summary['matched'] > 0) {
            Log::info('Scheduled automation: {matched} of {tickets} tickets matched across {rules} rule(s)', $summary);
        }

        return $summary;
    }

    /**
     * Is this rule's cron expression due in the minute we are in?
     *
     * The scheduler ticks every minute and this job asks each rule whether it
     * wants this tick, rather than registering a Laravel schedule entry per
     * rule — rules are data and change while the process is running.
     *
     * `now()` is in the application timezone, so "0 3 * * *" means three in
     * the morning where the desk is. That matches every other schedule in the
     * instance, and it is what an administrator writing it means.
     */
    private function isDue(AutomationRule $rule): bool
    {
        try {
            return (new CronExpression($rule->cronExpression()))->isDue(now()->toDateTimeString());
        } catch (Throwable) {
            // A cron expression that does not parse never runs, and says so
            // rather than running every minute.
            Log::warning('Automation rule {slug} has an invalid schedule', ['slug' => $rule->slug]);

            return false;
        }
    }
}
