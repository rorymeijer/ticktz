<?php

declare(strict_types=1);

namespace App\Listeners\Sla;

use App\Events\Tickets\TicketCommented;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketTransitioned;
use App\Events\Tickets\TicketUpdated;
use App\Models\SlaGoal;
use App\Models\TicketStatus;
use App\Services\Sla\SlaEngine;
use Carbon\CarbonImmutable;

/**
 * Keeps the clocks in step with what happens to a ticket.
 *
 * Not queued, unlike the mail listener. An SLA clock is part of the state of
 * the ticket — a ticket list that shows a countdown must not show the wrong
 * one for however long a worker takes to catch up, and an agent who moves a
 * ticket to "waiting for requester" expects the clock to stop as they watch.
 * The work is a handful of writes with no network call in it.
 *
 * Laravel discovers the handlers by the event each `handle*` method type-hints
 * — do not also register it manually, or every clock is touched twice.
 */
class MaintainSlaTimers
{
    public function __construct(private readonly SlaEngine $engine) {}

    public function handleTicketCreated(TicketCreated $event): void
    {
        $event->ticket->loadMissing(['slaTimers', 'status']);

        $this->engine->start($event->ticket, CarbonImmutable::now());
    }

    /**
     * A change to what the ticket *is* can change what was promised about it.
     * Priority is the usual one; queue, team and request type can all move a
     * ticket onto a different policy.
     */
    public function handleTicketUpdated(TicketUpdated $event): void
    {
        $relevant = ['priority_id', 'queue_id', 'team_id', 'request_type_id', 'organization_id'];

        if (array_intersect($relevant, array_keys($event->changes)) === []) {
            return;
        }

        $event->ticket->loadMissing('slaTimers');

        $this->engine->recalculate($event->ticket, CarbonImmutable::now());
    }

    public function handleTicketTransitioned(TicketTransitioned $event): void
    {
        $ticket = $event->ticket;
        $ticket->loadMissing('slaTimers');

        $now = CarbonImmutable::now();
        $from = $event->fromStatus;
        $to = $event->toStatus;

        // Resolved or closed: the resolution clock has its answer. A first
        // response clock still running at that point was never answered
        // separately, so resolving is the response.
        if (in_array($to->category, [TicketStatus::CATEGORY_RESOLVED, TicketStatus::CATEGORY_CLOSED], true)) {
            $this->engine->complete($ticket, SlaGoal::METRIC_FIRST_RESPONSE, $now);
            $this->engine->complete($ticket, SlaGoal::METRIC_RESOLUTION, $now);

            return;
        }

        // Coming back from resolved is a new promise to fix it.
        if ($from->isResolved()) {
            $this->engine->restartResolution($ticket, $now);
        }

        if ($to->pauses_sla && ! $from->pauses_sla) {
            $this->engine->pause($ticket, $now);

            return;
        }

        if ($from->pauses_sla && ! $to->pauses_sla) {
            $this->engine->resume($ticket, $now);
        }
    }

    /**
     * The first public reply from anyone but the requester is the first
     * response the SLA measures. An internal note is not an answer, and the
     * requester writing again is not a response to themselves.
     */
    public function handleTicketCommented(TicketCommented $event): void
    {
        $comment = $event->comment;
        $ticket = $event->ticket;

        if ($comment->is_internal) {
            return;
        }

        if ($comment->user_id !== null && (int) $comment->user_id === (int) $ticket->requester_id) {
            return;
        }

        $ticket->loadMissing('slaTimers');

        $this->engine->complete($ticket, SlaGoal::METRIC_FIRST_RESPONSE, CarbonImmutable::now());
    }
}
