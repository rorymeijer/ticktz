<?php

declare(strict_types=1);

namespace App\Listeners\Approvals;

use App\Events\Tickets\TicketCreated;
use App\Services\Approvals\ApprovalService;

/**
 * A request type that needs signing off opens its approval as the ticket is
 * filed.
 *
 * Not queued, and that is the whole point. The approval gate is a column on
 * the ticket, and the transition that gate blocks can be attempted
 * immediately — by an automation rule reacting to the same `TicketCreated`,
 * or by an agent who was already looking at the queue. A ticket that is
 * briefly un-gated because a worker has not caught up is a ticket that can be
 * walked straight past the approval, which is the one thing this feature
 * exists to prevent.
 *
 * The mail it triggers *is* queued — see {@see SendApprovalMailJob} — so the
 * inline work here is a handful of inserts with no network call in it.
 *
 * Laravel discovers the handler by the event the `handle*` method type-hints.
 */
class OpenRequestTypeApproval
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function handleTicketCreated(TicketCreated $event): void
    {
        $ticket = $event->ticket;

        if ($ticket->request_type_id === null) {
            return;
        }

        $ticket->loadMissing('requestType.approvalWorkflow');

        $workflow = $ticket->requestType?->approvalWorkflow;

        if ($workflow === null || ! $workflow->is_active) {
            return;
        }

        $this->approvals->open(
            $ticket,
            $workflow,
            [
                'subject' => $ticket->subject,
                'reason' => $workflow->instructions,
            ],
            // Attributed to the requester rather than to whoever happened to
            // press the button: the approval is for their request.
            $ticket->requester,
        );
    }
}
