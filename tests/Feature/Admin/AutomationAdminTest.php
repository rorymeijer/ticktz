<?php

declare(strict_types=1);

use App\Models\AuditLogEntry;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Team;
use App\Models\Ticket;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();
    $this->admin = makeAdmin();
});

/**
 * @return array<string, mixed>
 */
function rulePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Route hardware to the workshop',
        'slug' => 'route-hardware',
        'trigger' => AutomationRule::TRIGGER_CREATED,
        'match_type' => 'all',
        'is_active' => true,
        'stop_processing' => false,
        'position' => 10,
        'conditions' => [
            ['field' => 'source', 'operator' => 'is', 'value' => 'portal'],
        ],
        'actions' => [
            ['type' => 'assign_team', 'team_id' => Team::factory()->create()->getKey()],
        ],
    ], $overrides);
}

it('lets an administrator open the automation page', function (): void {
    $this->actingAs($this->admin)->get('/admin/automation')->assertOk();
});

it('refuses every automation endpoint without the permission', function (string $method, string $uri): void {
    $this->actingAs(makeAgent())->call($method, $uri)->assertForbidden();
})->with([
    ['get', '/admin/automation'],
    ['post', '/admin/automation/rules'],
]);

it('creates a rule and audits it', function (): void {
    $this->actingAs($this->admin)->post('/admin/automation/rules', rulePayload())->assertRedirect();

    $rule = AutomationRule::query()->sole();

    expect($rule->slug)->toBe('route-hardware')
        ->and($rule->created_by)->toBe($this->admin->id)
        ->and(AuditLogEntry::query()
            ->where('auditable_type', $rule->getMorphClass())
            ->where('event', 'created')
            ->exists())->toBeTrue();
});

/**
 * The form is built from the engine's vocabulary, so anything outside it is a
 * rule the engine could not run.
 */
it('rejects a trigger the engine does not have', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload(['trigger' => 'when_i_feel_like_it']))
        ->assertSessionHasErrors('trigger');
});

it('rejects a condition field the engine cannot read', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload([
            'conditions' => [['field' => 'the_weather', 'operator' => 'is', 'value' => 'rain']],
        ]))
        ->assertSessionHasErrors('conditions.0.field');
});

it('rejects an action the engine cannot perform', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload([
            'actions' => [['type' => 'delete_the_ticket']],
        ]))
        ->assertSessionHasErrors('actions.0.type');
});

it('refuses a rule with no actions', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload(['actions' => []]))
        ->assertSessionHasErrors('actions');
});

/**
 * A rule that can post anywhere is a request forgery primitive, and the
 * payload carries ticket content.
 */
it('refuses a webhook that is not https', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload([
            'actions' => [['type' => 'webhook', 'url' => 'http://example.test/hook']],
        ]))
        ->assertSessionHasErrors('actions.0.url');
});

it('accepts an https webhook', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload([
            'actions' => [['type' => 'webhook', 'url' => 'https://example.test/hook', 'secret' => 'shh']],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

/**
 * A schedule that does not parse never runs. Refusing it at the form beats
 * accepting it and silently ignoring it.
 */
it('refuses a schedule it cannot read', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload([
            'trigger' => AutomationRule::TRIGGER_SCHEDULED,
            'trigger_config' => ['cron' => 'whenever'],
        ]))
        ->assertSessionHasErrors('trigger_config.cron');
});

it('accepts a valid schedule', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload([
            'trigger' => AutomationRule::TRIGGER_SCHEDULED,
            'trigger_config' => ['cron' => '15 2 * * 1-5'],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AutomationRule::query()->sole()->cronExpression())->toBe('15 2 * * 1-5');
});

/**
 * A cron on a rule that is not scheduled would never run and would read as if
 * it might.
 */
it('drops a schedule from a rule that is not scheduled', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/automation/rules', rulePayload([
            'trigger' => AutomationRule::TRIGGER_CREATED,
            'trigger_config' => ['cron' => '0 3 * * *'],
        ]))
        ->assertRedirect();

    expect(AutomationRule::query()->sole()->trigger_config)->toBeNull();
});

