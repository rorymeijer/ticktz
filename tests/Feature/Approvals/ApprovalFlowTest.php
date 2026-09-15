<?php

declare(strict_types=1);

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Approvals\ApprovalService;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * Open an approval on a fresh ticket and hand back both.
 *
 * @return array{0: Ticket, 1: ApprovalRequest}
 */
function openApproval(ApprovalWorkflow $workflow, ?User $requester = null): array
{
    $ticket = Ticket::factory()->create($requester ? ['requester_id' => $requester->getKey()] : []);
    $approval = app(ApprovalService::class)->open($ticket, $workflow);

    return [$ticket->refresh(), $approval];
}

// -----------------------------------------------------------------
// The three shapes the brief asks for
// -----------------------------------------------------------------

it('settles a single-approver step on one yes', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();

    [$ticket, $approval] = openApproval($workflow);

    expect($approval->decisions)->toHaveCount(1)
        ->and($ticket->approval_state)->toBe(ApprovalRequest::PENDING);

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED);

    expect($approval->refresh()->status)->toBe(ApprovalRequest::APPROVED)
        ->and($ticket->refresh()->approval_state)->toBe(ApprovalRequest::APPROVED);
});

it('waits for everybody on a parallel all step', function (): void {
    $first = makeAgent();
    $second = makeAgent();
    $workflow = ApprovalWorkflow::factory()->parallel([$first, $second])->create();

    [$ticket, $approval] = openApproval($workflow);

    expect($approval->decisions)->toHaveCount(2);

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED);

    // One yes out of two settles nothing.
    expect($approval->refresh()->status)->toBe(ApprovalRequest::PENDING)
        ->and($ticket->refresh()->approval_state)->toBe(ApprovalRequest::PENDING);

    app(ApprovalService::class)->decide($approval->decisions->last(), ApprovalDecision::APPROVED);

    expect($approval->refresh()->status)->toBe(ApprovalRequest::APPROVED);
});

it('settles a parallel any step on the first yes and skips the rest', function (): void {
    $first = makeAgent();
    $second = makeAgent();
    $workflow = ApprovalWorkflow::factory()
        ->parallel([$first, $second], ApprovalStep::ANY)
        ->create();

    [, $approval] = openApproval($workflow);

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED);

    $approval->refresh()->load('decisions');

    expect($approval->status)->toBe(ApprovalRequest::APPROVED)
        // The person who did not get there first is marked skipped, not left
        // pending: the history should read as asked-then-overtaken.
        ->and($approval->decisions->last()->decision)->toBe(ApprovalDecision::SKIPPED);
});

it('asks the second approver only after the first has answered', function (): void {
    $first = makeAgent();
    $second = makeAgent();
    $workflow = ApprovalWorkflow::factory()->sequential([$first, $second])->create();

    [, $approval] = openApproval($workflow);

    expect($approval->decisions)->toHaveCount(1)
        ->and($approval->decisions->first()->approver_id)->toBe($first->getKey());

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED);

    $approval->refresh()->load('decisions');

    expect($approval->status)->toBe(ApprovalRequest::PENDING)
        ->and($approval->current_position)->toBe(1)
        ->and($approval->decisions)->toHaveCount(2)
        ->and($approval->decisions->last()->approver_id)->toBe($second->getKey());

    app(ApprovalService::class)->decide($approval->decisions->last(), ApprovalDecision::APPROVED);

    expect($approval->refresh()->status)->toBe(ApprovalRequest::APPROVED);
});

/**
 * An approval a majority can override is not an approval.
 */
it('ends the whole approval on a single rejection', function (): void {
    $first = makeAgent();
    $second = makeAgent();
    $workflow = ApprovalWorkflow::factory()->parallel([$first, $second])->create();

    [$ticket, $approval] = openApproval($workflow);

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::REJECTED);

    expect($approval->refresh()->status)->toBe(ApprovalRequest::REJECTED)
        ->and($ticket->refresh()->approval_state)->toBe(ApprovalRequest::REJECTED);
});

it('does not open a later step once an earlier one is refused', function (): void {
    $first = makeAgent();
    $second = makeAgent();
    $workflow = ApprovalWorkflow::factory()->sequential([$first, $second])->create();

    [, $approval] = openApproval($workflow);

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::REJECTED);

    expect($approval->refresh()->load('decisions')->decisions)->toHaveCount(1);
});

// -----------------------------------------------------------------
// Resolving who gets asked
// -----------------------------------------------------------------

