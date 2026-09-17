<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Kb\ArticleService;
use App\Services\RichText\RichTextImageBinder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gives the images a record's text references an owner, and with it an
 * audience.
 *
 * Split from {@see HasRichText} because the two are genuinely separate jobs
 * and one model needs only this one: a knowledge base article is sanitised by
 * {@see ArticleService}, which also derives its excerpt and
 * its search text and writes its version history, so the trait's own cleaning
 * would be a second opinion on work already done. What the article still needs
 * is for the screenshots in it to belong to it.
 *
 * On `saved` rather than `saving`, because a record being created has no key
 * until the insert has happened and an image cannot be bound to an owner that
 * does not have an id yet.
 */
trait BindsRichTextImages
{
    public static function bootBindsRichTextImages(): void
    {
        static::saved(function (Model $model): void {
            $values = [];

            foreach (static::richTextImageColumns() as $column) {
                $value = $model->getAttribute($column);

                if (is_array($value)) {
                    // A translated field: every language can carry its own.
                    $values = [...$values, ...array_values($value)];

                    continue;
                }

                $values[] = $value;
            }

            app(RichTextImageBinder::class)->bind(
                $model,
                $values,
                $model->getAttribute('is_internal') === null ? null : (bool) $model->getAttribute('is_internal'),
            );
        });
    }

    /**
     * The columns whose text may reference an image.
     *
     * @return array<int, string>
     */
    protected static function richTextImageColumns(): array
    {
        return [];
    }
}