it('turns a rule off and on again in one click', function (): void {
    $rule = AutomationRule::factory()->create();

    $this->actingAs($this->admin)->patch("/admin/automation/rules/{$rule->id}/active")->assertRedirect();
    expect($rule->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->admin)->patch("/admin/automation/rules/{$rule->id}/active")->assertRedirect();
    expect($rule->fresh()->is_active)->toBeTrue();

    expect(AuditLogEntry::query()->whereIn('event', ['automation.enabled', 'automation.disabled'])->count())->toBe(2);
});

it('deletes a rule and its history with it', function (): void {
    $rule = AutomationRule::factory()->create();
    AutomationExecution::query()->create([
        'automation_rule_id' => $rule->id,
        'trigger' => 'ticket.created',
        'status' => 'matched',
        'occurred_at' => now(),
    ]);

    $this->actingAs($this->admin)->delete("/admin/automation/rules/{$rule->id}")->assertRedirect();

    expect(AutomationRule::query()->count())->toBe(0)
        ->and(AutomationExecution::query()->count())->toBe(0);
});

// -----------------------------------------------------------------
// Trying a rule out
// -----------------------------------------------------------------

/**
 * Writing a rule you cannot try out means testing it on real tickets, which is
 * how an automation feature earns its reputation.
 */
it('says whether a rule would match a ticket, without changing anything', function (): void {
    $team = Team::factory()->create();

    $rule = AutomationRule::factory()
        ->matching([['field' => 'priority', 'operator' => 'is', 'value' => priority('urgent')->id]])
        ->then([['type' => 'assign_team', 'team_id' => $team->id]])
        ->create();

    $urgent = Ticket::factory()->create(['priority_id' => priority('urgent')->id]);

    $this->actingAs($this->admin)
        ->post("/admin/automation/rules/{$rule->id}/preview", ['ticket_key' => $urgent->key])
        ->assertRedirect()
        ->assertSessionHas('success');

    // Nothing ran: the preview evaluates conditions and stops.
    expect($urgent->fresh()->team_id)->toBeNull()
        ->and(AutomationExecution::query()->count())->toBe(0);
});

it('says which condition would stop a rule from matching', function (): void {
    $rule = AutomationRule::factory()
        ->matching([['field' => 'priority', 'operator' => 'is', 'value' => priority('urgent')->id]])
        ->then([['type' => 'add_watcher', 'user_id' => makeAgent()->id]])
        ->create();

    $low = Ticket::factory()->create(['priority_id' => priority('low')->id]);

    $this->actingAs($this->admin)
        ->post("/admin/automation/rules/{$rule->id}/preview", ['ticket_key' => $low->key])
        ->assertRedirect()
        ->assertSessionHas('info');
});

it('says so when the ticket does not exist', function (): void {
    $rule = AutomationRule::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/automation/rules/{$rule->id}/preview", ['ticket_key' => 'NOPE-999'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

// -----------------------------------------------------------------
// The log
// -----------------------------------------------------------------

it('shows the execution log and filters it', function (): void {
    $rule = AutomationRule::factory()->create();
    $other = AutomationRule::factory()->create();

    foreach ([[$rule, 'matched'], [$rule, 'skipped'], [$other, 'matched']] as [$which, $status]) {
        AutomationExecution::query()->create([
            'automation_rule_id' => $which->id,
            'trigger' => 'ticket.created',
            'status' => $status,
            'occurred_at' => now(),
        ]);
    }

    $all = $this->actingAs($this->admin)->get('/admin/automation')->assertOk();
    expect($all->viewData('page')['props']['executions'])->toHaveCount(3);

    $byRule = $this->actingAs($this->admin)->get("/admin/automation?rule={$rule->id}")->assertOk();
    expect($byRule->viewData('page')['props']['executions'])->toHaveCount(2);

    $byStatus = $this->actingAs($this->admin)->get('/admin/automation?status=skipped')->assertOk();
    expect($byStatus->viewData('page')['props']['executions'])->toHaveCount(1);
});
