<?php

declare(strict_types=1);

namespace App\Services\RichText;

use App\Models\Concerns\HasRichText;

/**
 * One rich text column, and what to do with it.
 *
 * @see HasRichText
 */
final readonly class RichTextAttribute
{
    public function __construct(
        /** Which vocabulary this field offers. */
        public RichTextProfile $profile = RichTextProfile::Basic,
        /**
         * The companion column holding the same content with the markup taken
         * out, or null where there is nothing that needs it.
         *
         * This is what a FULLTEXT index covers, what the plain-text part of an
         * e-mail carries, and what an export reads. Indexing the markup
         * instead would put every tag name in the index: a search for "code"
         * would match every ticket that contains a code block, and one for
         * "strong" would match half the desk.
         */
        public ?string $text = null,
        /** Whether an empty value should be stored as NULL or as ''. */
        public bool $nullable = true,
        /**
         * The companion JSON column holding the same field in other languages,
         * or null where the field is not translated.
         *
         * A translated field is the same field, so every language in it gets
         * the same treatment. Sanitising only the default one would leave the
         * Dutch version of an instruction as the way in.
         */
        public ?string $translations = null,
    ) {}
}
