<?php

declare(strict_types=1);

use App\Jobs\Automation\RunAutomationRulesJob;
use App\Models\AuditLogEntry;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Label;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationGuard;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();

    $this->engine = app(AutomationEngine::class);
    $this->guard = app(AutomationGuard::class);
});

function autoTicket(array $attributes = []): Ticket
{
    return app(TicketService::class)->create(array_merge([
        'subject' => 'The door badge reader is dead',
        'requester_id' => makeRequester()->getKey(),
    ], $attributes));
}

/**
 * Most tests here do *not* call this: creating or changing a ticket emits the
 * domain event, the listener queues the job, and the sync queue runs it — so
 * the whole wiring is exercised, which is the part most likely to break.
 *
 * This exists only for the cases that need to start a cascade partway through,
 * which nothing else can set up.
 */
function runRules(string $trigger, Ticket $ticket, array $context = [], int $depth = 0, array $seen = []): void
{
    (new RunAutomationRulesJob($trigger, (int) $ticket->getKey(), $context, $depth, $seen))
        ->handle(app(AutomationEngine::class), app(AutomationGuard::class));
}

// -----------------------------------------------------------------
// The acceptance criterion from the brief
// -----------------------------------------------------------------

/**
 * "New ticket in queue X → assign to team Y and set the priority", and it is
 * logged. This is the example the brief asks for by name.
 */
it('routes a new ticket to a team and raises its priority, and logs doing it', function (): void {
    $queue = App\Models\Queue::query()->first();
    $team = Team::factory()->create();

    $rule = AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_CREATED)
        ->matching([['field' => 'queue', 'operator' => 'is', 'value' => $queue->id]])
        ->then([
            ['type' => 'assign_team', 'team_id' => $team->id],
            ['type' => 'set_priority', 'priority_id' => priority('high')->id],
        ])
        ->create();

    $ticket = autoTicket(['queue_id' => $queue->id]);

    $ticket->refresh();

    expect($ticket->team_id)->toBe($team->id)
        ->and($ticket->priority_id)->toBe(priority('high')->id);

    $execution = AutomationExecution::query()->where('automation_rule_id', $rule->id)->sole();

    expect($execution->status)->toBe(AutomationExecution::STATUS_MATCHED)
        ->and($execution->ticket_id)->toBe($ticket->id)
        ->and(collect($execution->actions)->pluck('type')->all())->toBe(['assign_team', 'set_priority'])
        ->and(collect($execution->actions)->every(fn (array $a) => $a['ok']))->toBeTrue();
});

it('leaves a ticket alone when the condition does not hold, and says which one', function (): void {
    $team = Team::factory()->create();

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_CREATED)
        ->matching([['field' => 'priority', 'operator' => 'is', 'value' => priority('urgent')->id]])
        ->then([['type' => 'assign_team', 'team_id' => $team->id]])
        ->create();

    $ticket = autoTicket(['priority_id' => priority('low')->id]);

    $execution = AutomationExecution::query()->sole();

    expect($ticket->fresh()->team_id)->toBeNull()
        ->and($execution->status)->toBe(AutomationExecution::STATUS_SKIPPED)
        ->and($execution->reason)->toContain('priority is');
});

it('applies a rule with no conditions to everything its trigger fires on', function (): void {
    $label = Label::factory()->create();

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_CREATED)
        ->then([['type' => 'add_label', 'label_id' => $label->id]])
        ->create();

    $ticket = autoTicket();

    expect($ticket->fresh()->labels->pluck('id')->all())->toContain($label->id);
});

it('ignores an inactive rule and a rule for another trigger', function (): void {
    $team = Team::factory()->create();

    AutomationRule::factory()->inactive()
        ->on(AutomationRule::TRIGGER_CREATED)
        ->then([['type' => 'assign_team', 'team_id' => $team->id]])
        ->create();

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_COMMENTED)
        ->then([['type' => 'assign_team', 'team_id' => $team->id]])
        ->create();

    $ticket = autoTicket();

    expect($ticket->fresh()->team_id)->toBeNull()
        ->and(AutomationExecution::query()->count())->toBe(0);
});

// -----------------------------------------------------------------
// Ordering
// -----------------------------------------------------------------

it('runs rules in position order', function (): void {
    AutomationRule::factory()->at(20)
        ->then([['type' => 'set_priority', 'priority_id' => priority('low')->id]])
        ->create(['name' => 'Second']);

    AutomationRule::factory()->at(10)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create(['name' => 'First']);

    $ticket = autoTicket();

    // Both ran, in order, so the later one wins.
    expect($ticket->fresh()->priority_id)->toBe(priority('low')->id)
        ->and(AutomationExecution::query()->with('rule')->orderBy('id')->get()->pluck('rule.name')->all())
        ->toBe(['First', 'Second']);
});

it('stops after a matching rule that says to stop', function (): void {
    AutomationRule::factory()->at(10)->stopping()
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create();

    AutomationRule::factory()->at(20)
        ->then([['type' => 'set_priority', 'priority_id' => priority('low')->id]])
        ->create();

    $ticket = autoTicket();

    expect($ticket->fresh()->priority_id)->toBe(priority('urgent')->id)
        ->and(AutomationExecution::query()->count())->toBe(1);
});

