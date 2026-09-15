<?php

declare(strict_types=1);

namespace App\Jobs\Approvals;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nudges the approvals nobody has answered.
 *
 * An approval nobody answers is the one way this feature fails in practice.
 * Not dramatically — the ticket simply stops, the requester assumes the desk
 * is working on it, and a fortnight later somebody asks why nothing happened.
 * A reminder costs one e-mail and prevents exactly that.
 *
 * What it deliberately does **not** do is decide anything. Auto-approving on a
 * deadline would make every approval in the system a statement about how long
 * somebody waited rather than about what they agreed to, and auto-rejecting
 * would refuse things nobody refused. Overdue approvals are reported, chased
 * and left open.
 *
 * The cadence is read from `notified_at` on each decision row, so a reminder
 * that failed to send is retried on the next sweep rather than skipped
 * forever.
 */
class RemindApproversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue((string) config('ticktz.queues.low'));
    }

    public function handle(ApprovalNotifier $notifier): void
    {
        $hours = (int) config('ticktz.approvals.reminder_hours');

        if ($hours <= 0) {
            return;
        }

        $cutoff = now()->subHours($hours);
        $reminded = 0;

        ApprovalRequest::query()
            ->where('status', ApprovalRequest::PENDING)
            // Something on the open step has been quiet for long enough. The
            // `whereColumn` is what keeps a step that has not opened yet out
            // of this: nobody has been asked, so nobody is late.
            ->whereHas('decisions', fn ($decisions) => $decisions
                ->where('decision', ApprovalDecision::PENDING)
                ->whereColumn('approval_decisions.position', 'approval_requests.current_position')
                ->where(fn ($quiet) => $quiet
                    ->whereNull('notified_at')
                    ->orWhere('notified_at', '<=', $cutoff)))
            ->with('decisions')
            ->chunkById(100, function ($approvals) use ($notifier, &$reminded): void {
                foreach ($approvals as $approval) {
                    $reminded += $notifier->remind($approval);
                }
            });

        if ($reminded > 0) {
            Log::info('Approval reminders sent.', ['count' => $reminded]);
        }
    }
}
