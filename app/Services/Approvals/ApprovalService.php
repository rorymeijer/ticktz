<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Events\Approvals\ApprovalCompleted;
use App\Events\Approvals\ApprovalDecided;
use App\Events\Approvals\ApprovalRequested;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RichText\RichTextSanitizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Everything an approval can do.
 *
 * Nothing writes to `approval_requests` or `approval_decisions` outside this
 * class, which is what makes three things true everywhere rather than at each
 * call site:
 *
 * - **A decision is recorded once.** The write is a conditional update on the
 *   row's own `pending` state, so two clicks, a click racing an e-mail link,
 *   or a retried job all land one answer. The second one is told the approval
 *   has already been settled instead of quietly overwriting the first.
 * - **`tickets.approval_state` is written before anything is dispatched.** The
 *   transition gate reads that column, and a rule reacting to
 *   {@see ApprovalCompleted} may well transition the ticket — so the cache has
 *   to be true before the event goes out, not after a listener catches up.
 * - **Who was asked is a fact, not a query.** Approvers are resolved when a
 *   step opens and written into decision rows. A team gaining a member
 *   tomorrow does not change who was asked today.
 */
class ApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovalNotifier $notifier,
        private readonly RichTextSanitizer $richText,
    ) {}

    /**
     * Open an approval on a ticket.
     *
     * Returns null when the workflow has no steps, or when every step it has
     * resolves to nobody. A ticket held forever by an approval addressed to
     * nobody is worse than one that was never held, so the caller is told and
     * the ticket carries on.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(
        Ticket $ticket,
        ?ApprovalWorkflow $workflow,
        array $attributes = [],
        ?User $actor = null,
    ): ?ApprovalRequest {
        $steps = $workflow?->steps()->get() ?? new Collection;

        // An ad-hoc approval carries its approvers in the attributes rather
        // than in a workflow: an agent asking one named person to sign off on
        // one ticket should not have to create a workflow first.
        $adHoc = ! empty($attributes['approver_ids']);

        if ($steps->isEmpty() && ! $adHoc) {
            return null;
        }

        $approval = DB::transaction(function () use ($ticket, $workflow, $attributes, $actor, $steps, $adHoc) {
            $approval = ApprovalRequest::query()->create([
                'ticket_id' => $ticket->getKey(),
                'approval_workflow_id' => $workflow?->getKey(),
                'subject' => trim((string) ($attributes['subject'] ?? $ticket->subject)),
                'reason' => $attributes['reason'] ?? $workflow?->instructions,
                'status' => ApprovalRequest::PENDING,
                'current_position' => 0,
                'requested_by' => $actor?->getKey(),
            ]);

            if ($adHoc) {
                $this->openAdHocStep($approval, $ticket, $attributes);
            } else {
                // The first step with anybody in it, which is not necessarily
                // step zero: a step whose team turned out to be empty is
                // skipped rather than left blocking.
                $opened = $this->openStepsFrom($approval, $ticket, $steps, 0);

                if ($opened !== null && $opened !== 0) {
                    $approval->forceFill(['current_position' => $opened])->save();
                }
            }

            return $approval;
        });

        $approval->load('decisions.approver');

        // Nobody to ask. The run is recorded — an approval that quietly did
        // not happen is the kind of thing that is discovered in an audit —
        // but it is settled, not pending, so nothing is blocked by it.
        if ($approval->decisions->isEmpty()) {
            $this->settle($approval, $ticket, ApprovalRequest::CANCELLED, $actor, 'approvals.log.no_approvers');

            return null;
        }

        $this->refreshDueDate($approval);
        $this->syncTicket($ticket);

        $this->auditAs($actor)->log(
            $ticket,
            'approval.requested',
            __('approvals.log.requested', ['subject' => $approval->subject]),
            null,
            null,
            ['approval_request_id' => $approval->getKey()],
        );

        ApprovalRequested::dispatch($approval, $ticket);

        $this->notifier->stepOpened($approval, $ticket);

        return $approval;
    }

    /**
     * Record one person's answer.
     *
     * @throws RuntimeException when the decision has already been answered
     */
    public function decide(
        ApprovalDecision $decision,
        string $outcome,
        ?string $comment = null,
        ?User $actor = null,
        string $source = 'web',
    ): ApprovalRequest {
        if (! in_array($outcome, ApprovalDecision::OUTCOMES, true)) {
            throw new RuntimeException(__('approvals.errors.unknown_outcome'));
        }

        $approval = $decision->request()->with('ticket')->firstOrFail();
        $ticket = $approval->ticket;

        // Claimed rather than checked-then-written. Two approvers clicking at
        // the same instant, or one clicking while their e-mail link is being
        // followed, must produce one answer — and the loser must be told, not
        // silently ignored.
        $claimed = ApprovalDecision::query()
            ->whereKey($decision->getKey())
            ->where('decision', ApprovalDecision::PENDING)
            ->update([
                'decision' => $outcome,
                // Cleaned here, not by HasRichText: this is a query-builder
                // update rather than a model save — deliberately, so the claim
                // below is atomic — and Eloquent fires no model events for
                // one. The trait cannot cover a write that never touches the
                // model, so the write has to do it itself.
                //
                // Truncating first and sanitising after is the order that
                // matters: the sanitiser parses and re-serialises, so a cut
                // that lands mid-tag is repaired rather than stored as an
                // unclosed element that swallows the rest of the page.
                'comment' => $comment
                    ? ($this->richText->clean(mb_substr($comment, 0, 20_000)) ?: null)
                    : null,
                'source' => $source,
                'decided_at' => now(),
                'token_hash' => null,
                'token_expires_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            throw new RuntimeException(__('approvals.errors.already_decided'));
        }

        $decision->refresh()->loadMissing('approver');

        if (! $approval->isPending()) {
            // The run was cancelled between the page loading and the click.
            // The answer is kept — it is evidence — but it changes nothing.
            return $approval;
        }

        $this->auditAs($actor ?? $decision->approver)->log(
            $ticket,
            "approval.{$outcome}",
            __("approvals.log.{$outcome}", [
                'name' => $decision->approver?->name ?? '',
                'subject' => $approval->subject,
            ]),
            null,
            null,
            ['approval_request_id' => $approval->getKey(), 'source' => $source],
        );

        ApprovalDecided::dispatch($decision, $approval, $ticket);

        $this->advance($approval, $ticket, $actor);

        return $approval->refresh();
    }

    /**
     * Withdraw a running approval.
     *
     * Decisions already given are kept: they are what happened. The ones still
     * pending stay pending and unanswerable, which is why the step renderer
     * has a `cancelled` state rather than leaving them looking open.
     */
    public function cancel(ApprovalRequest $approval, ?User $actor = null, ?string $reason = null): ApprovalRequest
    {
        if (! $approval->isPending()) {
            return $approval;
        }

        $ticket = $approval->ticket()->firstOrFail();

        // Every unanswered link dies with the run.
        ApprovalDecision::query()
            ->where('approval_request_id', $approval->getKey())
            ->where('decision', ApprovalDecision::PENDING)
            ->update(['token_hash' => null, 'token_expires_at' => null]);

        $this->settle($approval, $ticket, ApprovalRequest::CANCELLED, $actor, 'approvals.log.cancelled', $reason);

        return $approval;
    }

    /**
     * Whether a ticket may move, given the approval it is carrying.
     *
     * Read from the cached column so a ticket list can ask it five hundred
     * times without five hundred queries.
     */
    public function blocksTransition(Ticket $ticket): bool
    {
        return $ticket->approval_state === ApprovalRequest::PENDING
            || $ticket->approval_state === ApprovalRequest::REJECTED;
    }

    /**
     * Recompute `tickets.approval_state` from the runs behind it.
     *
     * Written with a bare update: this is a cache of other rows, and stamping
     * `updated_at` on a ticket because somebody approved something would make
     * "recently updated" mean nothing.
     */
    public function syncTicket(Ticket $ticket): void
    {
        $state = ApprovalRequest::query()
            ->where('ticket_id', $ticket->getKey())
            ->whereIn('status', [ApprovalRequest::PENDING, ApprovalRequest::APPROVED, ApprovalRequest::REJECTED])
            ->latest('id')
            ->value('status');

        if ($ticket->approval_state === $state) {
            return;
        }

        Ticket::query()->whereKey($ticket->getKey())->toBase()->update(['approval_state' => $state]);

        $ticket->approval_state = $state;
        $ticket->syncOriginalAttribute('approval_state');
    }

    // -----------------------------------------------------------------
    // Steps
    // -----------------------------------------------------------------

    /**
     * Settle the open step if it can be settled, then move on or finish.
     */
    private function advance(ApprovalRequest $approval, Ticket $ticket, ?User $actor): void
    {
        $approval->load('decisions');

        $current = $approval->currentDecisions();

        // One refusal ends the whole approval. An approval a majority can
        // override is not an approval, and "rejected by one of four" is the
        // answer the desk needs to act on.
        if ($current->contains(fn (ApprovalDecision $decision) => $decision->decision === ApprovalDecision::REJECTED)) {
            $this->settle($approval, $ticket, ApprovalRequest::REJECTED, $actor, 'approvals.log.settled_rejected');

            return;
        }

        $mode = $current->first()?->mode ?? ApprovalStep::ANY;

        $settled = $mode === ApprovalStep::ANY
            ? $current->contains(fn (ApprovalDecision $decision) => $decision->decision === ApprovalDecision::APPROVED)
            : $current->every(fn (ApprovalDecision $decision) => $decision->decision === ApprovalDecision::APPROVED);

        if (! $settled) {
            return;
        }

        // On an `any` step the people who did not get there first are marked
        // skipped rather than left pending, so the history reads as what it
        // was: asked, overtaken, no longer needed.
        ApprovalDecision::query()
            ->where('approval_request_id', $approval->getKey())
            ->where('position', $approval->current_position)
            ->where('decision', ApprovalDecision::PENDING)
            ->update([
                'decision' => ApprovalDecision::SKIPPED,
                'token_hash' => null,
                'token_expires_at' => null,
                'updated_at' => now(),
            ]);

        $approval->loadMissing('workflow');

        $steps = $approval->workflow?->steps()->get() ?? new Collection;

        $opened = $this->openStepsFrom(
            $approval,
            $ticket,
            $steps,
            $approval->current_position + 1,
        );

        if ($opened === null) {
            $this->settle($approval, $ticket, ApprovalRequest::APPROVED, $actor, 'approvals.log.settled_approved');

            return;
        }

        $approval->forceFill(['current_position' => $opened])->save();
        $this->refreshDueDate($approval);

        $approval->load('decisions.approver');
        $this->notifier->stepOpened($approval, $ticket);
    }

    /**
     * Open the first step from `$from` onwards that actually has approvers.
     *
     * A step whose team turned out to be empty is skipped rather than being
     * left to block forever — and the skip is recorded, so the trail says the
     * step was reached and had nobody in it.
     *
     * @param  Collection<int, ApprovalStep>  $steps
     * @return int|null the position opened, or null when none remain
     */
    private function openStepsFrom(ApprovalRequest $approval, Ticket $ticket, Collection $steps, int $from): ?int
    {
        foreach ($steps as $index => $step) {
            if ($index < $from) {
                continue;
            }

            $approvers = $step->approversFor($ticket);

            if ($approvers->isEmpty()) {
                $this->auditAs(null)->log(
                    $ticket,
                    'approval.step_skipped',
                    __('approvals.log.step_skipped', ['step' => $step->label()]),
                    null,
                    null,
                    ['approval_request_id' => $approval->getKey(), 'position' => $index],
                );

                continue;
            }

            foreach ($approvers as $approver) {
                ApprovalDecision::query()->create([
                    'approval_request_id' => $approval->getKey(),
                    'approval_step_id' => $step->getKey(),
                    'position' => $index,
                    'step_name' => $step->label(),
                    'mode' => $step->mode,
                    'approver_id' => $approver->getKey(),
                    'decision' => ApprovalDecision::PENDING,
                ]);
            }

            return $index;
        }

        return null;
    }

    /**
     * An approval an agent raised by hand: one step, the people they named.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function openAdHocStep(ApprovalRequest $approval, Ticket $ticket, array $attributes): void
    {
        $mode = in_array($attributes['mode'] ?? null, ApprovalStep::MODES, true)
            ? $attributes['mode']
            : ApprovalStep::ANY;

        $approvers = User::query()
            ->whereIn('id', $attributes['approver_ids'])
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get()
            ->reject(fn (User $user) => $user->getKey() === $ticket->requester_id);

        foreach ($approvers as $approver) {
            ApprovalDecision::query()->create([
                'approval_request_id' => $approval->getKey(),
                'position' => 0,
                'step_name' => $attributes['step_name'] ?? null,
                'mode' => $mode,
                'approver_id' => $approver->getKey(),
                'decision' => ApprovalDecision::PENDING,
            ]);
        }
    }

    private function settle(
        ApprovalRequest $approval,
        Ticket $ticket,
        string $status,
        ?User $actor,
        string $logKey,
        ?string $reason = null,
    ): void {
        $approval->forceFill([
            'status' => $status,
            'completed_at' => now(),
            'reason' => $reason ?: $approval->reason,
        ])->save();

        // Before the event, deliberately: a rule listening for "approved" will
        // try to transition the ticket, and the gate it runs into reads this
        // column.
        $this->syncTicket($ticket);

        $this->auditAs($actor)->log(
            $ticket,
            "approval.{$status}",
            __($logKey, ['subject' => $approval->subject]),
            null,
            null,
            ['approval_request_id' => $approval->getKey()],
        );

        ApprovalCompleted::dispatch($approval, $ticket, $status);

        if ($status !== ApprovalRequest::CANCELLED) {
            $this->notifier->completed($approval, $ticket);
        }
    }

    /**
     * The deadline of the step that is open, if it has one.
     */
    private function refreshDueDate(ApprovalRequest $approval): void
    {
        $hours = $approval->decisions()
            ->where('position', $approval->current_position)
            ->with('step')
            ->get()
            ->map(fn (ApprovalDecision $decision) => $decision->step?->due_hours)
            ->filter()
            ->min();

        $approval->forceFill([
            'due_at' => $hours ? now()->addHours((int) $hours) : null,
        ])->save();
    }

    private function auditAs(?User $actor): AuditLogger
    {
        return $actor ? $this->audit->actingAs($actor) : $this->audit;
    }
}
