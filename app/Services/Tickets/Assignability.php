<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\Queue;
use App\Models\Team;
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
        // From the attributes rather than a loaded `queue` relation, which may
        // be the queue the ticket was in a moment ago: the caller asking this
        // is sometimes the code that is moving it. A ticket with its own team
        // answers without a query at all, which is most of them.
        return self::teamIdForAttributes(
            $ticket->team_id !== null ? (int) $ticket->team_id : null,
            $ticket->queue_id !== null ? (int) $ticket->queue_id : null,
        );
    }

    /**
     * The team a ticket being created would belong to.
     *
     * Creation has no ticket to ask, only the fields somebody filled in, and
     * the rule has to hold there too — otherwise the way to give a ticket to
     * somebody outside the team is to do it while creating it.
     */
    public static function teamIdForAttributes(?int $teamId, ?int $queueId): ?int
    {
        if ($teamId !== null) {
            return $teamId;
        }

        if ($queueId === null) {
            return null;
        }

        $queueTeam = Queue::query()->whereKey($queueId)->value('team_id');

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
        return self::queryForTeam(self::teamIdFor($ticket));
    }

    /**
     * @return Builder<User>
     */
    public static function queryForTeam(?int $teamId): Builder
    {
        $query = User::query()->active()->agents();

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
        return self::queryForTeam(self::teamIdFor($ticket))->whereKey($user->getKey())->exists();
    }

    /** The same question for a ticket that does not exist yet. */
    public static function allowsForTeam(?int $teamId, int $userId): bool
    {
        return self::queryForTeam($teamId)->whereKey($userId)->exists();
    }

    /**
     * Why not, in words, or null when there is no objection.
     *
     * Two reasons rather than one, because "you cannot assign that" leaves
     * somebody guessing which half is wrong — the person or the ticket.
     */
    public static function refusalFor(Ticket $ticket, User $user): ?string
    {
        return self::refusalForTeam(self::teamIdFor($ticket), (int) $user->getKey());
    }

    /**
     * The same, for a ticket that does not exist yet.
     *
     * This is the primitive the other one delegates to, so that the four
     * places that ask get the same sentence. Both extra queries are on the
     * failure path: the answer is one `exists` until it is no, and only then
     * does anybody need a name to put in the message.
     */
    public static function refusalForTeam(?int $teamId, int $userId): ?string
    {
        if (self::allowsForTeam($teamId, $userId)) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null || ! $user->isAgent() || ! $user->is_active) {
            return __('tickets.errors.assignee_not_agent');
        }

        // Only reachable with a team: without one every active agent is
        // allowed, so the branch above has already answered.
        return __('tickets.errors.assignee_not_in_team', [
            'team' => (string) Team::query()->whereKey($teamId)->value('name'),
        ]);
    }
}
