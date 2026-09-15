<?php

declare(strict_types=1);

namespace App\Listeners\Automation;

use App\Events\Sla\SlaBreached;
use App\Events\Sla\SlaEscalated;
use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketCommented;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketTransitioned;
use App\Events\Tickets\TicketUpdated;
use App\Jobs\Automation\RunAutomationRulesJob;
use App\Models\AutomationRule;
use App\Models\Ticket;
use App\Services\Automation\AutomationGuard;
use Illuminate\Support\Facades\Schema;

/**
 * Turns domain events into automation runs.
 *
 * Not queued itself: it only dispatches a job, and doing that inline keeps the
 * cascade state intact. The guard knows how deep the current run is, and that
 * has to be read *here*, in the process where a rule's own change was made,
 * to be handed to the job it causes. Queue this listener and the reading
 * happens in a fresh worker where the depth is always zero — and the loop
 * guard never fires.
 *
 * Laravel discovers the handlers by the event each `handle*` method type-hints
 * — do not also register it manually, or every rule runs twice.
 */
class QueueAutomationRules
{
    public function __construct(private readonly AutomationGuard $guard) {}

    public function handleTicketCreated(TicketCreated $event): void
    {
        $this->queue(AutomationRule::TRIGGER_CREATED, $event->ticket);
    }

    public function handleTicketUpdated(TicketUpdated $event): void
    {
        $this->queue(AutomationRule::TRIGGER_UPDATED, $event->ticket, [
            // Which fields this change touched, so a rule can say "when the
            // priority changes" rather than "whenever anything changes".
            'changed_fields' => array_keys($event->changes),
            'changes' => $event->changes,
        ]);
    }

    public function handleTicketCommented(TicketCommented $event): void
    {
        $this->queue(AutomationRule::TRIGGER_COMMENTED, $event->ticket, [
            'comment' => [
                'id' => $event->comment->id,
                'body' => $event->comment->body,
                'is_internal' => $event->comment->is_internal,
                'source' => $event->comment->source,
                'user_id' => $event->comment->user_id,
            ],
        ]);
    }

    public function handleTicketTransitioned(TicketTransitioned $event): void
    {
        $this->queue(AutomationRule::TRIGGER_TRANSITIONED, $event->ticket, [
            'from_status_id' => $event->fromStatus->getKey(),
            'to_status_id' => $event->toStatus->getKey(),
            'to_status_category' => $event->toStatus->category,
        ]);
    }

    public function handleTicketAssigned(TicketAssigned $event): void
    {
        $this->queue(AutomationRule::TRIGGER_ASSIGNED, $event->ticket, [
            'assignee_id' => $event->assignee?->getKey(),
            'previous_assignee_id' => $event->previousAssignee?->getKey(),
            'on_creation' => $event->onCreation,
        ]);
    }

    public function handleSlaBreached(SlaBreached $event): void
    {
        $this->queue(AutomationRule::TRIGGER_SLA_BREACHED, $event->ticket, [
            'metric' => $event->timer->metric,
            'target_minutes' => $event->timer->target_minutes,
        ]);
    }

    public function handleSlaEscalated(SlaEscalated $event): void
    {
        $this->queue(AutomationRule::TRIGGER_SLA_ESCALATED, $event->ticket, [
            'metric' => $event->timer->metric,
            'threshold' => $event->threshold,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function queue(string $trigger, Ticket $ticket, array $context = []): void
    {
        // The schedule and the seeders both run before this table exists.
        if (! Schema::hasTable('automation_rules')) {
            return;
        }

        // Nothing to do, and nothing to log: dispatching a job to discover
        // there are no rules would cost a job per ticket event on an instance
        // that does not use automation at all.
        if (! AutomationRule::query()->for($trigger)->exists()) {
            return;
        }

        RunAutomationRulesJob::dispatch(
            $trigger,
            (int) $ticket->getKey(),
            $context,
            // One deeper when a rule's own action caused this event, so a
            // cascade is bounded however the rules are wired together.
            $this->guard->isRunning() ? $this->guard->depth() + 1 : 0,
            $this->guard->isRunning() ? $this->guard->seen() : [],
        );
    }
}
