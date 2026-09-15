<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

/**
 * Who may see and act on a ticket.
 *
 * `view()` mirrors Ticket::scopeVisibleTo() exactly. They are kept next to each
 * other deliberately: the scope filters lists, the policy guards single
 * records, and a difference between the two is a data leak.
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission('tickets.view', 'portal.submit');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->hasPermission('tickets.view.all')) {
            return true;
        }

        if ($user->hasPermission('tickets.view')) {
            return (int) $ticket->assignee_id === $user->getKey()
                || (int) $ticket->created_by === $user->getKey()
                || in_array($ticket->team_id, $user->teamIds(), true)
                || in_array($ticket->queue?->team_id, $user->teamIds(), true)
                || $this->isWatching($user, $ticket);
        }

        return $this->isParticipant($user, $ticket);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission('tickets.create', 'portal.submit');
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.update') && $this->view($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.assign') && $this->view($user, $ticket);
    }

    public function transition(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.transition') && $this->view($user, $ticket);
    }

    /**
     * A requester may reply to their own ticket; an agent needs the permission.
     */
    public function comment(User $user, Ticket $ticket): bool
    {
        if ($user->hasPermission('tickets.comment')) {
            return $this->view($user, $ticket);
        }

        return $this->isParticipant($user, $ticket) && $ticket->isOpen();
    }

    public function commentInternally(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.comment.internal') && $this->view($user, $ticket);
    }

    /**
     * Internal notes are the agent console's side of the conversation.
     *
     * The boundary is agent versus requester, not a finer permission: anyone
     * who can open a ticket in the console needs the full context to work it,
     * and a service desk that hides half its own notes from its own staff is
     * one where people stop writing them.
     */
    public function viewInternal(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.view') && $this->view($user, $ticket);
    }

    public function link(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.link') && $this->view($user, $ticket);
    }

    public function watch(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function attach(User $user, Ticket $ticket): bool
    {
        return $this->comment($user, $ticket);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('tickets.delete');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('tickets.export');
    }

    private function isParticipant(User $user, Ticket $ticket): bool
    {
        if ((int) $ticket->requester_id === $user->getKey()) {
            return true;
        }

        if ($this->isWatching($user, $ticket)) {
            return true;
        }

        return $user->organization_id !== null
            && (int) $ticket->organization_id === (int) $user->organization_id
            && ($user->organization?->shared_ticket_visibility ?? false);
    }

    private function isWatching(User $user, Ticket $ticket): bool
    {
        return $ticket->relationLoaded('watchers')
            ? $ticket->watchers->contains('id', $user->getKey())
            : $ticket->watchers()->whereKey($user->getKey())->exists();
    }
}
