<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\KbArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something the desk knows, written down once.
 *
 * `body` is sanitised HTML — cleaned on the way in, so anywhere it is rendered
 * it is already safe. `body_text` is the same content flattened, and it is what
 * search reads: a query never matches a tag name, an attribute value or a URL
 * the author did not mean to be findable.
 *
 * Visibility is the property that matters. An internal article on the portal is
 * the failure this feature must not have, so the two audiences have separate
 * scopes rather than one scope with a flag — see {@see scopeVisibleOnPortal()}
 * and {@see scopeVisibleToAgent()}.
 *
 * @property string $body sanitised HTML
 * @property string $body_text the same content, flattened
 */
class KbArticle extends Model
{
    /** @use HasFactory<KbArticleFactory> */
    use Auditable, HasFactory, SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    public const PUBLIC = 'public';

    public const INTERNAL = 'internal';

    /** @var array<int, string> */
    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::ARCHIVED];

    /** @var array<int, string> */
    public const VISIBILITIES = [self::PUBLIC, self::INTERNAL];

    protected $fillable = [
        'kb_category_id', 'title', 'slug', 'excerpt', 'body', 'body_text',
        'status', 'visibility', 'locale', 'author_id', 'last_edited_by',
        'published_at', 'position',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'view_count' => 'integer',
            'version' => 'integer',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<KbCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'kb_category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    /** @return HasMany<KbArticleVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(KbArticleVersion::class)->orderByDesc('version');
    }

    /** @return BelongsToMany<Ticket, $this> */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'kb_article_ticket')
            ->withPivot('linked_by')
            ->withTimestamps();
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function isInternal(): bool
    {
        return $this->visibility === self::INTERNAL;
    }

    // -----------------------------------------------------------------
    // Who can see what
    // -----------------------------------------------------------------

    /**
     * What a requester may read: published, public, in a public category, and
     * in a language they read.
     *
     * The category check is not redundant. An article marked public inside a
     * category marked internal is a mistake somebody will make, and the portal
     * is the wrong place to find out.
     *
     * @param  Builder<KbArticle>  $query
     * @return Builder<KbArticle>
     */
    public function scopeVisibleOnPortal(Builder $query, ?string $locale = null): Builder
    {
        $locale ??= app()->getLocale();

        return $query
            ->where('kb_articles.status', self::PUBLISHED)
            ->where('kb_articles.visibility', self::PUBLIC)
            ->where(fn (Builder $scoped) => $scoped
                ->whereNull('kb_articles.kb_category_id')
                ->orWhereHas('category', fn (Builder $category) => $category
                    ->where('is_active', true)
                    ->where('visibility', KbCategory::PUBLIC)))
            // NULL means "any language"; anything else has to match the reader.
            ->where(fn (Builder $scoped) => $scoped
                ->whereNull('kb_articles.locale')
                ->orWhere('kb_articles.locale', $locale));
    }

    /**
     * What an agent may read. Internal articles are included only when the
     * agent holds the permission for them, and drafts only for someone who can
     * edit — a half-written article is not an answer.
     *
     * @param  Builder<KbArticle>  $query
     * @return Builder<KbArticle>
     */
    public function scopeVisibleToAgent(Builder $query, User $user): Builder
    {
        if (! $user->hasPermission('kb.view.internal')) {
            $query->where('kb_articles.visibility', self::PUBLIC);
        }

        if (! $user->hasPermission('kb.manage')) {
            $query->where('kb_articles.status', self::PUBLISHED);
        }

        return $query;
    }

    /**
     * Free-text search.
     *
     * MySQL uses the FULLTEXT index; SQLite (the test database) falls back to
     * LIKE. Both read `body_text` rather than `body`, so a search for "href"
     * finds articles that discuss links, not every article that contains one.
     *
     * @param  Builder<KbArticle>  $query
     * @return Builder<KbArticle>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($term): void {
            if ($scoped->getConnection()->getDriverName() === 'mysql') {
                $scoped->whereFullText(['title', 'excerpt', 'body_text'], $term)
                    ->orWhere('kb_articles.title', 'like', self::like($term));

                return;
            }

            // The LIKE fallback has to tokenise the way the index does. A
            // search term here is often a whole ticket subject — "Printer
            // keeps jamming" — and matching that phrase verbatim against an
            // article called "Printer paper jam" finds nothing at all.
            foreach (self::words($term) as $word) {
                $like = self::like($word);

                $scoped->orWhere('kb_articles.title', 'like', $like)
                    ->orWhere('kb_articles.excerpt', 'like', $like)
                    ->orWhere('kb_articles.body_text', 'like', $like);
            }
        });
    }

    /**
     * The words worth searching for.
     *
     * Anything shorter than three characters is dropped: "on", "de" and "a"
     * match most of the knowledge base and narrow nothing. The whole term is
     * kept as well, so an exact phrase still scores.
     *
     * @return array<int, string>
     */
    private static function words(string $term): array
    {
        $words = array_filter(
            preg_split('/\s+/u', $term) ?: [],
            static fn (string $word): bool => mb_strlen($word) >= 3,
        );

        return array_values(array_unique([$term, ...$words]));
    }

    private static function like(string $term): string
    {
        return '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
    }

    // -----------------------------------------------------------------
    // Payloads
    // -----------------------------------------------------------------

    /**
     * A search result or a suggestion: enough to decide whether to open it,
     * and deliberately without the body.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'category' => $this->relationLoaded('category') && $this->category
                ? ['name' => $this->category->translatedName(), 'slug' => $this->category->slug]
                : null,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The article itself. `body` is already sanitised, which is why it can be
     * handed to the browser as markup.
     *
     * @return array<string, mixed>
     */
    public function toDisplayArray(): array
    {
        return $this->toSummaryArray() + [
            'body' => $this->body,
            'locale' => $this->locale,
            'author' => $this->relationLoaded('author') ? $this->author?->toSummaryArray() : null,
            'published_at' => $this->published_at?->toIso8601String(),
            'view_count' => $this->view_count,
            'version' => $this->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return $this->toDisplayArray() + [
            'kb_category_id' => $this->kb_category_id,
            'editor' => $this->relationLoaded('editor') ? $this->editor?->toSummaryArray() : null,
            'position' => $this->position,
            'ticket_count' => $this->tickets_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
