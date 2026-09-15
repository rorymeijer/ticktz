<?php

declare(strict_types=1);

namespace App\Events\Tickets;

use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketStatus $fromStatus,
        public readonly TicketStatus $toStatus,
        public readonly ?User $actor = null,
    ) {}
}
