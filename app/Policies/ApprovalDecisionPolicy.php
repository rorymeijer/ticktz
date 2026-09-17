<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalDecision;
use App\Models\User;

/**
 * Who may answer one approval decision.
 *
 * Its own policy, on its own model, because the rule it enforces is unlike
 * every other rule in Ticktz: it is not a permission. `approvals.decide` says
 * somebody may take part in approvals at all. It never lets them answer in
 * another approver's name, and neither does anything else — including being a
 * super-admin, which {@see AuthServiceProvider} deliberately stops short of
 * here.
 *
 * The reason is what an approval is *for*. "The budget holder approved this"
 * has to mean the budget holder. An approval an administrator could have given
 * on their behalf is not evidence of anything, and the audit trail that
 * records it would be a record of a permission rather than of a decision.
 *
 * Someone genuinely needs to unstick an approval the named approver cannot
 * answer — they have left, or were never the right person. The way to do that
 * is to cancel the run and raise a new one, which is recorded as exactly that.
 */
class ApprovalDecisionPolicy
{
    public function decide(User $user, ApprovalDecision $decision): bool
    {
        return $decision->isPending()
            && (int) $decision->approver_id === (int) $user->getKey();
    }
}
