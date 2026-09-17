<?php

declare(strict_types=1);

namespace App\Events\Sla;

use App\Models\SlaTimer;
use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A target passed with the work undone.
 *
 * This is the seam the automation engine (phase 6) hangs SLA-triggered rules
 * on, which is why the SLA escalator keeps its own actions to a handful: the
 * general case belongs to a rule, not to this feature.
 */
class SlaBreached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SlaTimer $timer,
        public readonly Ticket $ticket,
    ) {}
}
