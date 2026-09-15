<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Raising an approval from a ticket.
 *
 * Two shapes, because a desk needs both: run a configured workflow, or ask one
 * named person right now. An agent who has to create an approval workflow
 * before they can ask a manager a yes/no question will instead ask them in a
 * chat window, and the answer will not be in the ticket.
 */
class TicketApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function store(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);
        $this->authorize('create', ApprovalRequest::class);

        $data = $request->validate([
            'approval_workflow_id' => ['nullable', 'integer', Rule::exists('approval_workflows', 'id')],
            'approver_ids' => ['array', 'max:20'],
            'approver_ids.*' => ['integer', Rule::exists('users', 'id')],
            'mode' => ['nullable', Rule::in(ApprovalStep::MODES)],
            'subject' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($data['approval_workflow_id']) && empty($data['approver_ids'])) {
            return back()->with('error', __('approvals.errors.nobody_named'));
        }

        /** @var User $user */
        $user = $request->user();

        $workflow = empty($data['approval_workflow_id'])
            ? null
            : ApprovalWorkflow::query()->find($data['approval_workflow_id']);

        $approval = $this->approvals->open($ticket, $workflow, [
            'subject' => $data['subject'] ?? $ticket->subject,
            'reason' => $data['reason'] ?? null,
            'approver_ids' => $data['approver_ids'] ?? [],
            'mode' => $data['mode'] ?? ApprovalStep::ANY,
        ], $user);

        // `open()` answers null when the workflow resolved to nobody. Saying so
        // is the whole point: a ticket silently held by an approval addressed
        // to no one is the failure this feature must not have.
        return $approval === null
            ? back()->with('error', __('approvals.errors.no_approvers'))
            : back()->with('success', __('approvals.flash.requested'));
    }
}
