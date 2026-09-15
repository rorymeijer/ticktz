<?php

declare(strict_types=1);

use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Ticket;

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| The portal
|--------------------------------------------------------------------------
|
| The acceptance criterion from the brief: a requester finds and reads a public
| article; an agent links an article to a ticket; internal articles stay hidden
| on the portal.
|
*/

it('lets a requester find and read a public article', function (): void {
    $requester = makeRequester();
    $article = KbArticle::factory()->about('Connecting to the VPN', 'Open the client and sign in.')->create();

    $index = $this->actingAs($requester)->get('/portal/kb?q=VPN')->assertOk();

    expect(collect($index->viewData('page')['props']['articles']['data'])->pluck('slug'))
        ->toContain($article->slug);

    $show = $this->actingAs($requester)->get("/portal/kb/{$article->slug}")->assertOk();

    expect($show->viewData('page')['props']['article']['body'])->toContain('Open the client');
});

/**
 * The failure this feature must not have.
 */
it('hides an internal article from the portal list', function (): void {
    $requester = makeRequester();
    KbArticle::factory()->internal()->about('Root password rotation', 'The vault is at 10.0.0.9.')->create();
    $public = KbArticle::factory()->about('Wifi', 'Use the guest network.')->create();

    $response = $this->actingAs($requester)->get('/portal/kb')->assertOk();

    $slugs = collect($response->viewData('page')['props']['articles']['data'])->pluck('slug');

    expect($slugs)->toContain($public->slug)
        ->and($slugs)->not->toContain('root-password-rotation');
});

it('hides an internal article from portal search, whatever is searched for', function (): void {
    $requester = makeRequester();
    KbArticle::factory()->internal()->about('Root password rotation', 'The vault is at 10.0.0.9.')->create();

    $response = $this->actingAs($requester)->get('/portal/kb?q=vault')->assertOk();

    expect($response->viewData('page')['props']['articles']['data'])->toBe([]);
});

/**
 * 404 rather than 403: the existence of an internal article is itself
 * information.
 */
it('answers 404 for an internal article reached by its url', function (): void {
    $requester = makeRequester();
    $article = KbArticle::factory()->internal()->create();

    $this->actingAs($requester)->get("/portal/kb/{$article->slug}")->assertNotFound();
});

it('hides a draft and an archived article from the portal', function (string $state): void {
    $requester = makeRequester();
    $article = KbArticle::factory()->{$state}()->create();

    $this->actingAs($requester)->get("/portal/kb/{$article->slug}")->assertNotFound();
})->with(['draft', 'archived']);

/**
 * An article marked public inside a category marked internal is a mistake
 * somebody will make, and the portal is the wrong place to find out.
 */
it('hides a public article that sits in an internal category', function (): void {
    $requester = makeRequester();
    $category = KbCategory::factory()->internal()->create();
    $article = KbArticle::factory()->for($category, 'category')->create();

    $this->actingAs($requester)->get("/portal/kb/{$article->slug}")->assertNotFound();
});

it('hides an article in a category that has been switched off', function (): void {
    $requester = makeRequester();
    $category = KbCategory::factory()->inactive()->create();
    $article = KbArticle::factory()->for($category, 'category')->create();

    $this->actingAs($requester)->get("/portal/kb/{$article->slug}")->assertNotFound();
});

it('shows an article written for the reader language and the ones for everyone', function (): void {
    $requester = makeRequester(['locale' => 'nl']);

    $dutch = KbArticle::factory()->inLocale('nl')->create();
    $english = KbArticle::factory()->inLocale('en')->create();
    $any = KbArticle::factory()->create();

    $response = $this->actingAs($requester)->get('/portal/kb')->assertOk();
    $slugs = collect($response->viewData('page')['props']['articles']['data'])->pluck('slug');

    expect($slugs)->toContain($dutch->slug)
        ->and($slugs)->toContain($any->slug)
        ->and($slugs)->not->toContain($english->slug);
});

it('leaves empty categories off the portal', function (): void {
    $requester = makeRequester();
    $empty = KbCategory::factory()->create();
    $filled = KbCategory::factory()->create();
    KbArticle::factory()->for($filled, 'category')->create();

    $response = $this->actingAs($requester)->get('/portal/kb')->assertOk();
    $slugs = collect($response->viewData('page')['props']['categories'])->pluck('slug');

    expect($slugs)->toContain($filled->slug)->and($slugs)->not->toContain($empty->slug);
});

// -----------------------------------------------------------------
// Suggestions on the request form
// -----------------------------------------------------------------

it('suggests public articles as a requester types', function (): void {
    $requester = makeRequester();
    KbArticle::factory()->about('Printer paper jam', 'Open the rear tray.')->create();

    $response = $this->actingAs($requester)->getJson('/portal/kb/suggest?q=printer')->assertOk();

    expect($response->json('articles'))->toHaveCount(1)
        ->and($response->json('articles.0.title'))->toBe('Printer paper jam');
});

it('never suggests an internal article', function (): void {
    $requester = makeRequester();
    KbArticle::factory()->internal()->about('Printer admin console', 'Log in at 10.0.0.5.')->create();

    expect($this->actingAs($requester)->getJson('/portal/kb/suggest?q=printer')->json('articles'))->toBe([]);
});

/**
 * Two characters match half the knowledge base and help nobody.
 */
it('says nothing for a term too short to mean anything', function (): void {
    $requester = makeRequester();
    KbArticle::factory()->about('Printer', 'Body.')->create();

    expect($this->actingAs($requester)->getJson('/portal/kb/suggest?q=pr')->json('articles'))->toBe([]);
});

