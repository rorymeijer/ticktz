<?php

declare(strict_types=1);

use App\Models\KbArticle;
use App\Models\RichTextImage;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedServiceDesk();
    Storage::fake('local');
});

/** Paste an image and get back the URL the editor would put in the text. */
function pasteImage(User $as, bool $internal = false): string
{
    $response = test()->actingAs($as)
        ->postJson('/rich-text/images', [
            'file' => UploadedFile::fake()->image('screenshot.png', 400, 300),
            'internal' => $internal,
        ])
        ->assertCreated();

    return $response->json('url');
}

// -----------------------------------------------------------------
// Uploading
// -----------------------------------------------------------------

it('stores a pasted image outside the public root and hands back its url', function (): void {
    $url = pasteImage(makeAgent());

    $image = RichTextImage::query()->sole();

    expect($url)->toBe('/rich-text/images/'.$image->uuid)
        ->and($image->path)->toStartWith('rich-text/')
        ->and($image->path)->not->toContain('public')
        ->and(Storage::disk('local')->exists($image->path))->toBeTrue();
});

it('refuses a file that is not an image', function (): void {
    $this->actingAs(makeAgent())
        ->postJson('/rich-text/images', ['file' => UploadedFile::fake()->create('payload.php', 10)])
        ->assertStatus(422);

    expect(RichTextImage::query()->count())->toBe(0);
});

/**
 * A `.png` that is not a PNG, and claims `image/png` on the way in.
 *
 * Built as a real file on disk rather than with `UploadedFile::fake()`: the
 * fake answers `getMimeType()` from the name it was given, so a test written
 * against it proves the test double honest rather than the validator. This one
 * puts PHP source in a file called `screenshot.png` and lets the rule read it.
 */
it('refuses a file that only claims to be an image', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'rt').'.png';
    file_put_contents($path, '<?php phpinfo();');

    $this->actingAs(makeAgent())
        ->postJson('/rich-text/images', [
            'file' => new UploadedFile($path, 'screenshot.png', 'image/png', null, true),
        ])
        ->assertStatus(422);

    expect(RichTextImage::query()->count())->toBe(0);

    @unlink($path);
});

it('refuses an upload from somebody not signed in', function (): void {
    $this->postJson('/rich-text/images', ['file' => UploadedFile::fake()->image('a.png')])
        ->assertUnauthorized();
});

// -----------------------------------------------------------------
// Who may see one
// -----------------------------------------------------------------

/**
 * Between the paste and the save an image belongs to nothing. Treating that as
 * public for convenience would make paste-and-abandon the easiest way to host
 * a file on somebody else's server.
 */
it('shows an unsaved image to its uploader and to nobody else', function (): void {
    $agent = makeAgent();
    $url = pasteImage($agent);

    $this->actingAs($agent)->get($url)->assertOk();
    $this->actingAs(makeAgent())->get($url)->assertForbidden();
});

it('shows an image on a ticket to everyone who may read the ticket', function (): void {
    $agent = makeAgent();
    $requester = makeRequester();
    $url = pasteImage($agent);

    $ticket = Ticket::factory()->forRequester($requester)->create([
        'description' => '<p>See this:</p><p><img src="'.$url.'" alt="Screenshot"></p>',
    ]);

    expect(RichTextImage::query()->sole()->owner_id)->toBe($ticket->getKey());

    $this->actingAs($requester->fresh())->get($url)->assertOk();
    $this->actingAs($agent)->get($url)->assertOk();
    $this->actingAs(makeRequester())->get($url)->assertForbidden();
});

/**
 * The whole point of not putting these on a public disk. A screenshot on an
 * internal note must not be readable by the requester, who can read everything
 * around it.
 */
it('hides an image on an internal note from the requester', function (): void {
    $agent = makeAgent();
    $requester = makeRequester();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $url = pasteImage($agent, internal: true);

    app(TicketService::class)->comment(
        $ticket,
        '<p>Not for them:</p><p><img src="'.$url.'" alt="Logs"></p>',
        $agent,
        internal: true,
    );

    $this->actingAs($agent)->get($url)->assertOk();
    $this->actingAs($requester->fresh())->get($url)->assertForbidden();
});

it('shows an image on a public reply to the requester', function (): void {
    $agent = makeAgent();
    $requester = makeRequester();
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $url = pasteImage($agent);

    app(TicketService::class)->comment($ticket, '<p><img src="'.$url.'" alt="Here"></p>', $agent);

    $this->actingAs($requester->fresh())->get($url)->assertOk();
});

it('refuses an image nobody has ever heard of', function (): void {
    $this->actingAs(makeAgent())
        ->get('/rich-text/images/0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->assertNotFound();
});

// -----------------------------------------------------------------
// Binding
// -----------------------------------------------------------------

it('binds an image to the article it was pasted into', function (): void {
    $editor = makeAgent();
    $url = pasteImage($editor);

    $article = KbArticle::factory()->create([
        'body' => '<h2>How to</h2><p><img src="'.$url.'" alt="The dialog"></p>',
    ]);

    expect(RichTextImage::query()->sole()->owner->is($article))->toBeTrue();
});

/**
 * Quoting somebody else's screenshot into a new ticket must not move the image
 * out of the ticket it belongs to and take it away from everyone reading that
 * one.
 */
it('never steals an image that already belongs to something', function (): void {
    $agent = makeAgent();
    $url = pasteImage($agent);

    $first = Ticket::factory()->create(['description' => '<p><img src="'.$url.'" alt=""></p>']);
    $second = Ticket::factory()->create(['description' => '<p>Same picture <img src="'.$url.'" alt=""></p>']);

    expect(RichTextImage::query()->sole()->owner_id)->toBe($first->getKey())
        ->and(RichTextImage::query()->sole()->owner_id)->not->toBe($second->getKey());
});

it('keeps the image when an edit removes it from the text', function (): void {
    $agent = makeAgent();
    $url = pasteImage($agent);

    $ticket = Ticket::factory()->create(['description' => '<p><img src="'.$url.'" alt=""></p>']);
    $ticket->update(['description' => '<p>Never mind.</p>']);

    expect(RichTextImage::query()->count())->toBe(1);
});

// -----------------------------------------------------------------
// Sweeping
// -----------------------------------------------------------------

it('deletes an unsaved image once it is old enough, and its file with it', function (): void {
    $url = pasteImage(makeAgent());
    $image = RichTextImage::query()->sole();

    $this->artisan('ticktz:prune-images')->assertSuccessful();
    expect(RichTextImage::query()->count())->toBe(1);

    $image->forceFill(['created_at' => now()->subDays(8)])->save();

    $this->artisan('ticktz:prune-images')->assertSuccessful();

    expect(RichTextImage::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($image->path))->toBeFalse();
});

it('never deletes an image that belongs to something, however old', function (): void {
    $url = pasteImage(makeAgent());
    Ticket::factory()->create(['description' => '<p><img src="'.$url.'" alt=""></p>']);

    RichTextImage::query()->update(['created_at' => now()->subYear()]);

    $this->artisan('ticktz:prune-images')->assertSuccessful();

    expect(RichTextImage::query()->count())->toBe(1);
});
