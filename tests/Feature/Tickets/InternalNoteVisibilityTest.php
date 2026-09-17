<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedServiceDesk();
});

/**
 * Internal notes leaking to a requester is the worst bug a service desk can
 * have, so the boundary is asserted from every angle: the payload, the policy
 * and the attachment download.
 */
test('an internal note is visible to agents and stripped for requesters', function () {
    $author = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->comment($ticket, 'Public answer', $author);
    app(TicketService::class)->comment($ticket, 'SECRET vendor pricing', $author, internal: true);

    // An agent with the internal permission sees both.
    $this->actingAs($author)
        ->get("/agent/tickets/{$ticket->key}")
        ->assertInertia(fn ($page) => $page->has('timeline', 2))
        ->assertSee('SECRET vendor pricing');

    // A requester reading the same ticket gets only the public reply — the
    // note is absent from the query result, not merely hidden by CSS.
    $requester = User::factory()->requester()->create();

    $readable = $ticket->comments()->readableBy($requester->fresh())->pluck('body_text')->all();

    expect($readable)->toBe(['Public answer']);
});

test('the internal flag is enforced by the policy as well as the query', function () {
    $agent = User::factory()->admin()->create();
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $public = app(TicketService::class)->comment($ticket, 'Public', $agent);
    $internal = app(TicketService::class)->comment($ticket, 'Internal', $agent, internal: true);

    expect($requester->fresh()->can('view', $public))->toBeTrue()
        ->and($requester->fresh()->can('view', $internal))->toBeFalse()
        ->and($agent->can('view', $internal))->toBeTrue();
});

test('an attachment on an internal note is not downloadable by the requester', function () {
    $agent = User::factory()->admin()->create();
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $note = app(TicketService::class)->comment($ticket, 'Internal', $agent, internal: true);

    $attachment = Attachment::query()->create([
        'ticket_id' => $ticket->id,
        'comment_id' => $note->id,
        'user_id' => $agent->id,
        'disk' => 'local',
        'path' => 'attachments/test.txt',
        'original_name' => 'vendor-quote.txt',
        'mime_type' => 'text/plain',
        'size' => 10,
        'is_internal' => true,
    ]);

    $this->actingAs($requester)->get("/attachments/{$attachment->id}")->assertForbidden();
});

test('a public attachment is downloadable by the requester', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/test.txt', 'hello');

    $agent = User::factory()->admin()->create();
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $attachment = Attachment::query()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $agent->id,
        'disk' => 'local',
        'path' => 'attachments/test.txt',
        'original_name' => 'screenshot.txt',
        'mime_type' => 'text/plain',
        'size' => 5,
        'is_internal' => false,
    ]);

    $this->actingAs($requester)->get("/attachments/{$attachment->id}")->assertOk();
});

test('an unrelated user cannot download any attachment of a ticket', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/test.txt', 'hello');

    $stranger = User::factory()->requester()->create();
    $ticket = Ticket::factory()->create();

    $attachment = Attachment::query()->create([
        'ticket_id' => $ticket->id,
        'disk' => 'local',
        'path' => 'attachments/test.txt',
        'original_name' => 'file.txt',
        'mime_type' => 'text/plain',
        'size' => 5,
        'is_internal' => false,
    ]);

    $this->actingAs($stranger)->get("/attachments/{$attachment->id}")->assertForbidden();
});

test('posting an internal note requires the internal comment permission', function () {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.comment');
    $ticket = Ticket::factory()->assignedTo($agent)->create();

    $this->actingAs($agent->fresh())
        ->post("/agent/tickets/{$ticket->key}/comments", ['body' => 'Note', 'is_internal' => true])
        ->assertForbidden();

    $this->actingAs($agent->fresh())
        ->post("/agent/tickets/{$ticket->key}/comments", ['body' => 'Reply', 'is_internal' => false])
        ->assertRedirect();

    expect($ticket->comments()->count())->toBe(1);
});
