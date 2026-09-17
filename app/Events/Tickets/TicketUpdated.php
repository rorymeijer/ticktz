<?php

declare(strict_types=1);

namespace App\Events\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $changes  attribute => new value
     * @param  array<string, mixed>  $original  attribute => previous value
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly array $changes = [],
        public readonly array $original = [],
        public readonly ?User $actor = null,
    ) {}
}
