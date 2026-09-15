<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Http\Controllers\Controller;
use App\Models\ApprovalDecision;
use App\Services\Approvals\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Answering an approval from an e-mail, without signing in.
 *
 * This is the only unauthenticated route in Ticktz that changes anything, so
 * it is worth being explicit about what holds it up.
 *
 * **The link is a GET that decides nothing.** It opens a page showing what is
 * being asked and two buttons; the buttons POST. A link that decided on GET
 * would be answered by the first mail scanner, corporate link-rewriter or
 * browser prefetcher that touched the message — approving things nobody read.
 * That failure is silent and indistinguishable from a real approval, which is
 * exactly what an approval must never be.
 *
 * **The token is the credential**, so it is 48 random characters, stored only
 * as a SHA-256 hash, cleared the moment the decision lands, and expired after
 * a configurable window. A forwarded mail cannot be replayed, and a leaked
 * database backup is not a pile of working approve links.
 *
 * **The route is throttled** on top of all that, because an unauthenticated
 * endpoint that looks things up by secret is a thing people will try to guess
 * at even when guessing is hopeless.
 *
 * An operator who would rather not have any of this sets
 * `TICKTZ_APPROVAL_EMAIL_LINKS=false`; the notification then carries no link
 * and the approver signs in.
 */
class ApprovalTokenController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function show(string $token): Response
    {
        $decision = ApprovalDecision::findByToken($token);

        // One page for "expired", "already answered" and "never existed". The
        // distinction is information about somebody else's approval, and the
        // person holding a dead link cannot act on any of the three anyway.
        if ($decision === null) {
            return Inertia::render('Approvals/Token', ['decision' => null, 'token' => null]);
        }

        $decision->load(['approver', 'request.ticket.requester', 'request.workflow']);

        $approval = $decision->request;

        return Inertia::render('Approvals/Token', [
            'token' => $token,
            'decision' => [
                'id' => $decision->id,
                'step_name' => $decision->step_name,
                'approver' => $decision->approver?->toSummaryArray(),
            ],
            'approval' => $approval->toSummaryArray() + [
                'ticket' => [
                    'key' => $approval->ticket?->key,
                    'subject' => $approval->ticket?->subject,
                    'description' => $approval->ticket?->description,
                    'requester' => $approval->ticket?->requester?->toSummaryArray(),
                ],
            ],
        ]);
    }

    public function decide(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(ApprovalDecision::OUTCOMES)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Re-resolved from the token rather than trusted from the form: the
        // page posts back the token it was opened with, and the token is what
        // says who this is.
        $decision = ApprovalDecision::findByToken($token);

        if ($decision === null) {
            return redirect()->route('approvals.token.show', ['token' => $token]);
        }

        try {
            $this->approvals->decide(
                $decision,
                $data['decision'],
                $data['comment'] ?? null,
                // No actor: nobody is signed in. The audit entry is attributed
                // to the approver the decision row names, and `source` records
                // that it arrived by mail rather than from a session.
                $decision->approver,
                'email',
            );
        } catch (RuntimeException) {
            // Answered in the meantime. The page below says so.
            return redirect()->route('approvals.token.show', ['token' => $token]);
        }

        return redirect()->route('approvals.token.done', ['outcome' => $data['decision']]);
    }

    public function done(string $outcome): Response
    {
        return Inertia::render('Approvals/Token', [
            'decision' => null,
            'token' => null,
            'outcome' => in_array($outcome, ApprovalDecision::OUTCOMES, true) ? $outcome : null,
        ]);
    }
}
