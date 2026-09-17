<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an article said before somebody changed it.
 *
 * A version is written *before* the edit lands, so the versions table holds the
 * history and the article holds the present. Rows are never updated — the point
 * of a history nobody can edit is that you can trust it.
 */
class KbArticleVersion extends Model
{
    protected $fillable = [
        'kb_article_id', 'version', 'title', 'excerpt', 'body', 'editor_id', 'note',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return BelongsTo<KbArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KbArticle::class, 'kb_article_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    /**
     * The list entry. The body is left out: a history list shows what changed
     * and who, and loading a dozen full bodies to render it is waste.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'title' => $this->title,
            'note' => $this->note,
            'editor' => $this->relationLoaded('editor') ? $this->editor?->toSummaryArray() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDisplayArray(): array
    {
        return $this->toSummaryArray() + ['body' => $this->body, 'excerpt' => $this->excerpt];
    }
}
