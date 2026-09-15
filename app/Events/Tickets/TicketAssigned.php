<?php

declare(strict_types=1);

namespace App\Events\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketAssigned
{
    use Dispatchable, SerializesModels;

    /**
     * @param  bool  $onCreation  True when the ticket was filed with this
     *                            assignee already on it, rather than handed
     *                            over later. Listeners that would otherwise
     *                            duplicate the "new ticket" notification check
     *                            this; the event still fires either way, so
     *                            SLA and automation see every assignment.
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?User $assignee,
        public readonly ?User $previousAssignee = null,
        public readonly ?User $actor = null,
        public readonly bool $onCreation = false,
    ) {}
}
