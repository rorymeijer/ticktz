<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    /**
     * An attachment inherits the visibility of the comment it belongs to: a
     * file on an internal note must never be downloadable by the requester,
     * even with the direct URL.
     */
    public function view(User $user, Attachment $attachment): bool
    {
        $ticket = $attachment->ticket;

        if (! $user->can('view', $ticket)) {
            return false;
        }

        return ! $attachment->is_internal || $user->can('viewInternal', $ticket);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return (int) $attachment->user_id === $user->getKey()
            || $user->hasPermission('tickets.delete');
    }
}