// -----------------------------------------------------------------
// The agent view
// -----------------------------------------------------------------

it('shows an internal article to an agent who may read internal ones', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'kb.view', 'kb.view.internal');
    $article = KbArticle::factory()->internal()->create();

    $this->actingAs($agent)->get("/agent/kb/{$article->slug}")->assertOk();
});

it('hides an internal article from an agent who may not', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'kb.view');
    $article = KbArticle::factory()->internal()->create();

    $this->actingAs($agent)->get("/agent/kb/{$article->slug}")->assertNotFound();
});

it('hides a draft from an agent who cannot edit the knowledge base', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'kb.view', 'kb.view.internal');
    $article = KbArticle::factory()->draft()->create();

    $this->actingAs($agent)->get("/agent/kb/{$article->slug}")->assertNotFound();
});

it('shows a draft to an editor', function (): void {
    $editor = makeUserWithPermissions('tickets.view', 'kb.view', 'kb.manage');
    $article = KbArticle::factory()->draft()->create();

    $this->actingAs($editor)->get("/agent/kb/{$article->slug}")->assertOk();
});

it('refuses the agent knowledge base without the permission', function (): void {
    $agent = makeUserWithPermissions('tickets.view');

    $this->actingAs($agent)->get('/agent/kb')->assertForbidden();
});

// -----------------------------------------------------------------
// Linking to a ticket
// -----------------------------------------------------------------

it('lets an agent link an article to a ticket', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'kb.view');
    $article = KbArticle::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/kb", ['kb_article_id' => $article->id])
        ->assertRedirect();

    expect($ticket->fresh()->kbArticles()->count())->toBe(1);
});

/**
 * An agent who cannot read an internal article cannot attach one either, and
 * guessing an id must not be a way around that.
 */
it('refuses to link an article the agent cannot read', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'kb.view');
    $article = KbArticle::factory()->internal()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/kb", ['kb_article_id' => $article->id])
        ->assertNotFound();

    expect($ticket->fresh()->kbArticles()->count())->toBe(0);
});

it('refuses to link without permission to update the ticket', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'kb.view');
    $article = KbArticle::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/kb", ['kb_article_id' => $article->id])
        ->assertForbidden();
});

it('unlinks an article from a ticket', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'kb.view');
    $article = KbArticle::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->post("/agent/tickets/{$ticket->key}/kb", ['kb_article_id' => $article->id]);
    $this->actingAs($agent)->delete("/agent/tickets/{$ticket->key}/kb/{$article->slug}")->assertRedirect();

    expect($ticket->fresh()->kbArticles()->count())->toBe(0);
});

it('suggests articles for a ticket from its own subject', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'kb.view');
    KbArticle::factory()->about('Printer paper jam', 'Open the rear tray.')->create();

    $ticket = Ticket::factory()->create(['subject' => 'Printer keeps jamming']);

    $response = $this->actingAs($agent)->getJson("/agent/tickets/{$ticket->key}/kb/suggest")->assertOk();

    expect($response->json('articles.0.title'))->toBe('Printer paper jam');
});

it('does not suggest an article already linked to the ticket', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'kb.view');
    $article = KbArticle::factory()->about('Printer paper jam', 'Open the rear tray.')->create();
    $ticket = Ticket::factory()->create(['subject' => 'Printer keeps jamming']);

    $this->actingAs($agent)->post("/agent/tickets/{$ticket->key}/kb", ['kb_article_id' => $article->id]);

    $response = $this->actingAs($agent)->getJson("/agent/tickets/{$ticket->key}/kb/suggest")->assertOk();

    expect($response->json('articles'))->toBe([]);
});

// -----------------------------------------------------------------
// The panel on the ticket page
// -----------------------------------------------------------------

it('lists the linked articles on the ticket page', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'kb.view');
    $article = KbArticle::factory()->about('Printer paper jam', 'Open the rear tray.')->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->post("/agent/tickets/{$ticket->key}/kb", ['kb_article_id' => $article->id]);

    $response = $this->actingAs($agent)->get("/agent/tickets/{$ticket->key}")->assertOk();

    expect(collect($response->viewData('page')['props']['kbArticles'])->pluck('title'))
        ->toContain('Printer paper jam');
});

/**
 * An article can be linked while it is public and made internal afterwards.
 * The link is history and stays, but the agent reading the ticket must still
 * be held to what they may read today.
 */
it('hides a linked article that has since become internal', function (): void {
    $editor = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'kb.view', 'kb.view.internal');
    $article = KbArticle::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($editor)->post("/agent/tickets/{$ticket->key}/kb", ['kb_article_id' => $article->id]);

    $article->forceFill(['visibility' => KbArticle::INTERNAL])->save();

    $outsider = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'kb.view');

    $response = $this->actingAs($outsider)->get("/agent/tickets/{$ticket->key}")->assertOk();

    expect($response->viewData('page')['props']['kbArticles'])->toBe([]);

    // And is still there for somebody who may read it.
    $response = $this->actingAs($editor)->get("/agent/tickets/{$ticket->key}")->assertOk();

    expect($response->viewData('page')['props']['kbArticles'])->toHaveCount(1);
});

it('does not offer the knowledge base panel to an agent without the permission', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update');
    $ticket = Ticket::factory()->create();

    $response = $this->actingAs($agent)->get("/agent/tickets/{$ticket->key}")->assertOk();

    expect($response->viewData('page')['props']['can']['kb'])->toBeFalse();
});
