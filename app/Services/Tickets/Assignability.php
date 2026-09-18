<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may be given this ticket.
 *
 * One place, because the answer is asked from four: the assign action in the
 * agent console, creating and updating a ticket in the interface, the public
 * API, and an automation rule that assigns. A rule about who may hold a ticket
 * that only some of those enforce is not a rule — it is a dropdown.
 */
class Assignability
{
    /**
     * The team this ticket belongs to, or null when it belongs to none.
     *
     * A ticket carries its own `team_id`, and a ticket in a queue inherits the
     * queue's. Both count, and the ticket's own wins: that is already how
     * `Ticket::visibleTo` decides who can see it, and a ticket somebody can see
     * through their team but not be given would be a strange shape to defend.
     */
    public static function teamIdFor(Ticket $ticket): ?int
    {
        if ($ticket->team_id !== null) {
            return (int) $ticket->team_id;
        }

        $queueTeam = $ticket->relationLoaded('queue')
            ? $ticket->queue?->team_id
            : $ticket->queue()->value('team_id');

        return $queueTeam !== null ? (int) $queueTeam : null;
    }

    /**
     * The agents who may take it.
     *
     * A query rather than a collection, so that the picker can search inside it
     * and the validator can ask about one person without loading everybody.
     *
     * @return Builder<User>
     */
    public static function query(Ticket $ticket): Builder
    {
        $query = User::query()->active()->agents();

        $teamId = self::teamIdFor($ticket);

        // A ticket belonging to no team at all is assignable to any agent.
        // Refusing instead would be defensible on paper and unusable in
        // practice: a desk routes plenty of work without a team on it — an
        // e-mail that matched no queue, a ticket typed straight in — and
        // nothing about that work should be impossible to hand to somebody.
        if ($teamId !== null) {
            $query->whereHas('teams', fn (Builder $teams): Builder => $teams->whereKey($teamId));
        }

        return $query;
    }

    /** Whether this particular person may hold this particular ticket. */
    public static function allows(Ticket $ticket, User $user): bool
    {
        return self::query($ticket)->whereKey($user->getKey())->exists();
    }

    /**
     * Why not, in words, or null when there is no objection.
     *
     * Two reasons rather than one, because "you cannot assign that" leaves
     * somebody guessing which half is wrong — the person or the ticket.
     */
    public static function refusalFor(Ticket $ticket, User $user): ?string
    {
        if (self::allows($ticket, $user)) {
            return null;
        }

        if (! $user->isAgent() || ! $user->is_active) {
            return __('tickets.errors.assignee_not_agent');
        }

        return __('tickets.errors.assignee_not_in_team', [
            'team' => $ticket->team?->name ?? $ticket->queue?->team?->name ?? '',
        ]);
    }
}
