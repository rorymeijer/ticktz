<?php

declare(strict_types=1);

namespace App\Services\Kb;

use App\Services\RichText\RichTextProfile;
use App\Services\RichText\RichTextSanitizer;

/**
 * An article body, cleaned.
 *
 * All of the work is {@see RichTextSanitizer}'s — this exists to name the
 * profile once, at the boundary where the knowledge base meets it, rather than
 * at every call site in {@see ArticleService}. An article gets the document
 * vocabulary: headings, tables, images and code, on top of the message
 * vocabulary every other field gets.
 *
 * `toText()` flattens to a single line here rather than keeping paragraph
 * breaks, because what it feeds is the `body_text` column behind the FULLTEXT
 * index and the excerpt under a search result. Neither has any use for a line
 * break, and a stored newline would only show up in a list.
 */
class ArticleSanitizer
{
    public function __construct(private readonly RichTextSanitizer $rich) {}

    public function clean(string $html): string
    {
        return $this->rich->clean($html, RichTextProfile::Article, images: true);
    }

    public function toText(string $html): string
    {
        return $this->rich->toLine($html);
    }

    public function excerpt(string $html, int $length = 200): string
    {
        return $this->rich->excerpt($html, $length);
    }
}
