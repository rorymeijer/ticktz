<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\RichTextImage;
use App\Models\User;

/**
 * Who may see an image pasted into rich text.
 *
 * An image has no audience of its own — it has the audience of whatever it was
 * pasted into. A screenshot in a ticket is readable by everyone who may read
 * that ticket; the same file in an internal note is not readable by the
 * requester, even though they may read the ticket around it; one in a help
 * article is readable by anyone the article is.
 *
 * Until the thing it was pasted into is saved, it belongs to nothing, and the
 * only person who may see it is whoever uploaded it. That window is where a
 * draft lives, and treating an unclaimed upload as public for the sake of
 * convenience would make a paste-and-abandon the easiest way to host a file on
 * somebody else's server.
 */
class RichTextImagePolicy
{
    public function view(User $user, RichTextImage $image): bool
    {
        $owner = $image->owner;

        if ($owner === null) {
            // Still a draft. Its uploader, and nobody else.
            return $image->user_id !== null && (int) $image->user_id === (int) $user->getKey();
        }

        // A comment's own policy already folds in the internal-note rule and
        // the ticket behind it.
        if ($owner instanceof Comment) {
            return $user->can('view', $owner);
        }

        if (! $user->can('view', $owner)) {
            return false;
        }

        // An image bound to a ticket but flagged internal came off an internal
        // note. The flag is checked on top of the owner rather than instead of
        // it, because both have to hold.
        return ! $image->is_internal || $user->can('viewInternal', $owner);
    }

    public function delete(User $user, RichTextImage $image): bool
    {
        return $image->user_id !== null && (int) $image->user_id === (int) $user->getKey();
    }
}
