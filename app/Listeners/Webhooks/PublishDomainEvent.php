<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\Approvals\ApprovalCompleted;
use App\Events\Sla\SlaBreached;
use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketCommented;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketTransitioned;
use App\Events\Tickets\TicketUpdated;
use App\Services\Api\WebhookDispatcher;
use App\Services\Api\WebhookPayload;

/**
 * The bridge between the domain events every phase already dispatches and the
 * endpoints subscribed to them.
 *
 * Deliberately *not* a queued listener. It only writes a delivery row and
 * queues the job that does the network call, so pushing that work onto a
 * second queue would add a hop and a failure mode without removing any: the
 * slow part is already on its own queue with its own backoff.
 *
 * Subscribing to the existing events rather than calling the dispatcher from
 * the services means a webhook fires for a ticket created by an agent, by
 * e-mail, by an automation rule and by the API itself, with nothing to
 * remember at each call site.
 *
 * Laravel discovers the handlers by the event each `handle*` method
 * type-hints — do not also register it manually, or every endpoint gets each
 * event twice.
 */
class PublishDomainEvent
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handleTicketCreated(TicketCreated $event): void
    {
        $this->dispatcher->dispatch(
            'ticket.created',
            WebhookPayload::created($event->ticket, $event->actor),
        );
    }

    public function handleTicketUpdated(TicketUpdated $event): void
    {
        $this->dispatcher->dispatch(
            'ticket.updated',
            WebhookPayload::updated($event->ticket, $event->changes, $event->actor),
        );
    }

    public function handleTicketAssigned(TicketAssigned $event): void
    {
        $this->dispatcher->dispatch(
            'ticket.assigned',
            WebhookPayload::assigned(
                $event->ticket,
                $event->assignee,
                $event->previousAssignee,
                $event->actor,
            ),
        );
    }

    public function handleTicketTransitioned(TicketTransitioned $event): void
    {
        $this->dispatcher->dispatch(
            'ticket.transitioned',
            WebhookPayload::transitioned(
                $event->ticket,
                // Slugs, not display names: a receiver keying off "In
                // behandeling" breaks the moment somebody renames a status,
                // and the point of a slug is that it does not move.
                (string) $event->fromStatus->slug,
                (string) $event->toStatus->slug,
                $event->actor,
            ),
        );
    }

    public function handleTicketCommented(TicketCommented $event): void
    {
        $this->dispatcher->dispatch(
            'ticket.commented',
            WebhookPayload::commented($event->ticket, $event->comment, $event->actor),
        );
    }

    public function handleSlaBreached(SlaBreached $event): void
    {
        $this->dispatcher->dispatch(
            'sla.breached',
            WebhookPayload::slaBreached($event->ticket, $event->timer),
        );
    }

    public function handleApprovalCompleted(ApprovalCompleted $event): void
    {
        $this->dispatcher->dispatch(
            'approval.completed',
            WebhookPayload::approvalCompleted($event->ticket, $event->approval, $event->outcome),
        );
    }
}
