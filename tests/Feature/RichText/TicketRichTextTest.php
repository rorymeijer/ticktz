<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Ticket;
use App\Services\Mail\TicketMailer;
use App\Services\RichText\RichTextBackfill;
use App\Services\Tickets\TicketFilter;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedServiceDesk();
});

// -----------------------------------------------------------------
// Every write path, not just the ones we remembered
// -----------------------------------------------------------------

/**
 * Sanitising lives on the model rather than in the services, because a ticket
 * description is written by an agent form, a portal submission, an API client,
 * an inbound e-mail and an automation rule. Put the sanitiser in each service
 * method and the one added next year is the hole — and the hole is stored XSS.
 */
it('cleans a description however it was written', function (): void {
    $ticket = Ticket::factory()->create([
        'description' => '<p>Before</p><script>alert(document.cookie)</script>',
    ]);

    expect($ticket->fresh()->description)->toContain('Before')
        ->and($ticket->fresh()->description)->not->toContain('script');
});

it('cleans a comment however it was written', function (): void {
    $ticket = Ticket::factory()->create();

    $comment = app(TicketService::class)->comment($ticket, '<p>Hi</p><img src=x onerror=alert(1)>');

    expect($comment->fresh()->body)->toContain('Hi')
        ->and(mb_strtolower((string) $comment->fresh()->body))->not->toContain('onerror');
});

it('accepts plain text and stores it as rich text', function (): void {
    $ticket = Ticket::factory()->create(['description' => "Printer is stuk.\n\nGraag hulp."]);

    expect($ticket->fresh()->description)->toBe('<p>Printer is stuk.</p><p>Graag hulp.</p>');
});

/**
 * The two columns are supposed to be the same content. A search index that
 * disagrees with what is on the screen is worse than no index at all, so they
 * are written in the same pass from the same cleaned value.
 */
it('keeps the flattened text beside the markup', function (): void {
    $ticket = Ticket::factory()->create(['description' => '<p>Printer</p><p>is stuk</p>']);

    expect($ticket->fresh()->description_text)->toBe("Printer\n\nis stuk");
});

it('updates the flattened text when the markup changes', function (): void {
    $ticket = Ticket::factory()->create(['description' => '<p>First</p>']);

    $ticket->update(['description' => '<p>Second</p>']);

    expect($ticket->fresh()->description_text)->toBe('Second');
});

it('stores an empty description as null and an empty comment as an empty string', function (): void {
    $ticket = Ticket::factory()->create(['description' => '<p></p>']);
    $comment = Comment::factory()->for($ticket)->create(['body' => '<p><br></p>']);

    expect($ticket->fresh()->description)->toBeNull()
        ->and($comment->fresh()->body)->toBe('');
});

// -----------------------------------------------------------------
// Search
// -----------------------------------------------------------------

it('finds a word inside a formatted description', function (): void {
    $wanted = Ticket::factory()->create(['description' => '<p>The <strong>printer</strong> jams.</p>']);
    Ticket::factory()->create(['description' => '<p>Something else entirely.</p>']);

    $keys = app(TicketFilter::class)
        ->apply(Ticket::query(), ['search' => 'printer'], makeAgent())
        ->pluck('key');

    expect($keys)->toContain($wanted->key)->toHaveCount(1);
});

/**
 * Searching the markup would make every tag name a term: "code" would find
 * every ticket containing a code block, "strong" would find half the desk.
 */
it('does not match a tag name', function (string $term): void {
    Ticket::factory()->create([
        'subject' => 'Nothing to see',
        'description' => '<p>A <strong>bold</strong> claim.</p><blockquote>Quoted</blockquote>',
    ]);

    $found = app(TicketFilter::class)
        ->apply(Ticket::query(), ['search' => $term], makeAgent())
        ->count();

    expect($found)->toBe(0);
})->with(['strong', 'blockquote']);

// -----------------------------------------------------------------
// E-mail
// -----------------------------------------------------------------

/**
 * A formatted reply has to arrive formatted, and the text part has to read as
 * something a person wrote rather than as markup with the tags pulled out.
 */