it('does not stop on a rule that says to stop but did not match', function (): void {
    AutomationRule::factory()->at(10)->stopping()
        ->matching([['field' => 'source', 'operator' => 'is', 'value' => 'never-a-source']])
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create();

    AutomationRule::factory()->at(20)
        ->then([['type' => 'set_priority', 'priority_id' => priority('low')->id]])
        ->create();

    $ticket = autoTicket();

    expect($ticket->fresh()->priority_id)->toBe(priority('low')->id)
        ->and(AutomationExecution::query()->count())->toBe(2);
});

// -----------------------------------------------------------------
// Loop protection — the failure mode this feature has
// -----------------------------------------------------------------

/**
 * A rule that sets a field emits ticket.updated, which is a trigger, which can
 * run the rule again. Without a guard this is an infinite loop that looks like
 * a busy worker.
 */
it('will not run a rule twice on the same ticket in one cascade', function (): void {
    $rule = AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_UPDATED)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create();

    $ticket = autoTicket(['priority_id' => priority('low')->id]);

    runRules(AutomationRule::TRIGGER_UPDATED, $ticket);

    // The guard remembers the pair, so a second pass inside the same cascade
    // is refused outright.
    app(AutomationGuard::class)->enter(1, [$rule->id.':'.$ticket->id]);
    $second = app(AutomationEngine::class)->apply($rule, $ticket->fresh(), AutomationRule::TRIGGER_UPDATED);
    app(AutomationGuard::class)->reset();

    expect($second)->toBeNull()
        ->and(AutomationExecution::query()->count())->toBe(1);
});

it('refuses to go deeper than the configured cascade limit', function (): void {
    config(['ticktz.automation.max_depth' => 2]);

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_UPDATED)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create();

    $ticket = autoTicket();

    runRules(AutomationRule::TRIGGER_UPDATED, $ticket, depth: 2);

    expect(AutomationExecution::query()->count())->toBe(0);
});

it('carries the cascade into the job a rule change causes', function (): void {
    // The ticket is filed before the rules exist, so creating it starts no
    // cascade of its own and the depth under test is unambiguous.
    $ticket = autoTicket();

    // Two rules that trigger each other: one sets the priority, the other
    // reacts to the change by setting the team.
    $first = AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_CREATED)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create();

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_UPDATED)
        ->then([['type' => 'assign_team', 'team_id' => Team::factory()->create()->id]])
        ->create();

    // Faked *after* the ticket exists, so the job under test is the one the
    // rule's own action causes rather than the one that created the ticket.
    Queue::fake();

    runRules(AutomationRule::TRIGGER_CREATED, $ticket);

    Queue::assertPushed(RunAutomationRulesJob::class, function (RunAutomationRulesJob $job) use ($first, $ticket): bool {
        return $job->trigger === AutomationRule::TRIGGER_UPDATED
            && $job->depth === 1
            // The rule that caused the change is carried forward, so it cannot
            // run again further down the cascade.
            && in_array($first->id.':'.$ticket->id, $job->seen, true);
    });
});

it('starts at depth zero for a change a person made', function (): void {
    Queue::fake();

    AutomationRule::factory()->on(AutomationRule::TRIGGER_UPDATED)
        ->then([['type' => 'add_watcher', 'user_id' => makeAgent()->id]])
        ->create();

    $ticket = autoTicket();
    app(TicketService::class)->update($ticket, ['subject' => 'Changed by a human'], makeAgent());

    Queue::assertPushed(RunAutomationRulesJob::class, fn (RunAutomationRulesJob $job): bool => $job->depth === 0);
});

// -----------------------------------------------------------------
// Actions
// -----------------------------------------------------------------

it('attributes what it did to the rule, not to the system', function (): void {
    $team = Team::factory()->create();

    $rule = AutomationRule::factory()
        ->then([['type' => 'assign_team', 'team_id' => $team->id]])
        ->create(['name' => 'Route hardware to the workshop']);

    $ticket = autoTicket();

    $entry = AuditLogEntry::query()
        ->where('auditable_id', $ticket->id)
        ->where('actor_type', 'automation')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_label)->toBe('Route hardware to the workshop');
});

it('reports an action that could not do anything rather than claiming success', function (): void {
    AutomationRule::factory()
        ->then([['type' => 'assign_user', 'user_id' => 99999]])
        ->create();

    $ticket = autoTicket();

    $execution = AutomationExecution::query()->sole();

    expect($execution->status)->toBe(AutomationExecution::STATUS_FAILED)
        ->and($execution->actions[0]['ok'])->toBeFalse()
        ->and($execution->actions[0]['detail'])->toBe('no such active user');
});

/**
 * The fourth door. The console, the ticket form and the API all refuse to put
 * a ticket in the hands of somebody outside its team; a rule that could do it
 * anyway would be the way in, and the quietest one — it happens at three in
 * the morning and the trail says no person did it.
 */
