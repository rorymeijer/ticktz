<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Label;
use App\Models\Queue;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;

beforeEach(function () {
    seedServiceDesk();
});

test('the ticket list requires a ticket permission', function () {
    $requester = User::factory()->requester()->create();

    $this->actingAs($requester)->get('/agent/tickets')->assertForbidden();
});

test('an agent sees the ticket list', function () {
    $agent = User::factory()->admin()->create();
    Ticket::factory()->count(3)->create();

    $this->actingAs($agent)
        ->get('/agent/tickets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agent/Tickets/Index')->has('tickets.data', 3));
});

test('the list only contains tickets the agent may see', function () {
    $agent = makeUserWithPermissions('tickets.view');
    $mine = Ticket::factory()->assignedTo($agent)->create();
    Ticket::factory()->count(2)->create();

    $this->actingAs($agent->fresh())
        ->get('/agent/tickets')
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1)
            ->where('tickets.data.0.key', $mine->key));
});

test('a queue applies its saved filter', function () {
    $agent = User::factory()->admin()->create();

    $unassigned = Ticket::factory()->create();
    Ticket::factory()->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->get('/agent/tickets?queue_slug=unassigned')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1)
            ->where('tickets.data.0.key', $unassigned->key)
            ->where('queue.slug', 'unassigned'));
});

test('searching by ticket key jumps straight to it', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();
    Ticket::factory()->count(3)->create();

    $this->actingAs($agent)
        ->get('/agent/tickets?search='.$ticket->key)
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1)
            ->where('tickets.data.0.key', $ticket->key));
});

test('searching by subject matches on text', function () {
    $agent = User::factory()->admin()->create();
    Ticket::factory()->create(['subject' => 'VPN verbinding valt weg']);
    Ticket::factory()->create(['subject' => 'Printer storing']);

    $this->actingAs($agent)
        ->get('/agent/tickets?search=VPN')
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1));
});

test('an agent creates a ticket through the console', function () {
    $agent = User::factory()->admin()->create();
    $requester = User::factory()->requester()->create();
    $label = Label::factory()->create();

    $this->actingAs($agent)
        ->post('/agent/tickets', [
            'subject' => 'Nieuw werkstation aanvragen',
            'description' => 'Voor de nieuwe collega per 1 oktober.',
            'requester_id' => $requester->id,
            'priority_id' => priority('high')->id,
            'label_ids' => [$label->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $ticket = Ticket::query()->sole();

    expect($ticket->subject)->toBe('Nieuw werkstation aanvragen')
        ->and($ticket->requester_id)->toBe($requester->id)
        ->and($ticket->priority->slug)->toBe('high')
        ->and($ticket->labels->pluck('id')->all())->toBe([$label->id])
        ->and($ticket->created_by)->toBe($agent->id);
});

test('creating a ticket validates its payload', function () {
    $agent = User::factory()->admin()->create();

    $this->actingAs($agent)
        ->post('/agent/tickets', ['subject' => '', 'requester_id' => 999999])
        ->assertSessionHasErrors(['subject', 'requester_id']);
});

test('an agent opens a ticket by its key', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->get("/agent/tickets/{$ticket->key}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agent/Tickets/Show')
            ->where('ticket.key', $ticket->key)
            ->has('transitions'));
});

test('an agent cannot open a ticket outside their scope', function () {
    $agent = makeUserWithPermissions('tickets.view');
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent->fresh())->get("/agent/tickets/{$ticket->key}")->assertForbidden();
});

test('an agent claims a ticket', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->post("/agent/tickets/{$ticket->key}/claim")->assertRedirect();

    expect($ticket->fresh()->assignee_id)->toBe($agent->id);
});

