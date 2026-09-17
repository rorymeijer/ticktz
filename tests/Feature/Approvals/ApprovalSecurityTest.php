<?php

declare(strict_types=1);

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use App\Models\Ticket;
use App\Services\Approvals\ApprovalService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| Only the person who was asked may answer
|--------------------------------------------------------------------------
|
| This is the property the whole feature rests on. "The budget holder approved
| it" has to mean the budget holder — not somebody with a permission, and not
| an administrator who could have.
|
*/

it('lets the approver answer their own decision', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $this->actingAs($approver)
        ->post('/approvals/decisions/'.$approval->decisions->first()->id, ['decision' => 'approved'])
        ->assertRedirect();

    expect($approval->refresh()->status)->toBe(ApprovalRequest::APPROVED);
});

it('refuses somebody else answering on the approver behalf', function (): void {
    $approver = makeAgent();
    $bystander = makeUserWithPermissions('approvals.view', 'approvals.decide', 'approvals.manage');

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $this->actingAs($bystander)
        ->post('/approvals/decisions/'.$approval->decisions->first()->id, ['decision' => 'approved'])
        ->assertForbidden();

    expect($approval->refresh()->status)->toBe(ApprovalRequest::PENDING);
});

/**
 * A super-admin passes every other gate in Ticktz. Not this one: an approval
 * an administrator could have given is worth nothing as evidence.
 */
it('refuses even a super admin answering for somebody else', function (): void {
    $approver = makeAgent();
    $admin = makeAdmin();

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $this->actingAs($admin)
        ->post('/approvals/decisions/'.$approval->decisions->first()->id, ['decision' => 'approved'])
        ->assertForbidden();

    expect($approval->refresh()->status)->toBe(ApprovalRequest::PENDING);
});

/**
 * An approver is very often a requester as far as permissions go — a budget
 * holder who has never seen the agent console. The inbox has to work for them.
 */
it('lets a requester who was asked reach the approval inbox', function (): void {
    $approver = makeRequester();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $response = $this->actingAs($approver)->get('/approvals')->assertOk();

    expect(collect($response->viewData('page')['props']['approvals']['data'])->pluck('id'))
        ->toContain($approval->id);
});

it('shows an approver only what they were asked', function (): void {
    $mine = makeRequester();
    $theirs = makeRequester();

    $ours = app(ApprovalService::class)->open(
        Ticket::factory()->create(),
        ApprovalWorkflow::factory()->single($mine)->create(),
    );
    $other = app(ApprovalService::class)->open(
        Ticket::factory()->create(),
        ApprovalWorkflow::factory()->single($theirs)->create(),
    );

    $response = $this->actingAs($mine)->get('/approvals')->assertOk();
    $ids = collect($response->viewData('page')['props']['approvals']['data'])->pluck('id');

    expect($ids)->toContain($ours->id)->not->toContain($other->id);
});

// -----------------------------------------------------------------
// Deciding from an e-mail
// -----------------------------------------------------------------

it('opens the decision page for a valid token without signing in', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $token = $approval->decisions->first()->issueToken();

    $response = $this->get("/approvals/decide/{$token}")->assertOk();

    expect($response->viewData('page')['props']['decision'])->not->toBeNull();
});

/**
 * The link in the mail must not decide anything on its own. A GET that
 * approved would be answered by the first link-rewriter, scanner or prefetcher
 * that touched the message — silently, and indistinguishably from a real
 * approval.
 */
it('decides nothing when the link is merely opened', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $token = $approval->decisions->first()->issueToken();

    $this->get("/approvals/decide/{$token}")->assertOk();

    expect($approval->refresh()->status)->toBe(ApprovalRequest::PENDING)
        ->and($approval->decisions()->first()->decision)->toBe(ApprovalDecision::PENDING);
});

it('decides on the post from that page', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $token = $approval->decisions->first()->issueToken();

    $this->post("/approvals/decide/{$token}", ['decision' => 'approved', 'comment' => 'Fine by me'])
        ->assertRedirect(route('approvals.token.done', ['outcome' => 'approved']));

    $approval->refresh()->load('decisions');

    expect($approval->status)->toBe(ApprovalRequest::APPROVED)
        ->and($approval->decisions->first()->source)->toBe('email')
        ->and($approval->decisions->first()->comment)->toBe('<p>Fine by me</p>');
});

/**
 * Single use: a forwarded mail cannot be replayed.
 */
it('kills the token once it has been used', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $token = $approval->decisions->first()->issueToken();

    $this->post("/approvals/decide/{$token}", ['decision' => 'approved']);

    expect(ApprovalDecision::findByToken($token))->toBeNull();

    $response = $this->get("/approvals/decide/{$token}")->assertOk();

    expect($response->viewData('page')['props']['decision'])->toBeNull();
});

it('refuses an expired token', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $decision = $approval->decisions->first();
    $token = $decision->issueToken();

    $decision->forceFill(['token_expires_at' => now()->subMinute()])->save();

    expect(ApprovalDecision::findByToken($token))->toBeNull();

    $this->post("/approvals/decide/{$token}", ['decision' => 'approved']);

    expect($approval->refresh()->status)->toBe(ApprovalRequest::PENDING);
});

it('refuses a made-up token', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $this->post('/approvals/decide/'.str_repeat('a', 48), ['decision' => 'approved']);

    expect($approval->refresh()->status)->toBe(ApprovalRequest::PENDING);
});

/**
 * A database backup must not be a pile of working approve links.
 */
it('never stores the token in the clear', function (): void {
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $decision = $approval->decisions->first();
    $token = $decision->issueToken();

    $stored = DB::table('approval_decisions')->where('id', $decision->id)->value('token_hash');

    expect($stored)->not->toBe($token)
        ->and($stored)->toBe(hash('sha256', $token))
        ->and($decision->fresh()->toArray())->not->toHaveKey('token_hash');
});

// -----------------------------------------------------------------
// Cancelling
// -----------------------------------------------------------------

it('lets whoever raised an approval withdraw it', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'approvals.decide');
    $approver = makeAgent();

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow, [], $agent);

    $this->actingAs($agent)->post("/approvals/{$approval->id}/cancel")->assertRedirect();

    expect($approval->refresh()->status)->toBe(ApprovalRequest::CANCELLED);
});

/**
 * "I withdrew the question rather than answer it" is not an answer.
 */
it('refuses an approver withdrawing the approval they were asked to give', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'approvals.decide');
    $approver = makeAgent();

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow, [], $agent);

    $this->actingAs($approver)->post("/approvals/{$approval->id}/cancel")->assertForbidden();

    expect($approval->refresh()->status)->toBe(ApprovalRequest::PENDING);
});
