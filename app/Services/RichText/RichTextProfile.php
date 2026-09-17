<?php

declare(strict_types=1);

namespace App\Services\RichText;

/**
 * How much HTML a field is allowed to hold.
 *
 * Two profiles rather than one, because the two kinds of rich text in Ticktz
 * are written by different people for different readers. A knowledge base
 * article is a document: it wants headings, tables, images and code blocks. A
 * ticket reply is a message: it wants emphasis, a list and a link, and giving
 * it a heading level would only invite somebody to shout.
 *
 * Neither is a security boundary on its own — both go through the same
 * allowlist sanitiser, and anything not named is removed. The profile decides
 * what a field *offers*, and the editor toolbar is built from the same choice,
 * so what somebody can click and what survives the save cannot drift apart.
 */
enum RichTextProfile: string
{
    /** Help articles: the full document vocabulary. */
    case Article = 'article';

    /** Ticket bodies, comments, notes, descriptions: a message vocabulary. */
    case Basic = 'basic';
}
