<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Ticket;
use Illuminate\Support\Arr;

/**
 * Decides whether a rule applies to a ticket.
 *
 * Conditions are a flat list joined by "all" or "any" — no nesting, no
 * parentheses. That is a deliberate limit: a nested boolean editor is hard to
 * build, harder to read, and a service desk that genuinely needs one is better
 * served by two rules than by one rule nobody can explain. See the decisions
 * doc.
 *
 * Every comparison goes through here, so an operator behaves identically
 * whichever field it is applied to.
 */
class ConditionEvaluator
{
    /**
     * Does this rule's condition set hold for this ticket?
     *
     * Returns the outcome and, when it does not hold, which condition decided
     * that — the execution log records it, and it is the answer to "why did my
     * rule not fire?".
     *
     * @param  array<string, mixed>  $context  Trigger payload: the comment, the
     *                                         changed fields, the SLA timer.
     * @return array{0: bool, 1: string|null, 2: array<string, mixed>|null}
     */
    public function evaluate(AutomationRule $rule, Ticket $ticket, array $context = []): array
    {
        $conditions = $rule->conditionList();

        // A rule with no conditions applies to everything its trigger fires
        // on, which is what makes "on every new ticket, do X" expressible.
        if ($conditions === []) {
            return [true, null, null];
        }

        $any = $rule->match_type === 'any';

        foreach ($conditions as $condition) {
            $holds = $this->test($condition, $ticket, $context);

            if ($any && $holds) {
                return [true, null, null];
            }

            if (! $any && ! $holds) {
                return [false, $this->describe($condition), $condition];
            }
        }

        // Under "any", no single condition is to blame — all of them are.
        return $any
            ? [false, 'none of the conditions held', null]
            : [true, null, null];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $context
     */
    public function test(array $condition, Ticket $ticket, array $context = []): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? '');
        $expected = $condition['value'] ?? null;

        $type = AutomationRule::FIELDS[$field] ?? null;

        if ($type === null || ! in_array($operator, AutomationRule::OPERATORS, true)) {
            // An unknown field cannot be true of anything. Failing closed keeps
            // a malformed rule from matching every ticket on the desk.
            return false;
        }

        $actual = $this->valueOf($field, $type, $ticket, $context);

        return match ($type) {
            'set' => $this->compareSet($operator, is_array($actual) ? $actual : [], $expected),
            'age', 'flag' => $this->compareScalar($operator, $actual, $expected),
            default => $this->compareScalar($operator, $actual, $expected),
        };
    }

    /**
     * What the ticket currently says for this field.
     *
     * @param  array<string, mixed>  $context
     */
    private function valueOf(string $field, string $type, Ticket $ticket, array $context): mixed
    {
        return match ($field) {
            'status' => $ticket->status_id,
            'status_category' => $ticket->status?->category,
            'priority' => $ticket->priority_id,
            'queue' => $ticket->queue_id,
            'team' => $ticket->team_id,
            'assignee' => $ticket->assignee_id,
            'requester' => $ticket->requester_id,
            'organization' => $ticket->organization_id,
            'request_type' => $ticket->request_type_id,
            'email_channel' => $ticket->email_channel_id,
            'source' => $ticket->source,
            'subject' => $ticket->subject,
            // The flattened text, not the markup. A rule saying "description
            // contains password" means the word — matched against the stored
            // HTML it would also fire on a rule looking for "code" in a ticket
            // that merely contains a code block.
            'description' => $ticket->description_text,
            'label' => $ticket->relationLoaded('labels')
                ? $ticket->labels->pluck('id')->all()
                : $ticket->labels()->pluck('labels.id')->all(),

            // Ages are in minutes so a single numeric comparison covers
            // "older than two hours" and "untouched for a week" alike.
            'age_minutes' => $ticket->created_at?->diffInMinutes(now()),
            'minutes_since_activity' => $ticket->last_activity_at?->diffInMinutes(now()),
            'minutes_since_requester_reply' => $ticket->last_requester_reply_at?->diffInMinutes(now()),

            'is_assigned' => $ticket->assignee_id !== null,
            'is_first_response_sent' => $ticket->first_response_at !== null,
            'sla_breached' => (bool) $ticket->sla_breached,

            // Trigger payload rather than ticket state.
            'comment_is_internal' => Arr::get($context, 'comment.is_internal'),
            'comment_body' => Arr::get($context, 'comment.body_text')
                ?? Arr::get($context, 'comment.body'),
            'changed_field' => Arr::get($context, 'changed_fields', []),

            default => null,
        };
    }

    private function compareScalar(string $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            'is' => $this->looseEquals($actual, $expected),
            'is_not' => ! $this->looseEquals($actual, $expected),
            'is_one_of' => $this->inList($actual, $expected),
            'is_none_of' => ! $this->inList($actual, $expected),
            'contains' => $actual !== null
                && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => $actual === null
                || ! str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'starts_with' => $actual !== null
                && str_starts_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'is_empty' => $actual === null || $actual === '' || $actual === [],
            'is_not_empty' => ! ($actual === null || $actual === '' || $actual === []),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'is_true' => (bool) $actual === true,
            'is_false' => (bool) $actual === false,
            default => false,
        };
    }

    /**
     * Many-to-many fields — a ticket's labels, or the list of fields a change
     * touched. "is" means "has this one", not "has exactly this one": nobody
     * writing a rule about labels means the latter.
     *
     * @param  array<int, mixed>  $actual
     */
    private function compareSet(string $operator, array $actual, mixed $expected): bool
    {
        $wanted = $this->toList($expected);

        return match ($operator) {
            'is', 'is_one_of', 'contains' => array_intersect($this->normalise($actual), $wanted) !== [],
            'is_not', 'is_none_of', 'not_contains' => array_intersect($this->normalise($actual), $wanted) === [],
            'is_empty' => $actual === [],
            'is_not_empty' => $actual !== [],
            default => false,
        };
    }

    /**
     * Ids arrive from a form as strings and from the database as integers, and
     * a rule that stops matching because of that is a bug report nobody can
     * reproduce. Comparison is on the string form of both sides.
     */
    private function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return (bool) $actual === (bool) $expected;
        }

        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        // A field whose value is a list has no scalar comparison. Saying so is
        // better than letting PHP stringify the array and compare nonsense.
        if (is_array($actual) || is_array($expected)) {
            return false;
        }

        return (string) $actual === (string) $expected;
    }

    private function inList(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || is_array($actual)) {
            return false;
        }

        return in_array((string) $actual, $this->toList($expected), true);
    }

    /**
     * @return array<int, string>
     */
    private function toList(mixed $value): array
    {
        return array_map(
            static fn ($item): string => (string) $item,
            is_array($value) ? $value : [$value],
        );
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalise(array $values): array
    {
        return array_map(static fn ($value): string => (string) $value, $values);
    }

    /**
     * The condition in words, for the execution log.
     *
     * @param  array<string, mixed>  $condition
     */
    private function describe(array $condition, ?string $override = null): string
    {
        if ($override !== null) {
            return $override;
        }

        $value = $condition['value'] ?? null;
        $printed = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;

        return trim(sprintf(
            '%s %s %s',
            $condition['field'] ?? '?',
            $condition['operator'] ?? '?',
            in_array($condition['operator'] ?? '', AutomationRule::VALUELESS_OPERATORS, true) ? '' : $printed,
        ));
    }
}
