<?php

declare(strict_types=1);

namespace App\Jobs\Automation;

use App\Models\Ticket;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Evaluates the rules for one trigger against one ticket.
 *
 * Queued, as the brief asks. A rule can call a webhook on somebody else's
 * server, and nothing a customer does in the portal should wait on that.
 *
 * The cascade state — how deep we are, and which rules have already run on
 * which tickets — is carried *in the job*, not in a static. A queued job runs
 * in a fresh process, so anything held in memory would reset and every
 * cascade would start from zero: the loop guard would never fire, which is
 * exactly when it is needed.
 */
class RunAutomationRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $context  the trigger payload
     * @param  array<int, string>  $seen  "rule:ticket" pairs already run in this cascade
     */
    public function __construct(
        public readonly string $trigger,
        public readonly int $ticketId,
        public readonly array $context = [],
        public readonly int $depth = 0,
        public readonly array $seen = [],
    ) {
        $this->onQueue(config('ticktz.queues.default'));
    }

    public function handle(AutomationEngine $engine, AutomationGuard $guard): void
    {
        // The conditions and the actions both read these. Loading them here
        // means a rule costs one query rather than one per relation it touches.
        $ticket = Ticket::query()
            ->with(['status', 'priority', 'labels', 'assignee', 'team', 'requester'])
            ->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        $guard->enter($this->depth, $this->seen);

        try {
            $engine->run($this->trigger, $ticket, $this->context);
        } finally {
            // The worker handles many jobs in one process, so leaving state
            // behind would let one ticket's cascade suppress the next one's.
            $guard->reset();
        }
    }
}
