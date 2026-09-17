<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\RichText\RichTextAttribute;
use App\Services\RichText\RichTextSanitizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Declares which of a model's columns hold rich text, and keeps them safe.
 *
 * Sanitising belongs at the write path, and this is the only place that is
 * true of *every* write path. A ticket description is set by an agent form, a
 * portal submission, an API client, an inbound e-mail and an automation rule;
 * a comment by five more. Putting the sanitiser in each service method means
 * the one added next year is the hole, and the hole is stored XSS — markup one
 * person wrote, rendered in another person's browser, with that person's
 * session.
 *
 * Hooked on `saving` rather than written as a mutator so the companion
 * plain-text column is filled in the same pass, from the same cleaned value.
 * Two attributes that are supposed to be the same content can otherwise drift,
 * and a search index that disagrees with what is on screen is worse than no
 * index at all.
 *
 * Models declare their columns:
 *
 *     protected static function richTextAttributes(): array
 *     {
 *         return ['description' => new RichTextAttribute(text: 'description_text')];
 *     }
 */
trait HasRichText
{
    public static function bootHasRichText(): void
    {
        static::saving(function (Model $model): void {
            /** @var RichTextSanitizer $sanitizer */
            $sanitizer = app(RichTextSanitizer::class);

            foreach (static::richTextAttributes() as $column => $rich) {
                // Only what changed. Re-cleaning an untouched column on every
                // save would rewrite history the moment the allowlist changes,
                // which is not a decision a status change should be making.
                if (! $model->isDirty($column)) {
                    continue;
                }

                $raw = $model->getAttribute($column);
                $clean = $raw === null ? '' : $sanitizer->clean((string) $raw, $rich->profile);

                $model->setAttribute($column, $clean === '' && $rich->nullable ? null : $clean);

                if ($rich->text !== null) {
                    $model->setAttribute($rich->text, $sanitizer->toText($clean));
                }
            }
        });
    }

    /**
     * @return array<string, RichTextAttribute>
     */
    protected static function richTextAttributes(): array
    {
        return [];
    }
}
