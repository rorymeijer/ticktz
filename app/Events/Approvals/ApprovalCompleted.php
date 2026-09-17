<?php

declare(strict_types=1);

namespace App\Events\Approvals;

use App\Models\ApprovalRequest;
use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The approval settled: granted, refused or withdrawn.
 *
 * This is the seam the automation engine hangs "when approved, move to In
 * progress" on. It is also the moment the transition gate opens, which is why
 * `tickets.approval_state` is written before this is dispatched rather than by
 * a listener — a rule that transitions the ticket must not race the cache it
 * is about to be checked against.
 */
class ApprovalCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ApprovalRequest $approval,
        public readonly Ticket $ticket,
        public readonly string $outcome,
    ) {}
}