it('sends the formatting in the html part and the words in the text part', function (): void {
    $requester = makeRequester(['locale' => 'en']);
    $ticket = Ticket::factory()->forRequester($requester)->create([
        'description' => '<p>The printer jams on <strong>thick</strong> paper.</p><ul><li>Tray 2</li></ul>',
    ]);

    [, $html, $text] = app(TicketMailer::class)
        ->render('ticket.created.requester', $ticket->fresh(), $requester, null, null, 'en');

    expect($html)->toContain('<strong>thick</strong>')
        ->and($html)->toContain('<li>Tray 2</li>')
        // The description sits on its own line in the template, so it is
        // replaced as a block rather than nested inside a paragraph.
        ->and($html)->not->toContain('<p><p>');

    expect($text)->toContain('thick paper')
        ->and($text)->not->toContain('<strong>')
        ->and($text)->not->toContain('<li>');
});

it('escapes the template around it so a name cannot become markup', function (): void {
    $requester = makeRequester(['name' => 'Ada <script>alert(1)</script>', 'locale' => 'en']);
    $ticket = Ticket::factory()->forRequester($requester)->create(['description' => '<p>Hello</p>']);

    [, $html] = app(TicketMailer::class)
        ->render('ticket.created.agent', $ticket->fresh(), $requester, null, null, 'en');

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

// -----------------------------------------------------------------
// The upgrade
// -----------------------------------------------------------------

/**
 * The backfill runs once, on somebody else's data, with no way to check the
 * result afterwards. So it is checked here.
 */
it('wraps existing plain text without interpreting it', function (): void {
    $ticket = Ticket::factory()->create();

    // Straight to the table: this is what the column held before the upgrade,
    // so it must not go through the model's own cleaning on the way in.
    DB::table('tickets')->where('id', $ticket->id)->update([
        'description' => "It fails when a < b.\nEvery time.\n\nSee <3 attached.",
        'description_text' => null,
    ]);

    app(RichTextBackfill::class)->convert('tickets', 'description', 'description_text');

    $fresh = $ticket->fresh();

    expect($fresh->description)->toBe(
        '<p>It fails when a &lt; b.<br>Every time.</p><p>See &lt;3 attached.</p>',
    )->and($fresh->description_text)->toBe("It fails when a < b.\nEvery time.\n\nSee <3 attached.");
});

it('leaves an empty column alone rather than writing an empty string over a null', function (): void {
    $ticket = Ticket::factory()->create();

    DB::table('tickets')->where('id', $ticket->id)->update(['description' => null]);

    app(RichTextBackfill::class)->convert('tickets', 'description', 'description_text');

    expect($ticket->fresh()->description)->toBeNull();
});

it('puts the words back on a rollback', function (): void {
    $ticket = Ticket::factory()->create();

    DB::table('tickets')->where('id', $ticket->id)->update([
        'description' => '<p>First line.<br>Second line.</p><p>New paragraph.</p>',
    ]);

    app(RichTextBackfill::class)->flatten('tickets', 'description');

    expect($ticket->fresh()->description)->toBe("First line.\nSecond line.\n\nNew paragraph.");
});

// -----------------------------------------------------------------
// Validation
// -----------------------------------------------------------------

/**
 * `required` passes on `<p></p>`, which is what an editor that has been
 * clicked into and left behind posts. Without the rule, "send reply" on an
 * empty box files an empty comment and mails it to the customer.
 */
it('refuses a reply that only looks filled in', function (string $body): void {
    $agent = makeAgent();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/comments", ['body' => $body])
        ->assertSessionHasErrors('body');

    expect($ticket->comments()->count())->toBe(0);
})->with([
    'an empty paragraph' => '<p></p>',
    'a paragraph with a break' => '<p><br></p>',
    'a non-breaking space' => '<p>&nbsp;</p>',
    'markup that sanitises to nothing' => '<script>alert(1)</script>',
]);

it('accepts a reply with words in it', function (): void {
    $agent = makeAgent();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/comments", ['body' => '<p>On its way.</p>'])
        ->assertSessionHasNoErrors();

    expect($ticket->comments()->count())->toBe(1);
});