test('a requester cannot be made the assignee', function () {
    $agent = User::factory()->admin()->create();
    $requester = User::factory()->requester()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}/assignee", ['assignee_id' => $requester->id])
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Who may hold a ticket
|--------------------------------------------------------------------------
|
| A ticket that belongs to a team can only be given to somebody on that team.
| Enforced on the server rather than by leaving people out of a dropdown,
| because the same assignment arrives from four places and only one of them
| has a dropdown.
*/

test('a ticket can be assigned to somebody on its team', function () {
    $agent = User::factory()->admin()->create();
    $member = User::factory()->agent()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member);

    $ticket = Ticket::factory()->create(['team_id' => $team->id]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}/assignee", ['assignee_id' => $member->id])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBe($member->id);
});

test('a ticket cannot be assigned to somebody outside its team', function () {
    $agent = User::factory()->admin()->create();
    $outsider = User::factory()->agent()->create();
    $team = Team::factory()->create();

    $ticket = Ticket::factory()->create(['team_id' => $team->id]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}/assignee", ['assignee_id' => $outsider->id])
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

test('claiming a ticket obeys the same rule', function () {
    // The way around it that everybody finds first: do not hand it to
    // yourself, take it.
    $outsider = User::factory()->agent()->create();
    $team = Team::factory()->create();

    $ticket = Ticket::factory()->create(['team_id' => $team->id]);

    $this->actingAs($outsider)
        ->post("/agent/tickets/{$ticket->key}/claim")
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

test('a ticket with no team can be assigned to any agent', function () {
    // Plenty of work arrives without a team on it — an e-mail that matched no
    // queue, a ticket typed straight in. Refusing to assign any of it would be
    // strict and unusable.
    $agent = User::factory()->admin()->create();
    $anybody = User::factory()->agent()->create();

    $ticket = Ticket::factory()->create(['team_id' => null]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}/assignee", ['assignee_id' => $anybody->id])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBe($anybody->id);
});

test('a ticket cannot be created already in the wrong hands', function () {
    // The way around the rule that costs nothing to find: do not assign the
    // ticket afterwards, assign it while creating it.
    $agent = User::factory()->admin()->create();
    $outsider = User::factory()->agent()->create();
    $team = Team::factory()->create();

    $this->actingAs($agent)
        ->post('/agent/tickets', [
            'subject' => 'Mail loop on the finance alias',
            'requester_id' => User::factory()->requester()->create()->id,
            'team_id' => $team->id,
            'assignee_id' => $outsider->id,
        ])
        ->assertSessionHasErrors('assignee_id');

    expect(Ticket::query()->count())->toBe(0);
});

test('a ticket can be created in the right hands', function () {
    $agent = User::factory()->admin()->create();
    $member = User::factory()->agent()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member);

    $this->actingAs($agent)
        ->post('/agent/tickets', [
            'subject' => 'Mail loop on the finance alias',
            'requester_id' => User::factory()->requester()->create()->id,
            'team_id' => $team->id,
            'assignee_id' => $member->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Ticket::query()->sole()->assignee_id)->toBe($member->id);
});

test('the ticket form obeys the rule as well as the assign action', function () {
    $agent = User::factory()->admin()->create();
    $outsider = User::factory()->agent()->create();
    $team = Team::factory()->create();

    $ticket = Ticket::factory()->create(['team_id' => $team->id]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}", ['assignee_id' => $outsider->id])
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

test('the team a ticket is being moved to is the one that decides', function () {
    // Moving and assigning in one post. The team that matters is the one the
    // ticket will belong to when the post is applied, not the one it belongs
    // to while the form is open — otherwise the rule refuses a correct
    // assignment and permits a wrong one, in the same request.
    $agent = User::factory()->admin()->create();
    $member = User::factory()->agent()->create();
    $before = Team::factory()->create();
    $after = Team::factory()->create();
    $after->members()->attach($member);

    $ticket = Ticket::factory()->create(['team_id' => $before->id]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}", [
            'team_id' => $after->id,
            'assignee_id' => $member->id,
        ])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBe($member->id);
});

test("a ticket with no team of its own inherits its queue's", function () {
    $agent = User::factory()->admin()->create();
    $outsider = User::factory()->agent()->create();
    $team = Team::factory()->create();
    $queue = Queue::factory()->create(['team_id' => $team->id]);

    $ticket = Ticket::factory()->create(['team_id' => null, 'queue_id' => $queue->id]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}/assignee", ['assignee_id' => $outsider->id])
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Moving a ticket out from under the person holding it
|--------------------------------------------------------------------------
|
| Refusing the move instead would make routine triage a two-step job, and
| leaving the ticket assigned outside its new team would make the rule a
| suggestion. So the move wins and the assignment is let go — audibly.
*/

test('moving a ticket to another team lets go of an assignee who is not on it', function () {
    $agent = User::factory()->admin()->create();
    $member = User::factory()->agent()->create();
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $first->members()->attach($member);

    $ticket = Ticket::factory()->create(['team_id' => $first->id, 'assignee_id' => $member->id]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}", ['team_id' => $second->id])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

test('moving a ticket keeps an assignee who is on both teams', function () {
    $agent = User::factory()->admin()->create();
    $member = User::factory()->agent()->create();
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $first->members()->attach($member);
    $second->members()->attach($member);

    $ticket = Ticket::factory()->create(['team_id' => $first->id, 'assignee_id' => $member->id]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}", ['team_id' => $second->id])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBe($member->id);
});

test('an edit that does not move the ticket leaves an older mismatch alone', function () {
    // An installation upgrading into this rule has tickets that already break
    // it. Editing the subject of one is not the moment to take it off whoever
    // has been working on it all week.
    $agent = User::factory()->admin()->create();
    $outsider = User::factory()->agent()->create();
    $team = Team::factory()->create();

    $ticket = Ticket::factory()->create(['team_id' => $team->id, 'assignee_id' => $outsider->id]);

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}", ['subject' => 'Renamed while triaging'])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBe($outsider->id);
});

test('a transition that needs a comment is refused without one', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->transition($ticket, status('open'), $agent);

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/transition", ['status_id' => status('resolved')->id])
        ->assertSessionHasErrors('comment');

    expect($ticket->fresh()->status->slug)->toBe('open');

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/transition", [
            'status_id' => status('resolved')->id,
            'comment' => 'Nieuwe toner geplaatst.',
        ])
        ->assertRedirect();

    expect($ticket->fresh()->status->slug)->toBe('resolved');
});

test('an illegal transition is rejected by the endpoint too', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/transition", ['status_id' => status('closed')->id])
        ->assertSessionHasErrors('status_id');
});

test('an agent updates ticket fields', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->put("/agent/tickets/{$ticket->key}", ['priority_id' => priority('urgent')->id])
        ->assertRedirect();

    expect($ticket->fresh()->priority->slug)->toBe('urgent');
});

test('tickets can be linked to each other and the inverse is shown', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();
    $other = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/links", ['related_key' => $other->key, 'type' => 'duplicates'])
        ->assertRedirect();

    $this->actingAs($agent)
        ->get("/agent/tickets/{$other->key}")
        ->assertInertia(fn ($page) => $page->where('ticket.links.0.type', 'duplicated_by')
            ->where('ticket.links.0.ticket.key', $ticket->key));
});

test('a ticket cannot be linked to itself', function () {
    $agent = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/links", ['related_key' => $ticket->key, 'type' => 'relates'])
        ->assertSessionHasErrors('related_key');
});

test('the queue overview counts tickets per queue', function () {
    $agent = User::factory()->admin()->create();
    Ticket::factory()->count(2)->create();

    $this->actingAs($agent)
        ->get('/agent/queues')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agent/Queues/Index')
            ->has('queues', Queue::query()->count()));
});

