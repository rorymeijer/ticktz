<?php

declare(strict_types=1);

use App\Models\AutomationRule;
use App\Models\Comment;
use App\Models\Organization;
use App\Models\ReplyTemplate;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\Automation\ActionRunner;
use App\Services\Tickets\TicketPlaceholders;
use Database\Seeders\ReplyTemplateSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();
});

/*
|--------------------------------------------------------------------------
| The renderer both halves share
|--------------------------------------------------------------------------
*/

it('fills the placeholders from the ticket', function (): void {
    $organization = Organization::factory()->create(['name' => 'Northwind']);
    $requester = makeRequester(['name' => 'Jan de Vries', 'organization_id' => $organization->getKey()]);
    $agent = makeAgent(['name' => 'Ada Lovelace']);

    $ticket = Ticket::factory()->create([
        'subject' => 'The badge reader is dead',
        'requester_id' => $requester->getKey(),
        'assignee_id' => $agent->getKey(),
    ]);

    $rendered = app(TicketPlaceholders::class)->render(
        'Hi {{ requester.first_name }} at {{ requester.organization }}, '
        .'{{ ticket.key }} ({{ ticket.subject }}) is with {{ assignee.name }}. — {{ agent.first_name }}',
        $ticket,
        $agent,
    );

    expect($rendered)->toBe(
        "Hi Jan at Northwind, {$ticket->key} (The badge reader is dead) is with Ada Lovelace. — Ada",
    );
});

/**
 * The failure this guards against is a customer reading `{{ requester.naem }}`
 * and learning that the desk types its apologies into a machine.
 */
it('renders an unknown placeholder as nothing rather than echoing it', function (): void {
    $ticket = Ticket::factory()->create();

    expect(app(TicketPlaceholders::class)->render('Hi {{ requester.naem }}!', $ticket))->toBe('Hi !');
});

it('escapes the values it substitutes into a template body', function (): void {
    $ticket = Ticket::factory()->create([
        'requester_id' => makeRequester(['name' => '<script>alert(1)</script>'])->getKey(),
    ]);

    $rendered = app(TicketPlaceholders::class)->render(
        '<p>Hi {{ requester.name }}</p>',
        $ticket,
        null,
        escape: true,
    );

    expect($rendered)->not->toContain('<script>')
        ->and($rendered)->toContain('&lt;script&gt;');
});

/*
|--------------------------------------------------------------------------
| Half one: an agent picks one
|--------------------------------------------------------------------------
*/

it('offers an agent the templates for this ticket, already filled in', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.comment');
    $requester = makeRequester(['name' => 'Jan de Vries', 'locale' => 'nl']);
    $ticket = Ticket::factory()->create(['requester_id' => $requester->getKey()]);

    ReplyTemplate::factory()->create([
        'name' => 'Acknowledge',
        'body' => '<p>Hallo {{ requester.first_name }}, we kijken naar {{ ticket.key }}.</p>',
    ]);

    $response = $this->actingAs($agent)
        ->getJson("/agent/tickets/{$ticket->key}/reply-templates")
        ->assertOk();

    expect($response->json('templates'))->toHaveCount(1)
        ->and($response->json('templates.0.name'))->toBe('Acknowledge')
        ->and($response->json('templates.0.body'))
        ->toBe("<p>Hallo Jan, we kijken naar {$ticket->key}.</p>");
});

it('does not offer an internal template to an agent who may only reply', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.comment');
    $ticket = Ticket::factory()->create();

    ReplyTemplate::factory()->create(['name' => 'A public reply']);
    ReplyTemplate::factory()->internal()->create(['name' => 'Waiting on the supplier']);

    $names = collect(
        $this->actingAs($agent)
            ->getJson("/agent/tickets/{$ticket->key}/reply-templates")
            ->assertOk()
            ->json('templates'),
    )->pluck('name');

    expect($names)->toContain('A public reply')
        ->and($names)->not->toContain('Waiting on the supplier');
});

