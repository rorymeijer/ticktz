<?php

declare(strict_types=1);

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\RequestType;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Approvals\ApprovalService;

beforeEach(function (): void {
    seedServiceDesk();
});

it('refuses the admin screen without the permission', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'approvals.view', 'approvals.decide');

    $this->actingAs($agent)->get('/admin/approvals')->assertForbidden();
});

it('creates a workflow with its steps', function (): void {
    $admin = makeAdmin();
    $first = makeAgent();
    $second = makeAgent();

    $this->actingAs($admin)->post('/admin/approvals', [
        'name' => 'Hardware over 1000 euro',
        'slug' => 'hardware-over-1000',
        'instructions' => 'Check the budget line first.',
        'is_active' => true,
        'steps' => [
            ['mode' => 'any', 'approver_type' => 'manager'],
            [
                'name' => 'Budget holder',
                'mode' => 'all',
                'approver_type' => 'users',
                'approver_ids' => [$first->getKey(), $second->getKey()],
                'due_hours' => 48,
            ],
        ],
    ])->assertRedirect();

    $workflow = ApprovalWorkflow::query()->where('slug', 'hardware-over-1000')->sole();

    expect($workflow->steps)->toHaveCount(2)
        ->and($workflow->shape())->toBe('sequential')
        ->and($workflow->steps->first()->approver_type)->toBe('manager')
        // Ids are only kept for the types that use them; leaving a stale list
        // on a `manager` step would read as configuration later.
        ->and($workflow->steps->first()->approver_ids)->toBeNull()
        ->and($workflow->steps->last()->due_hours)->toBe(48);
});

it('replaces the steps on save rather than accumulating them', function (): void {
    $admin = makeAdmin();
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->sequential([makeAgent(), makeAgent()])->create();

    $this->actingAs($admin)->put("/admin/approvals/{$workflow->id}", [
        'name' => $workflow->name,
        'slug' => $workflow->slug,
        'steps' => [
            ['mode' => 'any', 'approver_type' => 'users', 'approver_ids' => [$approver->getKey()]],
        ],
    ])->assertRedirect();

    expect($workflow->refresh()->steps)->toHaveCount(1)
        ->and($workflow->shape())->toBe('single');
});

/**
 * Running approvals copy everything they need into their own decision rows, so
 * rewriting a definition cannot disturb one already in flight. That is the
 * property that makes replace-on-save safe.
 */
it('leaves a running approval alone when its workflow is rewritten', function (): void {
    $admin = makeAdmin();
    $approver = makeAgent();
    $workflow = ApprovalWorkflow::factory()->single($approver)->create();

    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    $this->actingAs($admin)->put("/admin/approvals/{$workflow->id}", [
        'name' => $workflow->name,
        'slug' => $workflow->slug,
        'steps' => [['mode' => 'all', 'approver_type' => 'users', 'approver_ids' => [makeAgent()->getKey()]]],
    ])->assertRedirect();

    $approval->refresh()->load('decisions');

    expect($approval->decisions)->toHaveCount(1)
        ->and($approval->decisions->first()->approver_id)->toBe($approver->getKey())
        ->and($approval->status)->toBe(ApprovalRequest::PENDING);

    // And it can still be answered.
    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED);

    expect($approval->refresh()->status)->toBe(ApprovalRequest::APPROVED);
});

it('releases the request types when a workflow is deleted', function (): void {
    $admin = makeAdmin();
    $workflow = ApprovalWorkflow::factory()->single(makeAgent())->create();
    $requestType = RequestType::factory()->create(['approval_workflow_id' => $workflow->getKey()]);

    $this->actingAs($admin)->delete("/admin/approvals/{$workflow->id}")->assertRedirect();

    expect($requestType->refresh()->approval_workflow_id)->toBeNull();
});

it('keeps a request type approval workflow through the admin form', function (): void {
    $admin = makeAdmin();
    $workflow = ApprovalWorkflow::factory()->single(makeAgent())->create();
    $requestType = RequestType::factory()->create();

    $this->actingAs($admin)->put("/admin/request-types/{$requestType->id}", [
        'name' => $requestType->name,
        'slug' => $requestType->slug,
        'visibility' => 'everyone',
        'approval_workflow_id' => $workflow->getKey(),
    ])->assertRedirect();

    expect($requestType->refresh()->approval_workflow_id)->toBe($workflow->getKey());
});

