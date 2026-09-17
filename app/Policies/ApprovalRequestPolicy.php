<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * Who may see and answer an approval.
 *
 * Answering is not here: it belongs to the decision row rather than to the
 * run, and the rule it enforces is not a permission at all. See
 * {@see ApprovalDecisionPolicy}.
 */
class ApprovalRequestPolicy
{
    /**
     * Seeing an approval follows seeing its ticket. Somebody who was asked to
     * approve can always see it, even without `approvals.view` — they were
     * asked, so they are entitled to read what they are answering.
     */
    public function view(User $user, ApprovalRequest $approval): bool
    {
        if ($user->can('approvals.view')) {
            return true;
        }

        return $approval->decisions()->where('approver_id', $user->getKey())->exists();
    }

    /**
     * Raise an approval by hand on a ticket.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('approvals.manage')
            || $user->hasPermission('approvals.decide');
    }

    /**
     * Withdraw a running approval. Whoever raised it, or somebody who
     * administers approvals — not the approvers, because "I withdrew the
     * question rather than answer it" is not an answer.
     */
    public function cancel(User $user, ApprovalRequest $approval): bool
    {
        return $approval->isPending()
            && ($user->hasPermission('approvals.manage')
                || (int) $approval->requested_by === (int) $user->getKey());
    }
}
