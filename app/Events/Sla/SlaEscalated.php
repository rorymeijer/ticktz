<?php

declare(strict_types=1);

namespace App\Events\Sla;

use App\Models\SlaTimer;
use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An escalation threshold fired — at 50% of the target, at the breach itself,
 * or wherever else the goal placed one.
 */
class SlaEscalated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int  $threshold  Percentage of the target that was reached.
     * @param  array<int, array<string, mixed>>  $actions  What the escalation did.
     */
    public function __construct(
        public readonly SlaTimer $timer,
        public readonly Ticket $ticket,
        public readonly int $threshold,
        public readonly array $actions = [],
    ) {}
}
