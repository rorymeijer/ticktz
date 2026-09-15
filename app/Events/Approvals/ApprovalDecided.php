<?php

declare(strict_types=1);

namespace App\Events\Approvals;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One person answered.
 *
 * Not the same thing as the approval finishing: an `all` step with four
 * approvers dispatches this four times and {@see ApprovalCompleted} once.
 */
class ApprovalDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ApprovalDecision $decision,
        public readonly ApprovalRequest $approval,
        public readonly Ticket $ticket,
    ) {}
}
