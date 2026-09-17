<?php

declare(strict_types=1);

namespace App\Events\Approvals;

use App\Models\ApprovalRequest;
use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An approval has been opened and somebody has been asked.
 *
 * Dispatched once per run, not once per step — a sequential approval reaching
 * step two is progress, not a new approval, and a rule that fired again there
 * would be wrong.
 */
class ApprovalRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ApprovalRequest $approval,
        public readonly Ticket $ticket,
    ) {}
}
