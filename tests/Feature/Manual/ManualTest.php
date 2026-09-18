<?php

declare(strict_types=1);

use App\Support\Manual;

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| The manual
|--------------------------------------------------------------------------
|
| Two properties matter more than any individual chapter: that a reader is
| shown the parts of Ticktz they can actually reach, and that the `?` on a
| screen opens the chapter for that screen rather than approximately the right
| one.
*/

it('shows a reader only the chapters they could act on', function (): void {
    // An administrator's manual and an agent's manual are different documents,
    // because a manual that describes doors you cannot open makes people feel
    // locked out rather than helped.
    $agent = makeUserWithPermissions('tickets.view');
    $administrator = makeAdmin();

    $forAgent = collect(
        $this->actingAs($agent)->get('/manual')->assertOk()->viewData('page')['props']['chapters']
    )->pluck('slug');

    $forAdministrator = collect(
        $this->actingAs($administrator)->get('/manual')->assertOk()->viewData('page')['props']['chapters']
    )->pluck('slug');

    expect($forAgent)->toContain('working-a-ticket')
        ->and($forAgent)->not->toContain('admin-automation')
        ->and($forAdministrator)->toContain('admin-automation');
});

it('gives everybody the chapter that needs no permission', function (): void {
    $requester = makeRequester();

    $chapters = collect(
        $this->actingAs($requester)->get('/manual')->assertOk()->viewData('page')['props']['chapters']
    )->pluck('slug');

    expect($chapters)->toContain('getting-started');
});

it('answers 404 for a chapter this reader may not have', function (): void {
    // 404 rather than 403, so the manual does not become a way to enumerate
    // what Ticktz can do.
    $agent = makeUserWithPermissions('tickets.view');

    $this->actingAs($agent)->get('/manual/admin-automation')->assertNotFound();
    $this->actingAs($agent)->get('/manual/no-such-chapter')->assertNotFound();
});

it('renders a chapter as HTML with its own title', function (): void {
    $agent = makeUserWithPermissions('tickets.view');

    $chapter = $this->actingAs($agent)
        ->get('/manual/working-a-ticket')
        ->assertOk()
        ->viewData('page')['props']['chapter'];

    // The title is the chapter's own first heading rather than a second copy
    // kept in the registry, so a table of contents cannot promise something
    // the chapter does not say.
    expect($chapter['title'])->toBe('Working a ticket')
        ->and($chapter['html'])->toContain('<h2');
});

it('follows the reader language', function (): void {
    $agent = makeUserWithPermissions('tickets.view');

    $chapter = $this->actingAs($agent)
        ->get('/manual/working-a-ticket?lang=nl')
        ->assertOk()
        ->viewData('page')['props']['chapter'];

    expect($chapter['title'])->toBe('Een ticket behandelen');
});

/*
|--------------------------------------------------------------------------
| The ? on a screen
|--------------------------------------------------------------------------
*/

it('opens the chapter for the screen somebody is on', function (): void {
    $agent = makeUserWithPermissions('tickets.view');

    $response = $this->actingAs($agent)
        ->getJson('/help?page='.urlencode('Agent/Tickets/Show'))
        ->assertOk();

    expect($response->json('chapter.slug'))->toBe('working-a-ticket');
});

it('admits there is nothing written rather than guessing', function (): void {
    // A `?` that opens approximately the right chapter is worse than one that
    // says there is none.
    $agent = makeUserWithPermissions('tickets.view');

    $this->actingAs($agent)
        ->getJson('/help?page='.urlencode('Auth/ResetPassword'))
        ->assertOk()
        ->assertJsonPath('chapter', null);
});

it('will not hand over a chapter through the panel that it would refuse on its own page', function (): void {
    $agent = makeUserWithPermissions('tickets.view');

    $this->actingAs($agent)
        ->getJson('/help?page='.urlencode('Admin/Automation/Index'))
        ->assertOk()
        ->assertJsonPath('chapter', null);
});

it('is closed to somebody who is not signed in', function (): void {
    $this->get('/manual')->assertRedirect();
    $this->getJson('/help?page=Dashboard')->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| The chapters themselves
|--------------------------------------------------------------------------
*/

it('has every registered chapter, in every language', function (): void {
    // The failure this prevents is the quiet one: a chapter registered and
    // never written shows up as a gap in the table of contents that nobody
    // notices, and a chapter translated into one language and not the other
    // silently serves English to half the desk.
    $missing = [];

    foreach (array_keys(Manual::CHAPTERS) as $slug) {
        foreach (array_keys(config('ticktz.locales')) as $locale) {
            if (! is_file(resource_path("manual/{$locale}/{$slug}.md"))) {
                $missing[] = "{$locale}/{$slug}.md";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('gives every chapter a heading to take its title from', function (): void {
    foreach (array_keys(Manual::CHAPTERS) as $slug) {
        $source = file_get_contents(resource_path("manual/en/{$slug}.md"));

        expect($source)->toStartWith('# ', "The chapter [{$slug}] must open with its title.");
    }
});

it('does not point two chapters at the same screen', function (): void {
    // The `?` takes the first match, so a page listed twice would open
    // whichever chapter happens to be declared first — a coin toss that looks
    // like a decision.
    $seen = [];
    $duplicated = [];

    foreach (Manual::CHAPTERS as $slug => $chapter) {
        foreach ($chapter['pages'] as $page) {
            if (isset($seen[$page])) {
                $duplicated[] = "{$page} ({$seen[$page]} and {$slug})";
            }

            $seen[$page] = $slug;
        }
    }

    expect($duplicated)->toBe([]);
});

it('only names screens that exist', function (): void {
    // A page renamed without updating the registry leaves a `?` that never
    // appears, which is invisible until somebody goes looking for help.
    $missing = [];

    foreach (Manual::coveredPages() as $page) {
        if (! is_file(resource_path("js/Pages/{$page}.tsx"))) {
            $missing[] = $page;
        }
    }

    expect($missing)->toBe([]);
});
