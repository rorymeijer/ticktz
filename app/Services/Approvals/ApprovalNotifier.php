<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Jobs\Approvals\SendApprovalMailJob;
use App\Models\ApprovalRequest;
use App\Models\Ticket;

/**
 * Who hears about an approval, and when.
 *
 * Deliberately small and deliberately separate from {@see ApprovalService}:
 * the service owns what is true, this owns who is told. Every send goes out as
 * a queued job, so an unreachable mail server delays nobody's approval and a
 * failed delivery is retried rather than lost.
 */
class ApprovalNotifier
{
    /**
     * Tell the people a newly opened step is waiting on.
     *
     * Only the open step: being named in step three is not news while step one
     * is still running, and mailing everybody at once is how an approval chain
     * turns into four people each assuming somebody else has it.
     */
    public function stepOpened(ApprovalRequest $approval, Ticket $ticket): void
    {
        foreach ($approval->currentDecisions() as $decision) {
            if (! $decision->isPending()) {
                continue;
            }

            SendApprovalMailJob::dispatch($approval->getKey(), 'approval.requested', $decision->getKey());
        }
    }

    /**
     * Nudge the people who have not answered yet.
     */
    public function remind(ApprovalRequest $approval): int
    {
        $sent = 0;

        foreach ($approval->currentDecisions() as $decision) {
            if (! $decision->isPending()) {
                continue;
            }

            SendApprovalMailJob::dispatch($approval->getKey(), 'approval.reminder', $decision->getKey());
            $sent++;
        }

        return $sent;
    }

    /**
     * Tell the people who were waiting on the answer.
     *
     * The requester, because it is their request; whoever raised the approval,
     * because they asked; and the assignee, because the ticket is now theirs
     * to move. Not the approvers — they know what they decided.
     */
    public function completed(ApprovalRequest $approval, Ticket $ticket): void
    {
        $ticket->loadMissing(['requester', 'assignee']);
        $approval->loadMissing('requester');

        $recipients = collect([$ticket->requester, $ticket->assignee, $approval->requester])
            ->filter()
            ->unique('id');

        $template = $approval->status === ApprovalRequest::APPROVED
            ? 'approval.approved'
            : 'approval.rejected';

        foreach ($recipients as $recipient) {
            SendApprovalMailJob::dispatch($approval->getKey(), $template, null, $recipient->getKey());
        }
    }
}