it('only offers a team template to that team', function (): void {
    $theirs = Team::factory()->create();
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.comment');
    $ticket = Ticket::factory()->create();

    ReplyTemplate::factory()->create(['name' => 'Everybody', 'team_id' => null]);
    ReplyTemplate::factory()->create(['name' => 'Finance only', 'team_id' => $theirs->getKey()]);

    $names = collect(
        $this->actingAs($agent)
            ->getJson("/agent/tickets/{$ticket->key}/reply-templates")
            ->assertOk()
            ->json('templates'),
    )->pluck('name');

    expect($names)->toContain('Everybody')
        ->and($names)->not->toContain('Finance only');
});

/**
 * The requester's language decides, not the agent's. A Dutch agent answering
 * an English customer needs the English wording, and offering them the Dutch
 * one because Ticktz is in Dutch for *them* would be offering the one thing
 * they must not send.
 */
it('offers the template written for the language the requester reads', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.comment');
    $agent->forceFill(['locale' => 'nl'])->save();

    $ticket = Ticket::factory()->create([
        'requester_id' => makeRequester(['locale' => 'en'])->getKey(),
    ]);

    ReplyTemplate::factory()->create(['name' => 'English wording', 'locale' => 'en']);
    ReplyTemplate::factory()->create(['name' => 'Nederlandse tekst', 'locale' => 'nl']);
    ReplyTemplate::factory()->create(['name' => 'Any language', 'locale' => null]);

    $names = collect(
        $this->actingAs($agent)
            ->getJson("/agent/tickets/{$ticket->key}/reply-templates")
            ->assertOk()
            ->json('templates'),
    )->pluck('name');

    expect($names)->toContain('English wording')
        ->and($names)->toContain('Any language')
        ->and($names)->not->toContain('Nederlandse tekst');
});

