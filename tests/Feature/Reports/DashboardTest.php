<?php

declare(strict_types=1);

use App\Models\Ticket;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * Find a dashboard card by its key.
 *
 * @param  array<int, array<string, mixed>>  $cards
 * @return array<string, mixed>|null
 */
function card(array $cards, string $key): ?array
{
    return collect($cards)->firstWhere('key', $key);
}

/**
 * The regression this file exists for: `is_open` is a derived attribute on
 * TicketStatus, not a column, so `where('is_open', true)` matches nothing —
 * silently. On a dashboard that means every figure reads zero and looks like a
 * quiet day, which is the one way a dashboard can be wrong without anybody
 * noticing.
 */
it('counts the tickets assigned to the reader', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all');

    Ticket::factory()->count(3)->create([
        'assignee_id' => $agent->getKey(),
        'status_id' => status('open')->getKey(),
    ]);

    // A resolved one does not count: the card is about what is still open.
    Ticket::factory()->create([
        'assignee_id' => $agent->getKey(),
        'status_id' => status('resolved')->getKey(),
    ]);

    $response = $this->actingAs($agent)->get('/dashboard')->assertOk();

    expect(card($response->viewData('page')['props']['cards'], 'assigned_to_me')['count'])->toBe(3);
});

it('counts the unassigned tickets', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all');

    Ticket::factory()->count(2)->create(['assignee_id' => null, 'status_id' => status('new')->getKey()]);
    Ticket::factory()->create(['assignee_id' => $agent->getKey(), 'status_id' => status('new')->getKey()]);

    $response = $this->actingAs($agent)->get('/dashboard')->assertOk();

    expect(card($response->viewData('page')['props']['cards'], 'unassigned')['count'])->toBe(2);
});

it('counts the open tickets whose SLA has been breached', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all');

    Ticket::factory()->create(['sla_breached' => true, 'status_id' => status('open')->getKey()]);
    // Breached but finished: the desk cannot act on it any more.
    Ticket::factory()->create(['sla_breached' => true, 'status_id' => status('closed')->getKey()]);

    $response = $this->actingAs($agent)->get('/dashboard')->assertOk();

    expect(card($response->viewData('page')['props']['cards'], 'breached')['count'])->toBe(1);
});

it('gives a requester their own open requests', function (): void {
    $requester = makeRequester();

    Ticket::factory()->count(2)->create([
        'requester_id' => $requester->getKey(),
        'status_id' => status('open')->getKey(),
    ]);

    $response = $this->actingAs($requester)->get('/dashboard')->assertOk();

    expect(card($response->viewData('page')['props']['cards'], 'open_requests')['count'])->toBe(2)
        // And none of the agent cards, which are about somebody else's desk.
        ->and(card($response->viewData('page')['props']['cards'], 'assigned_to_me'))->toBeNull();
});

it('does not show the trend to somebody who may not read reports', function (): void {
    $agent = makeUserWithPermissions('tickets.view');

    $response = $this->actingAs($agent)->get('/dashboard')->assertOk();

    expect($response->viewData('page')['props']['trend'])->toBeNull();
});
