<?php

declare(strict_types=1);

use App\Jobs\Approvals\RemindApproversJob;
use App\Jobs\Approvals\SendApprovalMailJob;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use App\Models\Ticket;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Approvals\ApprovalService;
use App\Services\Mail\TicketMailer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
});

it('mails only the people the open step is waiting on', function (): void {
    Bus::fake();

    $first = makeAgent();
    $second = makeAgent();
    $workflow = ApprovalWorkflow::factory()->sequential([$first, $second])->create();

    app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    // Step two has not opened, so its approver hears nothing yet: mailing
    // everybody at once is how a chain becomes four people each assuming
    // somebody else has it.
    Bus::assertDispatchedTimes(SendApprovalMailJob::class, 1);
});

it('mails the next approver when the step advances', function (): void {
    $first = makeAgent();
    $second = makeAgent();
    $workflow = ApprovalWorkflow::factory()->sequential([$first, $second])->create();

    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    Bus::fake();

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED);

    Bus::assertDispatched(
        SendApprovalMailJob::class,
        fn (SendApprovalMailJob $job) => $job->templateKey === 'approval.requested',
    );
});

it('tells the requester when the approval is settled', function (): void {
    $approver = makeAgent();
    $requester = makeRequester();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();

    $ticket = Ticket::factory()->create(['requester_id' => $requester->getKey()]);
    $approval = app(ApprovalService::class)->open($ticket, $workflow);

    Bus::fake();

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED);

    Bus::assertDispatched(
        SendApprovalMailJob::class,
        fn (SendApprovalMailJob $job) => $job->templateKey === 'approval.approved'
            && $job->recipientId === $requester->getKey(),
    );
});

/**
 * The plaintext token exists for exactly as long as it takes to build one
 * e-mail. Minting it at the call site would put a working bearer credential
 * into a queue payload, where it sits in Redis in the clear and is written to
 * `failed_jobs` if delivery goes wrong.
 */
it('never puts a usable token in the queue payload', function (): void {
    Bus::fake();

    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    Bus::assertDispatched(SendApprovalMailJob::class, function (SendApprovalMailJob $job): bool {
        // The job carries ids and nothing else.
        return $job->decisionId !== null
            && json_encode(get_object_vars($job)) !== false
            && ! str_contains((string) json_encode(get_object_vars($job)), 'token');
    });

    expect($approval->decisions->first()->token_hash)->toBeNull();
});

it('puts a decision link in the mail when links are switched on', function (): void {
    Mail::fake();
    config(['ticktz.approvals.decide_by_email' => true]);

    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $decision = $approval->decisions->first();

    app(SendApprovalMailJob::class, [
        'approvalId' => $approval->getKey(),
        'templateKey' => 'approval.requested',
        'decisionId' => $decision->getKey(),
    ])->handle(app(TicketMailer::class));

    // The token is minted inside the job, so a hash now exists where there was
    // none before.
    expect($decision->refresh()->token_hash)->not->toBeNull()
        ->and($decision->notified_at)->not->toBeNull();
});

/**
 * An operator who would rather approvals were only answered by somebody signed
 * in turns the links off. Nothing else changes, and no token is ever minted.
 */
it('mints no token at all when e-mail links are switched off', function (): void {
    Mail::fake();
    config(['ticktz.approvals.decide_by_email' => false]);

    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $decision = $approval->decisions->first();

    app(SendApprovalMailJob::class, [
        'approvalId' => $approval->getKey(),
        'templateKey' => 'approval.requested',
        'decisionId' => $decision->getKey(),
    ])->handle(app(TicketMailer::class));

    expect($decision->refresh()->token_hash)->toBeNull()
        ->and($decision->notified_at)->not->toBeNull();
});

it('does not mail an approval that was answered between queueing and delivery', function (): void {
    Mail::fake();

    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $decision = $approval->decisions->first();
    app(ApprovalService::class)->decide($decision, ApprovalDecision::APPROVED);

    app(SendApprovalMailJob::class, [
        'approvalId' => $approval->getKey(),
        'templateKey' => 'approval.requested',
        'decisionId' => $decision->getKey(),
    ])->handle(app(TicketMailer::class));

    Mail::assertNothingSent();
});

// -----------------------------------------------------------------
// Reminders
// -----------------------------------------------------------------

it('nudges an approval nobody has answered', function (): void {
    config(['ticktz.approvals.reminder_hours' => 24]);

    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $approval->decisions->first()->forceFill(['notified_at' => now()->subDays(2)])->save();

    Bus::fake();
    app(RemindApproversJob::class)->handle(app(ApprovalNotifier::class));

    Bus::assertDispatched(
        SendApprovalMailJob::class,
        fn (SendApprovalMailJob $job) => $job->templateKey === 'approval.reminder',
    );
});

it('leaves an approval that was only just sent alone', function (): void {
    config(['ticktz.approvals.reminder_hours' => 24]);

    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $approval->decisions->first()->forceFill(['notified_at' => now()->subMinutes(5)])->save();

    Bus::fake();
    app(RemindApproversJob::class)->handle(app(ApprovalNotifier::class));

    Bus::assertNotDispatched(SendApprovalMailJob::class);
});

/**
 * A deadline reports; it never decides. Auto-approving would make every
 * approval a statement about how long somebody waited rather than about what
 * they agreed to.
 */
it('never decides an overdue approval on its own', function (): void {
    config(['ticktz.approvals.reminder_hours' => 1]);

    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $approval->forceFill(['due_at' => now()->subWeek()])->save();
    $approval->decisions->first()->forceFill(['notified_at' => now()->subWeek()])->save();

    Bus::fake();
    app(RemindApproversJob::class)->handle(app(ApprovalNotifier::class));

    expect($approval->refresh()->status)->toBe(ApprovalRequest::PENDING)
        ->and($approval->isOverdue())->toBeTrue();
});