it('refuses to assign a ticket to somebody outside its team', function (): void {
    $outsider = makeAgent();
    $team = Team::factory()->create();

    AutomationRule::factory()
        ->then([['type' => 'assign_user', 'user_id' => $outsider->getKey()]])
        ->create();

    $ticket = autoTicket(['team_id' => $team->getKey()]);

    $execution = AutomationExecution::query()->sole();

    expect($ticket->fresh()->assignee_id)->toBeNull()
        ->and($execution->actions[0]['ok'])->toBeFalse()
        ->and($execution->actions[0]['detail'])->toBe("not on this ticket's team");
});

it('assigns a ticket to somebody on its team', function (): void {
    $member = makeAgent();
    $team = Team::factory()->create();
    $team->members()->attach($member);

    AutomationRule::factory()
        ->then([['type' => 'assign_user', 'user_id' => $member->getKey()]])
        ->create();

    $ticket = autoTicket(['team_id' => $team->getKey()]);

    expect($ticket->fresh()->assignee_id)->toBe($member->getKey())
        ->and(AutomationExecution::query()->sole()->actions[0]['ok'])->toBeTrue();
});

it('moves a ticket to a team and lets go of an assignee who is not on it', function (): void {
    // assign_team goes through the same update() the ticket form does, so the
    // rule it enforces is the same one — including this consequence of it.
    $member = makeAgent();
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $first->members()->attach($member);

    AutomationRule::factory()
        ->then([['type' => 'assign_team', 'team_id' => $second->getKey()]])
        ->create();

    $ticket = autoTicket(['team_id' => $first->getKey(), 'assignee_id' => $member->getKey()]);

    expect($ticket->fresh()->assignee_id)->toBeNull()
        ->and($ticket->fresh()->team_id)->toBe($second->getKey());
});

/**
 * The workflow is the authority on what a ticket may do. A rule that could
 * override it would make the workflow a suggestion.
 */
it('refuses a transition the workflow does not allow', function (): void {
    AutomationRule::factory()
        ->then([['type' => 'transition', 'status_id' => status('closed')->id]])
        ->create();

    $ticket = autoTicket();

    $execution = AutomationExecution::query()->sole();

    expect($ticket->fresh()->status->slug)->toBe('new')
        ->and($execution->actions[0]['ok'])->toBeFalse()
        ->and($execution->actions[0]['detail'])->toContain('not allowed by the workflow');
});

it('makes a legal transition', function (): void {
    AutomationRule::factory()
        ->then([['type' => 'transition', 'status_id' => status('open')->id]])
        ->create();

    $ticket = autoTicket();

    expect($ticket->fresh()->status->slug)->toBe('open');
});

it('carries on to the next action when one fails', function (): void {
    $label = Label::factory()->create();

    AutomationRule::factory()
        ->then([
            ['type' => 'assign_user', 'user_id' => 99999],
            ['type' => 'add_label', 'label_id' => $label->id],
        ])
        ->create();

    $ticket = autoTicket();

    expect($ticket->fresh()->labels->pluck('id')->all())->toContain($label->id);
});

it('posts an automated comment with no author and fills its placeholders', function (): void {
    AutomationRule::factory()
        ->then([[
            'type' => 'add_comment',
            'body' => 'Auto-triaged {{ ticket.key }} as {{ ticket.priority }}. {{ nope }}',
            'internal' => true,
        ]])
        ->create();

    $ticket = autoTicket();

    $comment = $ticket->comments()->latest('id')->sole();

    expect($comment->user_id)->toBeNull()
        ->and($comment->is_internal)->toBeTrue()
        ->and($comment->source)->toBe('automation')
        ->and($comment->body_text)->toBe("Auto-triaged {$ticket->key} as Normal.");
});

it('says nothing happened when the ticket is already in the state the rule wants', function (): void {
    $team = Team::factory()->create();

    AutomationRule::factory()
        ->then([['type' => 'assign_team', 'team_id' => $team->id]])
        ->create();

    $ticket = autoTicket(['team_id' => $team->id]);

    expect(AutomationExecution::query()->sole()->actions[0]['detail'])->toBe('already on this team');
});

// -----------------------------------------------------------------
// Bookkeeping
// -----------------------------------------------------------------

it('counts runs and matches on the rule', function (): void {
    $rule = AutomationRule::factory()
        ->matching([['field' => 'priority', 'operator' => 'is', 'value' => priority('urgent')->id]])
        ->then([['type' => 'add_watcher', 'user_id' => makeAgent()->id]])
        ->create();

    autoTicket(['priority_id' => priority('urgent')->id]);
    autoTicket(['priority_id' => priority('low')->id]);

    $rule->refresh();

    expect($rule->run_count)->toBe(2)
        ->and($rule->match_count)->toBe(1)
        ->and($rule->last_run_at)->not->toBeNull();
});

it('records the cascade depth on every execution', function (): void {
    AutomationRule::factory()->on(AutomationRule::TRIGGER_UPDATED)
        ->then([['type' => 'add_watcher', 'user_id' => makeAgent()->id]])
        ->create();

    $ticket = autoTicket();
    runRules(AutomationRule::TRIGGER_UPDATED, $ticket, depth: 2);

    expect(AutomationExecution::query()->sole()->depth)->toBe(2);
});