/*
|--------------------------------------------------------------------------
| The answers to the form
|--------------------------------------------------------------------------
|
| A custom field exists so that the person who has to act on a request has the
| information without asking for it. The portal collected the answers, stored
| them and showed them back to the customer — and the agent console never
| rendered them at all, which made the whole feature half a feature.
*/

test('an agent sees the answers the requester gave', function () {
    $agent = User::factory()->admin()->create();

    $field = CustomField::factory()->create([
        'key' => 'serial_number',
        'label' => 'Serial number',
        'type' => 'text',
    ]);

    $ticket = Ticket::factory()->create();
    $ticket->setCustomFields(['serial_number' => 'PF-0X91223']);

    $this->actingAs($agent)
        ->get("/agent/tickets/{$ticket->key}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Show')
            ->where('ticket.fields.0.label', 'Serial number')
            ->where('ticket.fields.0.display', 'PF-0X91223'));

    expect($field->fresh())->not->toBeNull();
});

test('the agent sees the fields the portal keeps to the desk', function () {
    // The portal asks for `includePrivate: false`; the console is the side
    // those fields were marked private *for*.
    $agent = User::factory()->admin()->create();

    CustomField::factory()->create([
        'key' => 'internal_reference',
        'label' => 'Internal reference',
        'type' => 'text',
        'is_public' => false,
    ]);

    $ticket = Ticket::factory()->create();
    $ticket->setCustomFields(['internal_reference' => 'CHG-44']);

    $this->actingAs($agent)
        ->get("/agent/tickets/{$ticket->key}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ticket.fields.0.display', 'CHG-44'));
});

/*
|--------------------------------------------------------------------------
| Handing a ticket to somebody is its own permission
|--------------------------------------------------------------------------
|
| `tickets.assign` gated the assign action and the button on the screen, and
| nothing on the way in. So the way to hand a ticket to somebody without the
| permission to hand out tickets was to do it while creating one — the same
| shape of hole as the team rule had, one layer further out.
|
| The API description has claimed this rule since the API existed, which is the
| part that makes it a defect rather than a decision.
*/

test('filing a ticket already assigned needs the permission to assign', function () {
    $filer = makeUserWithPermissions('tickets.view', 'tickets.create');
    $agent = User::factory()->agent()->create();

    $this->actingAs($filer)
        ->post('/agent/tickets', [
            'subject' => 'Mail loop on the finance alias',
            'requester_id' => User::factory()->requester()->create()->id,
            'assignee_id' => $agent->id,
        ])
        ->assertSessionHasErrors('assignee_id');

    expect(Ticket::query()->count())->toBe(0);
});

test('filing a ticket without an assignee needs no such permission', function () {
    $filer = makeUserWithPermissions('tickets.view', 'tickets.create');

    $this->actingAs($filer)
        ->post('/agent/tickets', [
            'subject' => 'Mail loop on the finance alias',
            'requester_id' => User::factory()->requester()->create()->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Ticket::query()->sole()->assignee_id)->toBeNull();
});

test('changing the assignee through the ticket form needs it too', function () {
    // `tickets.view.all` as well, or the policy refuses before validation ever
    // runs and the test passes for the wrong reason.
    $editor = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update');
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->create(['team_id' => null, 'queue_id' => null]);

    $this->actingAs($editor)
        ->put("/agent/tickets/{$ticket->key}", ['assignee_id' => $agent->id])
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

test('the create form does not offer a field its reader cannot use', function () {
    // Offering a control and then refusing what it submits is a worse way to
    // express a permission than not drawing it.
    $filer = makeUserWithPermissions('tickets.view', 'tickets.create');
    $assigner = makeUserWithPermissions('tickets.view', 'tickets.create', 'tickets.assign');

    $this->actingAs($filer)
        ->get('/agent/tickets/create')
        ->assertInertia(fn ($page) => $page->where('can.assign', false));

    $this->actingAs($assigner)
        ->get('/agent/tickets/create')
        ->assertInertia(fn ($page) => $page->where('can.assign', true));
});
