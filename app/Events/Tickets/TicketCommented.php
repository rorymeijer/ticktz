<?php

declare(strict_types=1);

namespace App\Events\Tickets;

use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketCommented
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly Comment $comment,
        public readonly ?User $actor = null,
    ) {}
}
