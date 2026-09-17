<?php

declare(strict_types=1);

namespace App\Services\Kb;

use App\Models\KbArticle;
use App\Models\KbArticleVersion;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every write an article can undergo.
 *
 * Nothing writes to `kb_articles` outside this class, which is what makes two
 * guarantees hold everywhere rather than at each call site:
 *
 * - **The body is sanitised.** Once, here, on the way in. A controller cannot
 *   forget, and an import or a seeder gets the same treatment as the editor.
 * - **A change is versioned before it lands.** The previous text is written to
 *   the history first, so an edit that goes wrong is one click from being
 *   undone and the history can never be half-written.
 */
class ArticleService
{
    public function __construct(
        private readonly ArticleSanitizer $sanitizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $author = null): KbArticle
    {
        $body = $this->sanitizer->clean((string) ($attributes['body'] ?? ''));
        $text = $this->sanitizer->toText($body);

        $article = KbArticle::query()->create([
            'kb_category_id' => $attributes['kb_category_id'] ?? null,
            'title' => trim((string) $attributes['title']),
            'slug' => $this->uniqueSlug($attributes['slug'] ?? $attributes['title']),
            'excerpt' => $this->resolveExcerpt($attributes['excerpt'] ?? null, $body),
            'body' => $body,
            'body_text' => $text,
            'status' => $attributes['status'] ?? KbArticle::DRAFT,
            'visibility' => $attributes['visibility'] ?? KbArticle::PUBLIC,
            'locale' => $attributes['locale'] ?? null,
            'author_id' => $author?->getKey(),
            'last_edited_by' => $author?->getKey(),
            'position' => $attributes['position'] ?? 100,
            'published_at' => ($attributes['status'] ?? null) === KbArticle::PUBLISHED ? now() : null,
        ]);

        $this->auditAs($author)->created($article, "Created article {$article->title}");

        // Refreshed so the columns the database fills in — `version` and
        // `view_count` — are on the model the caller gets back. Without this a
        // brand-new article has a null version, and the first edit snapshots
        // a version number that does not exist.
        return $article->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(KbArticle $article, array $attributes, ?User $editor = null): KbArticle
    {
        return DB::transaction(function () use ($article, $attributes, $editor): KbArticle {
            $body = array_key_exists('body', $attributes)
                ? $this->sanitizer->clean((string) $attributes['body'])
                : $article->body;

            $changesTheText = $body !== $article->body
                || (isset($attributes['title']) && $attributes['title'] !== $article->title);

            // The version records what the article said *before* this edit, so
            // restoring one is "put back what was there", not "guess".
            if ($changesTheText) {
                $this->snapshot($article, $editor, $attributes['note'] ?? null);
            }

            $fields = Arr::only($attributes, ['kb_category_id', 'title', 'status', 'visibility', 'locale', 'position']);

            if (isset($attributes['slug']) && $attributes['slug'] !== $article->slug) {
                $fields['slug'] = $this->uniqueSlug($attributes['slug'], $article->getKey());
            }

            $fields['body'] = $body;
            $fields['body_text'] = $this->sanitizer->toText($body);
            $fields['excerpt'] = $this->resolveExcerpt($attributes['excerpt'] ?? null, $body);
            $fields['last_edited_by'] = $editor?->getKey();

            // Publishing stamps the date once. Re-saving a published article
            // does not make it newer than it is.
            if (($fields['status'] ?? $article->status) === KbArticle::PUBLISHED && $article->published_at === null) {
                $fields['published_at'] = now();
            }

            $article->fill($fields);

            if ($changesTheText) {
                $article->version = $article->version + 1;
            }

            $article->save();

            $this->auditAs($editor)->updated($article, "Updated article {$article->title}");

            return $article->refresh();
        });
    }

    /**
     * Put an old version back.
     *
     * The current text is snapshotted first, so restoring is itself undoable —
     * an editor who restores the wrong version has not lost anything.
     */
    public function restore(KbArticle $article, KbArticleVersion $version, ?User $editor = null): KbArticle
    {
        return DB::transaction(function () use ($article, $version, $editor): KbArticle {
            $this->snapshot($article, $editor, __('kb.versions.replaced_by_restore', ['version' => $version->version]));

            // Cleaned again rather than trusted: a body stored before the
            // allowlist last changed is not necessarily safe under the
            // allowlist as it stands now.
            $body = $this->sanitizer->clean($version->body);

            $article->forceFill([
                'title' => $version->title,
                'excerpt' => $version->excerpt,
                'body' => $body,
                'body_text' => $this->sanitizer->toText($body),
                'version' => $article->version + 1,
                'last_edited_by' => $editor?->getKey(),
            ])->save();

            $this->auditAs($editor)->log(
                $article,
                'kb.restored',
                "Restored article {$article->title} to version {$version->version}",
                null,
                ['from_version' => $version->version],
            );

            return $article->refresh();
        });
    }

    public function publish(KbArticle $article, ?User $actor = null): KbArticle
    {
        $article->forceFill([
            'status' => KbArticle::PUBLISHED,
            'published_at' => $article->published_at ?? now(),
            'last_edited_by' => $actor?->getKey(),
        ])->save();

        $this->auditAs($actor)->log($article, 'kb.published', "Published article {$article->title}");

        return $article;
    }

    public function unpublish(KbArticle $article, ?User $actor = null): KbArticle
    {
        $article->forceFill([
            'status' => KbArticle::DRAFT,
            'last_edited_by' => $actor?->getKey(),
        ])->save();

        $this->auditAs($actor)->log($article, 'kb.unpublished', "Unpublished article {$article->title}");

        return $article;
    }

    /**
     * Link an article to the ticket it answered.
     *
     * Idempotent: linking the same article twice is a double click, not an
     * error worth showing anybody.
     */
    public function linkToTicket(KbArticle $article, Ticket $ticket, ?User $actor = null): void
    {
        if ($article->tickets()->whereKey($ticket->getKey())->exists()) {
            return;
        }

        $article->tickets()->attach($ticket->getKey(), ['linked_by' => $actor?->getKey()]);

        $this->auditAs($actor)->log(
            $ticket,
            'kb.linked',
            "Linked article {$article->title} to {$ticket->key}",
            null,
            ['kb_article_id' => $article->getKey()],
        );
    }

    public function unlinkFromTicket(KbArticle $article, Ticket $ticket, ?User $actor = null): void
    {
        $article->tickets()->detach($ticket->getKey());

        $this->auditAs($actor)->log(
            $ticket,
            'kb.unlinked',
            "Unlinked article {$article->title} from {$ticket->key}",
            ['kb_article_id' => $article->getKey()],
            null,
        );
    }

    /**
     * Count a read.
     *
     * A bare increment rather than a model save: this runs on every article
     * view, it must not touch `updated_at` — an article does not become newer
     * because somebody read it — and it must not collide with a concurrent
     * edit.
     */
    public function recordView(KbArticle $article): void
    {
        // `toBase()` drops out of Eloquent deliberately. An Eloquent update
        // stamps `updated_at`, and an article does not become newer because
        // somebody read it — the knowledge base would reorder itself by who
        // happened to click what.
        KbArticle::query()->whereKey($article->getKey())->toBase()->update([
            'view_count' => DB::raw('view_count + 1'),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function snapshot(KbArticle $article, ?User $editor, ?string $note): void
    {
        KbArticleVersion::query()->create([
            'kb_article_id' => $article->getKey(),
            'version' => $article->version,
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'body' => $article->body,
            'editor_id' => $editor?->getKey(),
            'note' => $note ? mb_substr($note, 0, 255) : null,
        ]);
    }

    /**
     * An excerpt the author wrote, or one cut from the body.
     */
    private function resolveExcerpt(?string $given, string $body): string
    {
        $given = trim((string) $given);

        return $given !== ''
            ? mb_substr($given, 0, 500)
            : mb_substr($this->sanitizer->excerpt($body), 0, 500);
    }

    /**
     * A slug nobody else is using.
     *
     * Two articles called "Printer problems" are entirely normal, and the
     * second one should not fail to save because of it.
     */
    private function uniqueSlug(string $source, ?int $ignore = null): string
    {
        $base = Str::slug(mb_substr($source, 0, 140)) ?: 'article';
        $slug = $base;
        $suffix = 2;

        while (KbArticle::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function auditAs(?User $actor): AuditLogger
    {
        return $actor ? $this->audit->actingAs($actor) : $this->audit;
    }
}
