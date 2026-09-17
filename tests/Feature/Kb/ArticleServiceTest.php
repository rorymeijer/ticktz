<?php

declare(strict_types=1);

use App\Models\AuditLogEntry;
use App\Models\KbArticle;
use App\Models\KbArticleVersion;
use App\Models\Ticket;
use App\Services\Kb\ArticleService;

beforeEach(function (): void {
    seedServiceDesk();
    $this->service = app(ArticleService::class);
    $this->author = makeAgent();
});

// -----------------------------------------------------------------
// Writing
// -----------------------------------------------------------------

it('sanitises the body on the way in, not on the way out', function (): void {
    $article = $this->service->create([
        'title' => 'Connecting to the VPN',
        'body' => '<p>Open the client.</p><script>alert(1)</script>',
    ], $this->author);

    // What is in the database is what is safe. Nothing downstream has to
    // remember to clean it.
    expect($article->body)->not->toContain('script')
        ->and($article->body)->toContain('Open the client.')
        ->and(KbArticle::query()->whereKey($article->id)->value('body'))->not->toContain('script');
});

it('keeps a flattened copy for searching', function (): void {
    $article = $this->service->create([
        'title' => 'Printer',
        'body' => '<h2>Paper jams</h2><p>Open the <strong>rear</strong> tray.</p>',
    ], $this->author);

    expect($article->body_text)->toBe('Paper jams Open the rear tray.')
        ->and($article->body_text)->not->toContain('<');
});

it('writes an excerpt when the author did not', function (): void {
    $article = $this->service->create([
        'title' => 'Wifi',
        'body' => '<p>'.str_repeat('Connect to the guest network. ', 20).'</p>',
    ], $this->author);

    expect($article->excerpt)->not->toBeEmpty()
        ->and(mb_strlen($article->excerpt))->toBeLessThanOrEqual(500)
        ->and($article->excerpt)->toEndWith('…');
});

it('keeps the excerpt the author wrote', function (): void {
    $article = $this->service->create([
        'title' => 'Wifi',
        'excerpt' => 'How to get on the guest network.',
        'body' => '<p>Long body here.</p>',
    ], $this->author);

    expect($article->excerpt)->toBe('How to get on the guest network.');
});

/**
 * Two articles called "Printer problems" are entirely normal, and the second
 * one should not fail to save because of it.
 */
it('makes a unique slug when the title is taken', function (): void {
    $first = $this->service->create(['title' => 'Printer problems', 'body' => '<p>a</p>'], $this->author);
    $second = $this->service->create(['title' => 'Printer problems', 'body' => '<p>b</p>'], $this->author);

    expect($first->slug)->toBe('printer-problems')
        ->and($second->slug)->toBe('printer-problems-2');
});

it('does not reuse the slug of a deleted article', function (): void {
    $first = $this->service->create(['title' => 'Old news', 'body' => '<p>a</p>'], $this->author);
    $first->delete();

    $second = $this->service->create(['title' => 'Old news', 'body' => '<p>b</p>'], $this->author);

    expect($second->slug)->toBe('old-news-2');
});

it('stamps the publish date once', function (): void {
    $article = $this->service->create([
        'title' => 'Published now',
        'body' => '<p>a</p>',
        'status' => KbArticle::PUBLISHED,
    ], $this->author);

    $stamped = $article->published_at;

    $this->travel(1)->hours();
    $this->service->update($article, ['body' => '<p>edited</p>'], $this->author);

    expect($article->fresh()->published_at->toIso8601String())->toBe($stamped->toIso8601String());
});

it('audits creating and editing', function (): void {
    $article = $this->service->create(['title' => 'Audited', 'body' => '<p>a</p>'], $this->author);
    $this->service->update($article, ['body' => '<p>b</p>'], $this->author);

    expect(AuditLogEntry::query()
        ->where('auditable_type', $article->getMorphClass())
        ->whereIn('event', ['created', 'updated'])
        ->count())->toBe(2);
});

// -----------------------------------------------------------------
// Versioning
// -----------------------------------------------------------------

it('records what the article said before an edit', function (): void {
    $article = $this->service->create([
        'title' => 'First title',
        'body' => '<p>First body.</p>',
    ], $this->author);

    $this->service->update($article, [
        'title' => 'Second title',
        'body' => '<p>Second body.</p>',
    ], $this->author);

    $version = KbArticleVersion::query()->where('kb_article_id', $article->id)->sole();

    // The history holds what was, the article holds what is.
    expect($version->version)->toBe(1)
        ->and($version->title)->toBe('First title')
        ->and($version->body)->toContain('First body.')
        ->and($article->fresh()->version)->toBe(2)
        ->and($article->fresh()->title)->toBe('Second title');
});

it('does not version an edit that leaves the text alone', function (): void {
    $article = $this->service->create(['title' => 'Same', 'body' => '<p>Same.</p>'], $this->author);

    $this->service->update($article, ['visibility' => KbArticle::INTERNAL], $this->author);

    expect(KbArticleVersion::query()->count())->toBe(0)
        ->and($article->fresh()->version)->toBe(1)
        ->and($article->fresh()->visibility)->toBe(KbArticle::INTERNAL);
});

