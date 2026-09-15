<?php

declare(strict_types=1);

namespace App\Events\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Domain events are the seam the notification pipeline (phase 4), the SLA
 * engine (phase 5) and the automation engine (phase 6) hook into. Controllers
 * and services never call those subsystems directly.
 */
class TicketCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?User $actor = null,
    ) {}
}
