<?php

declare(strict_types=1);

namespace App\Jobs\Approvals;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Mail\TicketMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One approval notification, to one person.
 *
 * Queued for the usual reason — nothing a requester does should wait on an
 * SMTP server — and for one specific to this feature: the decision token is
 * **minted here**, inside the job, at the moment the mail is built. Minting it
 * at the call site would put a working bearer credential into a queue payload,
 * where it would sit in Redis in the clear and be written to the failed-jobs
 * table if delivery went wrong.
 */
class SendApprovalMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $approvalId,
        public readonly string $templateKey,
        public readonly ?int $decisionId = null,
        public readonly ?int $recipientId = null,
    ) {
        $this->onQueue((string) config('ticktz.queues.mail'));
    }

    public function handle(TicketMailer $mailer): void
    {
        $approval = ApprovalRequest::query()
            ->with(['ticket.requester', 'ticket.status', 'ticket.priority', 'ticket.queue', 'workflow'])
            ->find($this->approvalId);

        if ($approval?->ticket === null) {
            return;
        }

        $decision = $this->decisionId
            ? ApprovalDecision::query()->with('approver')->find($this->decisionId)
            : null;

        $recipient = $decision?->approver
            ?? ($this->recipientId ? User::query()->find($this->recipientId) : null);

        if ($recipient === null) {
            return;
        }

        // A decision answered between queueing and delivery needs no mail —
        // and must not be handed a fresh token.
        if ($decision !== null && ! $decision->isPending()) {
            return;
        }

        // On a real queue an unreachable mail server should fail the job so the
        // backoff retries it. On `sync` — a demo instance, a seeder, a
        // developer without SMTP — the same exception would take down whatever
        // raised the approval, and an approval that cannot be created because
        // the mail server is down is a worse failure than one nobody was
        // e-mailed about. The approval itself is already recorded and visible
        // in the inbox either way.
        try {
            $mailer->send(
                $this->templateKey,
                $approval->ticket,
                $recipient,
                null,
                null,
                $this->placeholders($approval, $decision, $recipient),
            );
        } catch (Throwable $exception) {
            if (config('queue.default') !== 'sync') {
                throw $exception;
            }

            report($exception);

            return;
        }

        $decision?->forceFill(['notified_at' => now()])->save();
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(ApprovalRequest $approval, ?ApprovalDecision $decision, User $recipient): array
    {
        $locale = $recipient->locale ?: (string) config('app.locale');

        $values = [
            'approval.subject' => $approval->subject,
            'approval.reason' => (string) $approval->reason,
            'approval.status' => __("approvals.status.{$approval->status}", [], $locale),
            'approval.workflow' => (string) $approval->workflow?->translatedName($locale),
            'approval.due_at' => $approval->due_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? '',
            'approval.url' => url('/agent/approvals'),
            'approval.decide_url' => '',
        ];

        if ($decision === null || ! config('ticktz.approvals.decide_by_email')) {
            // Links off: the mail says what is waiting and the approver signs
            // in. On a desk approving things that matter, that is the right
            // setting, and the template still renders — an unknown placeholder
            // becomes an empty string, not the literal token.
            return $values;
        }

        $values['approval.decide_url'] = url('/approvals/'.$decision->issueToken());

        return $values;
    }
}