it('asks everybody in a team', function (): void {
    $workflow = ApprovalWorkflow::factory()->create();
    $team = Team::factory()->create();

    $members = collect([makeAgent(), makeAgent()]);
    $members->each(fn (User $user) => $user->teams()->attach($team));

    $workflow->steps()->create([
        'mode' => ApprovalStep::ALL,
        'approver_type' => 'team',
        'approver_ids' => [$team->getKey()],
        'position' => 0,
    ]);

    [, $approval] = openApproval($workflow);

    expect($approval->decisions->pluck('approver_id')->sort()->values()->all())
        ->toBe($members->pluck('id')->sort()->values()->all());
});

it('asks the requester manager', function (): void {
    $manager = makeAgent();
    $requester = makeRequester(['manager_id' => $manager->getKey()]);

    $workflow = ApprovalWorkflow::factory()->create();
    $workflow->steps()->create(['mode' => ApprovalStep::ANY, 'approver_type' => 'manager', 'position' => 0]);

    [, $approval] = openApproval($workflow, $requester);

    expect($approval->decisions)->toHaveCount(1)
        ->and($approval->decisions->first()->approver_id)->toBe($manager->getKey());
});

/**
 * The requester approving their own request is not an approval.
 */
it('never asks the requester to approve their own request', function (): void {
    $requester = makeAgent();
    $other = makeAgent();

    $workflow = ApprovalWorkflow::factory()->parallel([$requester, $other])->create();

    [, $approval] = openApproval($workflow, $requester);

    expect($approval->decisions)->toHaveCount(1)
        ->and($approval->decisions->first()->approver_id)->toBe($other->getKey());
});

it('skips an inactive approver', function (): void {
    $active = makeAgent();
    $inactive = makeAgent(['is_active' => false]);

    $workflow = ApprovalWorkflow::factory()->parallel([$active, $inactive])->create();

    [, $approval] = openApproval($workflow);

    expect($approval->decisions)->toHaveCount(1)
        ->and($approval->decisions->first()->approver_id)->toBe($active->getKey());
});

/**
 * A ticket held forever by an approval addressed to nobody is worse than one
 * that was never held. The caller is told so it can say so.
 */
it('opens nothing when the step resolves to nobody', function (): void {
    $workflow = ApprovalWorkflow::factory()->create();
    $workflow->steps()->create([
        'mode' => ApprovalStep::ANY,
        'approver_type' => 'users',
        'approver_ids' => [],
        'position' => 0,
    ]);

    $ticket = Ticket::factory()->create();

    expect(app(ApprovalService::class)->open($ticket, $workflow))->toBeNull()
        ->and($ticket->refresh()->approval_state)->toBeNull();
});

it('moves past an empty step to the next one that has approvers', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->create();

    $workflow->steps()->create([
        'mode' => ApprovalStep::ANY, 'approver_type' => 'users', 'approver_ids' => [], 'position' => 0,
    ]);
    $workflow->steps()->create([
        'mode' => ApprovalStep::ANY, 'approver_type' => 'users',
        'approver_ids' => [$approver->getKey()], 'position' => 1,
    ]);

    [, $approval] = openApproval($workflow);

    expect($approval)->not->toBeNull()
        ->and($approval->current_position)->toBe(1)
        ->and($approval->decisions)->toHaveCount(1);
});

// -----------------------------------------------------------------
// Answering once
// -----------------------------------------------------------------

it('refuses a second answer on the same decision', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();

    [, $approval] = openApproval($workflow);
    $decision = $approval->decisions->first();

    app(ApprovalService::class)->decide($decision, ApprovalDecision::APPROVED);

    expect(fn () => app(ApprovalService::class)->decide($decision->refresh(), ApprovalDecision::REJECTED))
        ->toThrow(RuntimeException::class);

    expect($approval->refresh()->status)->toBe(ApprovalRequest::APPROVED);
});

it('keeps decisions already given when an approval is cancelled', function (): void {
    $first = makeAgent();
    $second = makeAgent();
    $workflow = ApprovalWorkflow::factory()->parallel([$first, $second])->create();

    [$ticket, $approval] = openApproval($workflow);

    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED);
    app(ApprovalService::class)->cancel($approval->refresh());

    $approval->refresh()->load('decisions');

    expect($approval->status)->toBe(ApprovalRequest::CANCELLED)
        ->and($approval->decisions->first()->decision)->toBe(ApprovalDecision::APPROVED)
        // Cancelling releases the ticket rather than leaving it blocked.
        ->and($ticket->refresh()->approval_state)->toBeNull();
});

it('kills the outstanding e-mail links when an approval is cancelled', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();

    [, $approval] = openApproval($workflow);
    $decision = $approval->decisions->first();
    $token = $decision->issueToken();

    app(ApprovalService::class)->cancel($approval);

    expect(ApprovalDecision::findByToken($token))->toBeNull();
});
