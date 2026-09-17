<?php

declare(strict_types=1);

use App\Models\AutomationRule;
use App\Models\Label;
use App\Models\Organization;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\Automation\ConditionEvaluator;

beforeEach(function (): void {
    seedServiceDesk();
    $this->evaluator = app(ConditionEvaluator::class);
});

function holds(array $condition, Ticket $ticket, array $context = []): bool
{
    return app(ConditionEvaluator::class)->test($condition, $ticket, $context);
}

// -----------------------------------------------------------------
// Operators
// -----------------------------------------------------------------

it('compares an id field', function (): void {
    $ticket = Ticket::factory()->create(['priority_id' => priority('high')->id]);

    expect(holds(['field' => 'priority', 'operator' => 'is', 'value' => priority('high')->id], $ticket))->toBeTrue()
        ->and(holds(['field' => 'priority', 'operator' => 'is', 'value' => priority('low')->id], $ticket))->toBeFalse()
        ->and(holds(['field' => 'priority', 'operator' => 'is_not', 'value' => priority('low')->id], $ticket))->toBeTrue();
});

/**
 * Ids arrive from a form as strings and from the database as integers. A rule
 * that stops matching because of that is a bug report nobody can reproduce.
 */
it('does not care whether an id is a string or an integer', function (): void {
    $ticket = Ticket::factory()->create(['priority_id' => priority('high')->id]);

    expect(holds(['field' => 'priority', 'operator' => 'is', 'value' => (string) priority('high')->id], $ticket))
        ->toBeTrue();
});

it('compares against a list', function (): void {
    $ticket = Ticket::factory()->create(['priority_id' => priority('high')->id]);
    $ids = [priority('high')->id, priority('urgent')->id];

    expect(holds(['field' => 'priority', 'operator' => 'is_one_of', 'value' => $ids], $ticket))->toBeTrue()
        ->and(holds(['field' => 'priority', 'operator' => 'is_none_of', 'value' => $ids], $ticket))->toBeFalse()
        ->and(holds(['field' => 'priority', 'operator' => 'is_none_of', 'value' => [priority('low')->id]], $ticket))
        ->toBeTrue();
});

it('matches text case-insensitively', function (): void {
    $ticket = Ticket::factory()->create(['subject' => 'Printer on the SECOND floor']);

    expect(holds(['field' => 'subject', 'operator' => 'contains', 'value' => 'second'], $ticket))->toBeTrue()
        ->and(holds(['field' => 'subject', 'operator' => 'contains', 'value' => 'third'], $ticket))->toBeFalse()
        ->and(holds(['field' => 'subject', 'operator' => 'not_contains', 'value' => 'third'], $ticket))->toBeTrue()
        ->and(holds(['field' => 'subject', 'operator' => 'starts_with', 'value' => 'printer'], $ticket))->toBeTrue();
});

it('tests emptiness', function (): void {
    $ticket = Ticket::factory()->create(['assignee_id' => null, 'description' => null]);

    expect(holds(['field' => 'assignee', 'operator' => 'is_empty'], $ticket))->toBeTrue()
        ->and(holds(['field' => 'assignee', 'operator' => 'is_not_empty'], $ticket))->toBeFalse()
        ->and(holds(['field' => 'description', 'operator' => 'is_empty'], $ticket))->toBeTrue();
});

it('compares numbers', function (): void {
    $ticket = Ticket::factory()->create(['created_at' => now()->subHours(5)]);

    expect(holds(['field' => 'age_minutes', 'operator' => 'greater_than', 'value' => 120], $ticket))->toBeTrue()
        ->and(holds(['field' => 'age_minutes', 'operator' => 'less_than', 'value' => 120], $ticket))->toBeFalse()
        ->and(holds(['field' => 'age_minutes', 'operator' => 'less_than', 'value' => 600], $ticket))->toBeTrue();
});

it('tests booleans', function (): void {
    $assigned = Ticket::factory()->assignedTo(makeAgent())->create();
    $unassigned = Ticket::factory()->create(['assignee_id' => null]);

    expect(holds(['field' => 'is_assigned', 'operator' => 'is_true'], $assigned))->toBeTrue()
        ->and(holds(['field' => 'is_assigned', 'operator' => 'is_true'], $unassigned))->toBeFalse()
        ->and(holds(['field' => 'is_assigned', 'operator' => 'is_false'], $unassigned))->toBeTrue();
});

/**
 * "Has this label", not "has exactly this label" — nobody writing a rule about
 * labels means the latter.
 */
it('matches a label a ticket carries among others', function (): void {
    $wanted = Label::factory()->create();
    $other = Label::factory()->create();

    $ticket = Ticket::factory()->create();
    $ticket->labels()->attach([$wanted->id, $other->id]);
    $ticket->load('labels');

    expect(holds(['field' => 'label', 'operator' => 'is', 'value' => $wanted->id], $ticket))->toBeTrue()
        ->and(holds(['field' => 'label', 'operator' => 'is_not', 'value' => $wanted->id], $ticket))->toBeFalse()
        ->and(holds(['field' => 'label', 'operator' => 'is_one_of', 'value' => [$other->id, 999]], $ticket))->toBeTrue()
        ->and(holds(['field' => 'label', 'operator' => 'is_none_of', 'value' => [999]], $ticket))->toBeTrue();
});

it('reads the status category rather than the status name', function (): void {
    $ticket = Ticket::factory()->withStatus('waiting-for-requester')->create();
    $ticket->load('status');

    expect(holds(['field' => 'status_category', 'operator' => 'is', 'value' => 'pending'], $ticket))->toBeTrue()
        ->and(holds(['field' => 'status_category', 'operator' => 'is', 'value' => 'open'], $ticket))->toBeFalse();
});