/**
 * An `all` step cannot be satisfied by one person answering twice, so the same
 * approver is never asked twice in the same step.
 */
it('asks one person once per step even when two sources name them', function (): void {
    $approver = makeAgent();
    $team = Team::factory()->create();
    $approver->teams()->attach($team);

    $workflow = ApprovalWorkflow::factory()->create();
    $workflow->steps()->create([
        'mode' => ApprovalStep::ALL,
        'approver_type' => 'team',
        'approver_ids' => [$team->getKey(), $team->getKey()],
        'position' => 0,
    ]);

    $approval = app(ApprovalService::class)->open(Ticket::factory()->create(), $workflow);

    expect($approval->decisions)->toHaveCount(1);
});

it('lets an agent raise an approval on a ticket by naming people', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'approvals.decide');
    $approver = makeAgent();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->post("/agent/tickets/{$ticket->key}/approvals", [
        'approver_ids' => [$approver->getKey()],
        'subject' => 'Sign off on the overtime',
        'reason' => 'Four hours outside office hours.',
    ])->assertRedirect();

    $approval = $ticket->approvals()->with('decisions')->first();

    expect($approval)->not->toBeNull()
        ->and($approval->subject)->toBe('Sign off on the overtime')
        ->and($approval->decisions->first()->approver_id)->toBe($approver->getKey())
        ->and($ticket->refresh()->approval_state)->toBe(ApprovalRequest::PENDING);
});

it('refuses raising an approval that names nobody', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'approvals.decide');
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->post("/agent/tickets/{$ticket->key}/approvals", [])->assertRedirect();

    expect($ticket->refresh()->approvals()->count())->toBe(0);
});

it('refuses raising an approval without the permission', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update');
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/approvals", ['approver_ids' => [makeAgent()->getKey()]])
        ->assertForbidden();
});

/**
 * The manager relation is what an approval step of type `manager` reads, so
 * the admin form has to be able to set it.
 */
it('sets a manager from the user admin form', function (): void {
    $admin = makeAdmin();
    $manager = makeAgent();
    $user = makeRequester();

    $this->actingAs($admin)->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => 'en',
        'manager_id' => $manager->getKey(),
        'is_active' => true,
    ])->assertRedirect();

    expect($user->refresh()->manager_id)->toBe($manager->getKey())
        ->and($user->manager->name)->toBe($manager->name);
});

it('refuses making somebody their own manager', function (): void {
    $admin = makeAdmin();
    $user = makeRequester();

    $this->actingAs($admin)->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => 'en',
        'manager_id' => $user->getKey(),
        'is_active' => true,
    ])->assertSessionHasErrors('manager_id');

    expect($user->refresh()->manager_id)->toBeNull();
});

it('carries the approval gate through the workflow admin form', function (): void {
    $admin = makeAdmin();
    $workflow = Workflow::query()->where('slug', 'default')->sole();

    $newStatus = status('open');
    $from = status('new');

    $this->actingAs($admin)->put("/admin/workflows/{$workflow->id}", [
        'name' => $workflow->name,
        'slug' => $workflow->slug,
        'is_active' => true,
        'status_ids' => [$from->getKey(), $newStatus->getKey()],
        'initial_status_id' => $from->getKey(),
        'transitions' => [
            [
                'from_status_id' => $from->getKey(),
                'to_status_id' => $newStatus->getKey(),
                'requires_approval' => true,
            ],
        ],
    ])->assertRedirect();

    expect(
        $workflow->transitions()
            ->where('from_status_id', $from->getKey())
            ->where('to_status_id', $newStatus->getKey())
            ->value('requires_approval'),
    )->toBeTruthy();
});

it('lists the users an approval step can pick from', function (): void {
    $admin = makeAdmin();
    /** @var User $agent */
    $agent = makeAgent();

    $response = $this->actingAs($admin)->get('/admin/approvals')->assertOk();

    expect(collect($response->viewData('page')['props']['options']['users'])->pluck('id'))
        ->toContain($agent->getKey());
});