it('refuses the list to somebody who may not comment at all', function (): void {
    $reader = makeUserWithPermissions('tickets.view', 'tickets.view.all');
    $ticket = Ticket::factory()->create();

    ReplyTemplate::factory()->create();

    $this->actingAs($reader)
        ->getJson("/agent/tickets/{$ticket->key}/reply-templates")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Half two: a rule sends one
|--------------------------------------------------------------------------
*/

it('sends a reply template as an automation action', function (): void {
    $ticket = Ticket::factory()->create([
        'requester_id' => makeRequester(['name' => 'Jan de Vries'])->getKey(),
    ]);

    $template = ReplyTemplate::factory()->create([
        'body' => '<p>Hi {{ requester.first_name }}, {{ ticket.key }} is with us.</p>',
    ]);

    $rule = AutomationRule::factory()->create([
        'actions' => [['type' => AutomationRule::ACTION_REPLY_TEMPLATE, 'template_id' => $template->getKey()]],
    ]);

    $results = app(ActionRunner::class)->run($rule, $ticket);

    expect($results[0]['ok'])->toBeTrue();

    $comment = Comment::query()->where('ticket_id', $ticket->getKey())->sole();

    expect($comment->body)->toContain('Hi Jan')
        ->and($comment->body)->toContain($ticket->key)
        ->and($comment->is_internal)->toBeFalse()
        ->and($comment->source)->toBe('automation')
        // No author: an automated reply is the desk talking, not whichever
        // agent happened to be named in the rule.
        ->and($comment->user_id)->toBeNull();
});

/**
 * The flag lives on the template rather than on the rule, so a note saying
 * "escalated to the supplier, do not tell them yet" cannot be turned into a
 * customer reply by an administrator ticking a box three screens away.
 */
it('keeps an internal template internal when a rule sends it', function (): void {
    $ticket = Ticket::factory()->create();
    $template = ReplyTemplate::factory()->internal()->create();

    $rule = AutomationRule::factory()->create([
        'actions' => [['type' => AutomationRule::ACTION_REPLY_TEMPLATE, 'template_id' => $template->getKey()]],
    ]);

    app(ActionRunner::class)->run($rule, $ticket);

    expect(Comment::query()->where('ticket_id', $ticket->getKey())->sole()->is_internal)->toBeTrue();
});

it('reports rather than sends when the template is gone or switched off', function (): void {
    $ticket = Ticket::factory()->create();
    $template = ReplyTemplate::factory()->create(['is_active' => false]);

    $rule = AutomationRule::factory()->create([
        'actions' => [['type' => AutomationRule::ACTION_REPLY_TEMPLATE, 'template_id' => $template->getKey()]],
    ]);

    $results = app(ActionRunner::class)->run($rule, $ticket);

    expect($results[0]['ok'])->toBeFalse()
        ->and($results[0]['detail'])->toContain('no such template')
        ->and(Comment::query()->where('ticket_id', $ticket->getKey())->count())->toBe(0);
});

/**
 * A template can move to another team after a rule was wired up to it. When it
 * does the rule stops, rather than quietly sending one team's wording to
 * another team's customer.
 */
it('will not send another team\'s template', function (): void {
    $theirs = Team::factory()->create();
    $ticket = Ticket::factory()->create(['team_id' => null]);
    $template = ReplyTemplate::factory()->create(['team_id' => $theirs->getKey()]);

    $rule = AutomationRule::factory()->create([
        'actions' => [['type' => AutomationRule::ACTION_REPLY_TEMPLATE, 'template_id' => $template->getKey()]],
    ]);

    $results = app(ActionRunner::class)->run($rule, $ticket);

    expect($results[0]['ok'])->toBeFalse()
        ->and(Comment::query()->where('ticket_id', $ticket->getKey())->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Both halves read one list
|--------------------------------------------------------------------------
*/

/**
 * The point of the feature: the words an agent inserts and the words a rule
 * sends come from the same row. If these two ever differ, the desk has two
 * versions of the same apology and the robot is sending the stale one.
 */
it('gives the agent and the rule the same words', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.comment');
    $ticket = Ticket::factory()->create([
        'requester_id' => makeRequester(['name' => 'Jan de Vries'])->getKey(),
    ]);

    $template = ReplyTemplate::factory()->create([
        'body' => '<p>Hi {{ requester.first_name }}, we have {{ ticket.key }}.</p>',
    ]);

    $offered = $this->actingAs($agent)
        ->getJson("/agent/tickets/{$ticket->key}/reply-templates")
        ->assertOk()
        ->json('templates.0.body');

    $rule = AutomationRule::factory()->create([
        'actions' => [['type' => AutomationRule::ACTION_REPLY_TEMPLATE, 'template_id' => $template->getKey()]],
    ]);

    app(ActionRunner::class)->run($rule, $ticket);

    $sent = Comment::query()->where('ticket_id', $ticket->getKey())->sole()->body;

    expect($sent)->toBe($offered);
});

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
*/

it('lets somebody with templates.manage write one', function (): void {
    $manager = makeUserWithPermissions('templates.manage');

    $this->actingAs($manager)->post('/admin/reply-templates', [
        'name' => 'We have your request',
        'slug' => 'acknowledge',
        'body' => '<p>Hi {{ requester.first_name }}.</p>',
        'is_internal' => false,
        'is_active' => true,
        'position' => 10,
    ])->assertRedirect();

    expect(ReplyTemplate::query()->where('slug', 'acknowledge')->exists())->toBeTrue();
});

it('refuses the admin screen to somebody without templates.manage', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.comment');

    $this->actingAs($agent)->get('/admin/reply-templates')->assertForbidden();

    $this->actingAs($agent)->post('/admin/reply-templates', [
        'name' => 'Mine now',
        'slug' => 'mine-now',
        'body' => '<p>Hello.</p>',
    ])->assertForbidden();

    expect(ReplyTemplate::query()->count())->toBe(0);
});

/**
 * A template body is stored HTML, and the sanitiser is what makes it safe to
 * render into another person's browser. Without this the admin screen is a
 * stored-XSS form with a rich text editor attached.
 */
it('sanitises a template body on the way in', function (): void {
    $manager = makeUserWithPermissions('templates.manage');

    $this->actingAs($manager)->post('/admin/reply-templates', [
        'name' => 'Nasty',
        'slug' => 'nasty',
        'body' => '<p>Hello<script>alert(1)</script></p>',
        'is_active' => true,
    ])->assertRedirect();

    $body = ReplyTemplate::query()->where('slug', 'nasty')->sole()->body;

    expect($body)->not->toContain('<script>')
        ->and($body)->toContain('Hello');
});

it('keeps a flattened copy of the body for anything that cannot read markup', function (): void {
    $template = ReplyTemplate::factory()->create(['body' => '<p>Hello <strong>there</strong></p>']);

    expect($template->refresh()->body_text)->toBe('Hello there');
});

it('names the person who wrote a template', function (): void {
    $manager = makeUserWithPermissions('templates.manage');

    $this->actingAs($manager)->post('/admin/reply-templates', [
        'name' => 'Mine',
        'slug' => 'mine',
        'body' => '<p>Hello.</p>',
        'is_active' => true,
    ])->assertRedirect();

    expect(ReplyTemplate::query()->where('slug', 'mine')->sole()->created_by)
        ->toBe($manager->getKey());
});

it('offers the automation form only the templates a rule could actually send', function (): void {
    $admin = makeAdmin();

    ReplyTemplate::factory()->create(['name' => 'Live one']);
    ReplyTemplate::factory()->create(['name' => 'Switched off', 'is_active' => false]);

    $response = $this->actingAs($admin)->get('/admin/automation')->assertOk();

    $names = collect($response->viewData('page')['props']['options']['reply_templates'])->pluck('name');

    expect($names)->toContain('Live one')
        ->and($names)->not->toContain('Switched off');
});

it('lists the reply template action in the automation vocabulary', function (): void {
    expect(AutomationRule::ACTIONS)->toContain(AutomationRule::ACTION_REPLY_TEMPLATE);
});

it('seeds a working set of templates in both languages', function (): void {
    app(ReplyTemplateSeeder::class)->run();
    $before = ReplyTemplate::query()->count();

    expect($before)->toBeGreaterThan(0)
        ->and(ReplyTemplate::query()->where('locale', 'nl')->count())->toBeGreaterThan(0)
        ->and(ReplyTemplate::query()->where('locale', 'en')->count())->toBeGreaterThan(0)
        // At least one internal note, which is the worked example of why the
        // flag exists.
        ->and(ReplyTemplate::query()->where('is_internal', true)->count())->toBeGreaterThan(0);

    // Idempotent, and it does not undo an edit the desk has made.
    $first = ReplyTemplate::query()->orderBy('id')->first();
    $first->forceFill(['name' => 'Edited by the desk'])->save();

    app(ReplyTemplateSeeder::class)->run();

    expect(ReplyTemplate::query()->count())->toBe($before)
        ->and($first->refresh()->name)->toBe('Edited by the desk');
});

it('does not let one desk read another template than it may use', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.comment');
    $ticket = Ticket::factory()->create();

    ReplyTemplate::factory()->create(['name' => 'Switched off', 'is_active' => false]);

    expect(
        $this->actingAs($agent)
            ->getJson("/agent/tickets/{$ticket->key}/reply-templates")
            ->assertOk()
            ->json('templates'),
    )->toBe([]);
});

it('lets an agent send a template as an ordinary reply', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.comment');
    $ticket = Ticket::factory()->create([
        'requester_id' => makeRequester(['name' => 'Jan de Vries'])->getKey(),
    ]);

    ReplyTemplate::factory()->create([
        'body' => '<p>Hi {{ requester.first_name }}, we have {{ ticket.key }}.</p>',
    ]);

    $body = $this->actingAs($agent)
        ->getJson("/agent/tickets/{$ticket->key}/reply-templates")
        ->json('templates.0.body');

    // Nothing special happens on the way back in: an inserted template is the
    // agent's own words by the time they press send, and it goes through the
    // same endpoint, the same policy and the same sanitiser as anything else
    // they type. That is the point — there is no second write path to secure.
    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/comments", ['body' => $body, 'is_internal' => false])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $comment = Comment::query()->where('ticket_id', $ticket->getKey())->sole();

    expect($comment->user_id)->toBe($agent->getKey())
        ->and($comment->is_internal)->toBeFalse()
        ->and($comment->body)->toContain('Jan');
});
