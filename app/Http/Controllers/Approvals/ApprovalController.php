<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Http\Controllers\Controller;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The approval inbox, and the two buttons on it.
 *
 * Mounted at `/approvals` rather than under `/agent` or `/portal`, and that is
 * deliberate. The person who has to approve a hardware order is very often a
 * budget holder who is not an agent and has never seen the console — a
 * requester, as far as the permission system is concerned. Putting the inbox
 * behind `tickets.view` would have meant either giving every approver an agent
 * licence or building the screen twice. One URL, and the page picks the shell
 * that matches who is reading it.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filter = $request->string('filter')->toString() ?: 'mine';

        // "Everything" is a permission; "mine" never is. Somebody who was
        // asked can always read their own inbox.
        $canSeeAll = $user->can('approvals.view');

        return Inertia::render('Approvals/Index', [
            'approvals' => ApprovalRequest::query()
                ->with(['ticket:id,key,subject,requester_id', 'ticket.requester', 'workflow', 'requester', 'decisions.approver'])
                ->when(
                    $filter !== 'all' || ! $canSeeAll,
                    fn ($query) => $query->awaiting($user),
                    fn ($query) => $query->where('status', ApprovalRequest::PENDING),
                )
                ->latest('id')
                ->paginate(config('ticktz.per_page.tickets'))
                ->withQueryString()
                ->through(fn (ApprovalRequest $approval) => $approval->toDetailArray() + [
                    'ticket' => [
                        'key' => $approval->ticket?->key,
                        'subject' => $approval->ticket?->subject,
                        'requester' => $approval->ticket?->requester?->toSummaryArray(),
                    ],
                    // Which row, if any, is this reader's to answer. Computed
                    // here so the page never has to guess from the user id.
                    'my_decision_id' => $this->myDecision($approval, $user)?->getKey(),
                ]),
            'filters' => ['filter' => $canSeeAll ? $filter : 'mine'],
            'can' => ['view_all' => $canSeeAll],
        ]);
    }

    /**
     * Answer an approval while signed in.
     *
     * The policy takes the *decision*, not the request: the only person who
     * may answer is the person who was asked. A super-admin does not get to
     * approve on somebody's behalf, because an approval that an administrator
     * could have given is worth nothing as evidence.
     */
    public function decide(Request $request, ApprovalDecision $decision): RedirectResponse
    {
        $this->authorize('decide', $decision);

        $data = $request->validate([
            'decision' => ['required', Rule::in(ApprovalDecision::OUTCOMES)],
            'comment' => ['nullable', 'string', 'max:10000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $this->approvals->decide($decision, $data['decision'], $data['comment'] ?? null, $user, 'web');
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __("approvals.flash.{$data['decision']}"));
    }

    public function cancel(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->authorize('cancel', $approval);

        /** @var User $user */
        $user = $request->user();

        $this->approvals->cancel($approval, $user);

        return back()->with('success', __('approvals.flash.cancelled'));
    }

    private function myDecision(ApprovalRequest $approval, User $user): ?ApprovalDecision
    {
        return $approval->decisions
            ->where('approver_id', $user->getKey())
            ->where('position', $approval->current_position)
            ->firstWhere('decision', ApprovalDecision::PENDING);
    }
}
