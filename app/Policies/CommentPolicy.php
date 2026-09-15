<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function view(User $user, Comment $comment): bool
    {
        $ticket = $comment->ticket;

        if (! $user->can('view', $ticket)) {
            return false;
        }

        return ! $comment->is_internal || $user->can('viewInternal', $ticket);
    }

    /**
     * Comments are part of the record. An author may correct their own within
     * a short window; nobody edits someone else's words.
     */
    public function update(User $user, Comment $comment): bool
    {
        return (int) $comment->user_id === $user->getKey()
            && $comment->created_at?->gt(now()->subMinutes(30));
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $user->hasPermission('tickets.delete');
    }
}
