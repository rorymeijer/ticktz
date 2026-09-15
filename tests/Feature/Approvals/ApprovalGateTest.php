<?php

declare(strict_types=1);

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\WorkflowTransition;
use App\Services\Approvals\ApprovalService;
use App\Services\Tickets\TicketService;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * Mark the transition into `$slug` as needing approval, and hand it back.
 */
function gateTransitionInto(Ticket $ticket, string $slug): WorkflowTransition
{
    $target = status($slug);

    $transition = WorkflowTransition::query()
        ->where('workflow_id', $ticket->workflow_id)
        ->where('to_status_id', $target->getKey())
        ->firstOrFail();

    $transition->forceFill(['requires_approval' => true])->save();

    return $transition;
}

/*
|--------------------------------------------------------------------------
| The acceptance criterion
|--------------------------------------------------------------------------
|
| From the brief: "een request type met verplichte goedkeuring blokkeert
| voortgang tot een approver akkoord geeft."
|
*/

it('blocks progress on a request type that needs approval until an approver agrees', function (): void {
    $approver = makeAgent();
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.transition');

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $requestType = RequestType::factory()->create(['approval_workflow_id' => $workflow->getKey()]);

    $ticket = app(TicketService::class)->create([
        'subject' => 'A laptop for the new starter',
        'requester_id' => makeRequester()->getKey(),
        'request_type_id' => $requestType->getKey(),
    ]);

    $target = status('open');
    gateTransitionInto($ticket, 'open');

    // The approval opened as the ticket was filed, so the gate is already up.
    expect($ticket->refresh()->approval_state)->toBe(ApprovalRequest::PENDING);

    expect(fn () => app(TicketService::class)->transition($ticket, $target, $agent))
        ->toThrow(RuntimeException::class);

    expect($ticket->refresh()->status->slug)->not->toBe('open');

    // The approver agrees, and only then does the ticket move.
    $approval = $ticket->approvals()->with('decisions')->first();
    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::APPROVED, null, $approver);

    app(TicketService::class)->transition($ticket->refresh(), $target, $agent);

    expect($ticket->refresh()->status->slug)->toBe('open');
});

it('keeps blocking after a rejection', function (): void {
    $approver = makeAgent();
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.transition');

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $requestType = RequestType::factory()->create(['approval_workflow_id' => $workflow->getKey()]);

    $ticket = app(TicketService::class)->create([
        'subject' => 'Something expensive',
        'requester_id' => makeRequester()->getKey(),
        'request_type_id' => $requestType->getKey(),
    ]);

    gateTransitionInto($ticket, 'open');

    $approval = $ticket->approvals()->with('decisions')->first();
    app(ApprovalService::class)->decide($approval->decisions->first(), ApprovalDecision::REJECTED, null, $approver);

    expect(fn () => app(TicketService::class)->transition($ticket->refresh(), status('open'), $agent))
        ->toThrow(RuntimeException::class);
});

/**
 * The gate is on the transition, not on the ticket. A ticket awaiting approval
 * can still be closed, put on hold or cancelled — only the steps the workflow
 * actually gates are refused.
 */
it('only blocks the transitions that are marked as needing approval', function (): void {
    $approver = makeAgent();
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.transition');

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $requestType = RequestType::factory()->create(['approval_workflow_id' => $workflow->getKey()]);

    $ticket = app(TicketService::class)->create([
        'subject' => 'Gated one way only',
        'requester_id' => makeRequester()->getKey(),
        'request_type_id' => $requestType->getKey(),
    ]);

    gateTransitionInto($ticket, 'open');

    // "Waiting for requester" was never gated, so it still works.
    app(TicketService::class)->transition($ticket, status('waiting-for-requester'), $agent);

    expect($ticket->refresh()->status->slug)->toBe('waiting-for-requester')
        ->and($ticket->approval_state)->toBe(ApprovalRequest::PENDING);
});

/**
 * The gate fails closed.
 *
 * A ticket with no approval at all has not been approved, so a transition
 * marked as needing approval refuses it. The alternative — only blocking when
 * an approval exists and is pending — would mean any ticket that reached the
 * workflow by another route (an e-mail, an agent filing it by hand) walked
 * straight past the control. A gate with a way round it is not a gate.
 *
 * The way forward for such a ticket is to raise an approval on it, which is
 * what the panel on the ticket page is for.
 */
it('blocks a gated transition on a ticket that has no approval at all', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.transition');

    $ticket = Ticket::factory()->create();
    gateTransitionInto($ticket, 'open');

    expect($ticket->approval_state)->toBeNull();

    expect(fn () => app(TicketService::class)->transition($ticket, status('open'), $agent))
        ->toThrow(RuntimeException::class);

    expect($ticket->refresh()->status->slug)->not->toBe('open');
});

it('lets an ungated transition through on a ticket with no approval', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.transition');

    $ticket = Ticket::factory()->create();

    app(TicketService::class)->transition($ticket, status('open'), $agent);

    expect($ticket->refresh()->status->slug)->toBe('open');
});

it('refuses the gated transition over http, not just in the service', function (): void {
    $approver = makeAgent();
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.transition', 'tickets.update');

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $requestType = RequestType::factory()->create(['approval_workflow_id' => $workflow->getKey()]);

    $ticket = app(TicketService::class)->create([
        'subject' => 'Over the wire',
        'requester_id' => makeRequester()->getKey(),
        'request_type_id' => $requestType->getKey(),
    ]);

    gateTransitionInto($ticket, 'open');

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/transition", ['status_id' => status('open')->getKey()])
        ->assertRedirect();

    expect($ticket->refresh()->status->slug)->not->toBe('open');
});

it('re-opens the gate when a fresh approval is raised after a rejection', function (): void {
    $approver = makeAgent();
    $second = makeAgent();
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.transition');

    $workflow = ApprovalWorkflow::factory()->single($approver)->create();
    $ticket = Ticket::factory()->create();

    gateTransitionInto($ticket, 'open');

    $first = app(ApprovalService::class)->open($ticket, $workflow);
    app(ApprovalService::class)->decide($first->decisions->first(), ApprovalDecision::REJECTED);

    expect($ticket->refresh()->approval_state)->toBe(ApprovalRequest::REJECTED);

    // A second opinion: the newest run is the one that counts.
    $secondRun = ApprovalWorkflow::factory()->single($second)->create();
    $again = app(ApprovalService::class)->open($ticket->refresh(), $secondRun);

    expect($ticket->refresh()->approval_state)->toBe(ApprovalRequest::PENDING);

    app(ApprovalService::class)->decide($again->decisions->first(), ApprovalDecision::APPROVED);

    app(TicketService::class)->transition($ticket->refresh(), status('open'), $agent);

    expect($ticket->refresh()->status->slug)->toBe('open');
});
