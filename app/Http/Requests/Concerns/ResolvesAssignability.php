<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Ticket;
use App\Rules\AssignableToTeam;
use App\Services\Tickets\Assignability;
use Closure;

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
    /**
     * Everything `assignee_id` has to satisfy beyond existing.
     *
     * Two rules, and they answer different questions. The first is whether
     * this person may hand a ticket to anybody at all; the second is whether
     * this particular person may hold this particular ticket.
     *
     * The permission one was missing everywhere. `tickets.assign` gated the
     * assign action and the button on the screen, and nothing on the way in:
     * somebody with `tickets.create` and no assign permission could hand a
     * ticket to whoever they liked by doing it while creating it, and somebody
     * with `tickets.update` could do it through the ticket form. The API
     * description has claimed this rule since the API existed.
     *
     * @return array<int, mixed>
     */
    protected function assigneeRules(?Ticket $ticket = null): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                if (! ($this->user()?->hasPermission('tickets.assign') ?? false)) {
                    $fail(__('tickets.errors.assignee_not_permitted'));
                }
            },
            $this->assignableRule($ticket),
        ];
    }

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