it('reads fields off related records', function (): void {
    $organization = Organization::factory()->create();
    $team = Team::factory()->create();
    $requester = makeRequester(['organization_id' => $organization->id]);

    $ticket = Ticket::factory()->forRequester($requester)->create(['team_id' => $team->id]);

    expect(holds(['field' => 'organization', 'operator' => 'is', 'value' => $organization->id], $ticket))->toBeTrue()
        ->and(holds(['field' => 'team', 'operator' => 'is', 'value' => $team->id], $ticket))->toBeTrue()
        ->and(holds(['field' => 'requester', 'operator' => 'is', 'value' => $requester->id], $ticket))->toBeTrue();
});

// -----------------------------------------------------------------
// Trigger payload
// -----------------------------------------------------------------

it('reads the comment off the trigger payload', function (): void {
    $ticket = Ticket::factory()->create();
    $context = ['comment' => ['body' => 'Please escalate this', 'is_internal' => false]];

    expect(holds(['field' => 'comment_body', 'operator' => 'contains', 'value' => 'escalate'], $ticket, $context))
        ->toBeTrue()
        ->and(holds(['field' => 'comment_is_internal', 'operator' => 'is_false'], $ticket, $context))->toBeTrue();
});

it('tests which fields a change touched', function (): void {
    $ticket = Ticket::factory()->create();
    $context = ['changed_fields' => ['priority_id', 'team_id']];

    expect(holds(['field' => 'changed_field', 'operator' => 'is', 'value' => 'priority_id'], $ticket, $context))
        ->toBeTrue()
        ->and(holds(['field' => 'changed_field', 'operator' => 'is', 'value' => 'subject'], $ticket, $context))
        ->toBeFalse();
});

// -----------------------------------------------------------------
// Joining conditions
// -----------------------------------------------------------------

it('requires every condition under "all"', function (): void {
    $ticket = Ticket::factory()->create(['priority_id' => priority('high')->id, 'source' => 'email']);

    $rule = AutomationRule::factory()->matching([
        ['field' => 'priority', 'operator' => 'is', 'value' => priority('high')->id],
        ['field' => 'source', 'operator' => 'is', 'value' => 'email'],
    ])->create();

    [$matches] = $this->evaluator->evaluate($rule, $ticket);
    expect($matches)->toBeTrue();

    $rule->conditions = [
        ['field' => 'priority', 'operator' => 'is', 'value' => priority('high')->id],
        ['field' => 'source', 'operator' => 'is', 'value' => 'portal'],
    ];

    [$matches, $reason] = $this->evaluator->evaluate($rule, $ticket);

    expect($matches)->toBeFalse()
        ->and($reason)->toBe('source is portal');
});

it('requires only one condition under "any"', function (): void {
    $ticket = Ticket::factory()->create(['priority_id' => priority('high')->id, 'source' => 'email']);

    $rule = AutomationRule::factory()->matching([
        ['field' => 'priority', 'operator' => 'is', 'value' => priority('low')->id],
        ['field' => 'source', 'operator' => 'is', 'value' => 'email'],
    ], 'any')->create();

    expect($this->evaluator->evaluate($rule, $ticket)[0])->toBeTrue();

    $rule->conditions = [
        ['field' => 'priority', 'operator' => 'is', 'value' => priority('low')->id],
        ['field' => 'source', 'operator' => 'is', 'value' => 'portal'],
    ];

    expect($this->evaluator->evaluate($rule, $ticket)[0])->toBeFalse();
});

it('matches everything when there are no conditions', function (): void {
    $rule = AutomationRule::factory()->create(['conditions' => []]);

    expect($this->evaluator->evaluate($rule, Ticket::factory()->create())[0])->toBeTrue();
});

// -----------------------------------------------------------------
// Failing closed
// -----------------------------------------------------------------

/**
 * A malformed rule must not match every ticket on the desk. Failing closed is
 * the only safe direction: the worst case is a rule that does nothing, rather
 * than one that reassigns everything.
 */
it('treats an unknown field as not holding', function (): void {
    $ticket = Ticket::factory()->create();

    expect(holds(['field' => 'invented_field', 'operator' => 'is', 'value' => 'x'], $ticket))->toBeFalse()
        ->and(holds(['field' => 'invented_field', 'operator' => 'is_not', 'value' => 'x'], $ticket))->toBeFalse();
});

it('treats an unknown operator as not holding', function (): void {
    $ticket = Ticket::factory()->create(['source' => 'email']);

    expect(holds(['field' => 'source', 'operator' => 'sounds_like', 'value' => 'email'], $ticket))->toBeFalse();
});

it('drops a malformed condition from the rule entirely', function (): void {
    $rule = AutomationRule::factory()->matching([
        ['field' => 'invented_field', 'operator' => 'is', 'value' => 'x'],
        ['operator' => 'is', 'value' => 'no field at all'],
    ])->create();

    // Both are dropped, so the rule has no conditions and applies to
    // everything its trigger fires on — which is visible in the admin UI
    // rather than a rule that quietly never matches.
    expect($rule->conditionList())->toBe([])
        ->and($this->evaluator->evaluate($rule, Ticket::factory()->create())[0])->toBeTrue();
});

it('handles a null field without blowing up', function (): void {
    $ticket = Ticket::factory()->create(['last_requester_reply_at' => null]);

    expect(holds(['field' => 'minutes_since_requester_reply', 'operator' => 'greater_than', 'value' => 60], $ticket))
        ->toBeFalse()
        ->and(holds(['field' => 'minutes_since_requester_reply', 'operator' => 'is_empty'], $ticket))->toBeTrue();
});
