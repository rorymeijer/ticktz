<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Ticket;
use App\Rules\AssignableToTeam;
use App\Services\Tickets\Assignability;

/**
 * Works out which team's rule an `assignee_id` has to satisfy.
 *
 * Shared by the four requests that can set one. The subtlety it exists to get
 * right is that a request may move a ticket and assign it in the same post: the
 * team that decides is the one the ticket will belong to afterwards, not the
 * one it belongs to now. So a submitted `team_id` or `queue_id` wins over the
 * ticket's own — including when it is submitted as null, which is somebody
 * deliberately taking the ticket out of its team.
 */
trait ResolvesAssignability
{
    protected function assignableRule(?Ticket $ticket = null): AssignableToTeam
    {
        return new AssignableToTeam(Assignability::teamIdForAttributes(
            $this->teamAfterThisRequest('team_id', $ticket?->team_id),
            $this->teamAfterThisRequest('queue_id', $ticket?->queue_id),
        ));
    }

    /**
     * What the field will hold once this request is applied.
     *
     * `has` rather than `filled`, so that clearing a field is heard as
     * clearing it. Anything that is not a number reads as absent: `exists`
     * will refuse the request anyway, and guessing at it here would only
     * change which message the operator sees.
     */
    private function teamAfterThisRequest(string $field, mixed $current): ?int
    {
        $value = $this->has($field) ? $this->input($field) : $current;

        return is_numeric($value) ? (int) $value : null;
    }
}