it('keeps the editor note with the version', function (): void {
    $article = $this->service->create(['title' => 'Noted', 'body' => '<p>a</p>'], $this->author);

    $this->service->update($article, [
        'body' => '<p>b</p>',
        'note' => 'Fixed the VPN port',
    ], $this->author);

    expect(KbArticleVersion::query()->sole()->note)->toBe('Fixed the VPN port');
});

it('puts an old version back', function (): void {
    $article = $this->service->create(['title' => 'One', 'body' => '<p>One.</p>'], $this->author);
    $this->service->update($article, ['title' => 'Two', 'body' => '<p>Two.</p>'], $this->author);

    $first = KbArticleVersion::query()->where('version', 1)->sole();

    $this->service->restore($article->fresh(), $first, $this->author);

    expect($article->fresh()->title)->toBe('One')
        ->and($article->fresh()->body)->toContain('One.');
});

/**
 * An editor who restores the wrong version has not lost anything.
 */
it('makes a restore itself undoable', function (): void {
    $article = $this->service->create(['title' => 'One', 'body' => '<p>One.</p>'], $this->author);
    $this->service->update($article, ['title' => 'Two', 'body' => '<p>Two.</p>'], $this->author);

    $first = KbArticleVersion::query()->where('version', 1)->sole();
    $this->service->restore($article->fresh(), $first, $this->author);

    // Version 2 — the text that was current when the restore happened — is now
    // in the history too.
    $versions = KbArticleVersion::query()->where('kb_article_id', $article->id)->pluck('title', 'version');

    expect($versions[1])->toBe('One')
        ->and($versions[2])->toBe('Two')
        ->and($article->fresh()->version)->toBe(3);
});

/**
 * A body stored before the allowlist last changed is not necessarily safe
 * under the allowlist as it stands now.
 */
it('sanitises a restored body again', function (): void {
    $article = $this->service->create(['title' => 'Clean', 'body' => '<p>first</p>'], $this->author);
    $this->service->update($article, ['body' => '<p>second</p>'], $this->author);

    $version = KbArticleVersion::query()->sole();

    // Rewritten in place to hold markup an older, looser allowlist would have
    // let through. Restoring it must not put that back into the article.
    $version->forceFill(['body' => '<p>first</p><script>alert(1)</script>'])->save();

    $this->service->restore($article->fresh(), $version, $this->author);

    expect($article->fresh()->body)->toContain('first')
        ->and($article->fresh()->body)->not->toContain('script');
});

it('audits a restore', function (): void {
    $article = $this->service->create(['title' => 'One', 'body' => '<p>One.</p>'], $this->author);
    $this->service->update($article, ['body' => '<p>Two.</p>'], $this->author);

    $this->service->restore($article->fresh(), KbArticleVersion::query()->sole(), $this->author);

    expect(AuditLogEntry::query()->where('event', 'kb.restored')->exists())->toBeTrue();
});

// -----------------------------------------------------------------
// Publishing
// -----------------------------------------------------------------

it('publishes and unpublishes', function (): void {
    $article = $this->service->create(['title' => 'Draft', 'body' => '<p>a</p>'], $this->author);

    expect($article->status)->toBe(KbArticle::DRAFT);

    $this->service->publish($article, $this->author);
    expect($article->fresh()->status)->toBe(KbArticle::PUBLISHED)
        ->and($article->fresh()->published_at)->not->toBeNull();

    $this->service->unpublish($article->fresh(), $this->author);
    expect($article->fresh()->status)->toBe(KbArticle::DRAFT);
});

// -----------------------------------------------------------------
// Tickets
// -----------------------------------------------------------------

it('links an article to a ticket and records who did it', function (): void {
    $article = KbArticle::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->service->linkToTicket($article, $ticket, $this->author);

    expect($article->tickets()->count())->toBe(1)
        ->and($article->tickets()->first()->pivot->linked_by)->toBe($this->author->id)
        ->and(AuditLogEntry::query()->where('event', 'kb.linked')->exists())->toBeTrue();
});

it('treats linking the same article twice as a double click', function (): void {
    $article = KbArticle::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->service->linkToTicket($article, $ticket, $this->author);
    $this->service->linkToTicket($article, $ticket, $this->author);

    expect($article->tickets()->count())->toBe(1);
});

it('unlinks an article from a ticket', function (): void {
    $article = KbArticle::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->service->linkToTicket($article, $ticket, $this->author);
    $this->service->unlinkFromTicket($article, $ticket, $this->author);

    expect($article->tickets()->count())->toBe(0);
});

// -----------------------------------------------------------------
// Views
// -----------------------------------------------------------------

/**
 * An article does not become newer because somebody read it.
 */
it('counts a read without touching the article timestamp', function (): void {
    $article = KbArticle::factory()->create();
    $before = $article->updated_at;

    $this->travel(1)->hours();
    $this->service->recordView($article);

    $article->refresh();

    expect($article->view_count)->toBe(1)
        ->and($article->updated_at->toIso8601String())->toBe($before->toIso8601String());
});
