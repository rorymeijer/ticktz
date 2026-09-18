<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Tickets\Assignability;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An `assignee_id` that the ticket's team allows.
 *
 * A rule object rather than a check in each controller, because there are four
 * doors into an assignment — the agent console, the ticket form, the public API
 * and an automation rule — and a rule only some of them enforce is not a rule.
 * It is validation rather than a policy because the answer belongs to the field
 * and the operator needs to see it next to the field.
 *
 * The team is resolved by the caller and passed in, since it comes from a
 * different place each time: a ticket that exists knows its own, and one being
 * created has only the `team_id` and `queue_id` somebody filled in. See
 * App\Http\Requests\Concerns\ResolvesAssignability.
 */
class AssignableToTeam implements ValidationRule
{
    public function __construct(
        /** The team the ticket belongs to, or null when it belongs to none. */
        private readonly ?int $teamId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Unassigning is always allowed, and a value that is not a number is
        // somebody else's rule to complain about — `integer` and `exists` both
        // run on this field. Answering here as well would put two messages
        // under one input.
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $refusal = Assignability::refusalForTeam($this->teamId, (int) $value);

        if ($refusal !== null) {
            $fail($refusal);
        }
    }
}
