<?php

declare(strict_types=1);

use App\Jobs\Automation\DeliverWebhookJob;
use App\Jobs\Automation\RunScheduledRulesJob;
use App\Jobs\Sla\SweepSlaTimersJob;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\BusinessCalendar;
use App\Models\Label;
use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationGuard;
use App\Services\Sla\SlaEngine;
use App\Services\Sla\SlaEscalator;
use App\Services\Tickets\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    seedServiceDesk();
    Mail::fake();
});

function triggerTicket(array $attributes = []): Ticket
{
    return app(TicketService::class)->create(array_merge([
        'subject' => 'Meeting room projector will not switch on',
        'requester_id' => makeRequester()->getKey(),
    ], $attributes));
}

// -----------------------------------------------------------------
// Each trigger is reached by the thing it is named after
// -----------------------------------------------------------------

it('fires on a comment, and can tell an internal note from a reply', function (): void {
    $team = Team::factory()->create();

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_COMMENTED)
        ->matching([['field' => 'comment_is_internal', 'operator' => 'is_false']])
        ->then([['type' => 'assign_team', 'team_id' => $team->id]])
        ->create();

    $ticket = triggerTicket();
    app(TicketService::class)->comment($ticket, 'Internal thought', makeAgent(), internal: true);

    expect($ticket->fresh()->team_id)->toBeNull();

    app(TicketService::class)->comment($ticket->fresh(), 'A real reply', makeAgent());

    expect($ticket->fresh()->team_id)->toBe($team->id);
});

it('fires on a transition and can read the status it moved to', function (): void {
    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_TRANSITIONED)
        ->matching([['field' => 'status_category', 'operator' => 'is', 'value' => 'resolved']])
        ->then([['type' => 'add_comment', 'body' => 'Closing the loop.', 'internal' => true]])
        ->create();

    $ticket = triggerTicket();
    app(TicketService::class)->transition($ticket, status('open'), makeAgent());

    expect($ticket->fresh()->comments()->count())->toBe(0);

    app(TicketService::class)->transition($ticket->fresh(), status('resolved'), makeAgent());

    expect($ticket->fresh()->comments()->latest('id')->first()->body)->toBe('Closing the loop.');
});

it('fires on an assignment', function (): void {
    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_ASSIGNED)
        ->then([['type' => 'add_label', 'label_id' => Label::factory()->create()->id]])
        ->create();

    $ticket = triggerTicket();
    app(TicketService::class)->assign($ticket, makeAgent(), makeAgent());

    expect($ticket->fresh()->labels)->toHaveCount(1);
});

it('fires on an update and can ask which field changed', function (): void {
    $team = Team::factory()->create();

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_UPDATED)
        ->matching([['field' => 'changed_field', 'operator' => 'is', 'value' => 'priority_id']])
        ->then([['type' => 'assign_team', 'team_id' => $team->id]])
        ->create();

    $ticket = triggerTicket();

    app(TicketService::class)->update($ticket, ['subject' => 'A new subject'], makeAgent());
    expect($ticket->fresh()->team_id)->toBeNull();

    app(TicketService::class)->update($ticket->fresh(), ['priority_id' => priority('urgent')->id], makeAgent());
    expect($ticket->fresh()->team_id)->toBe($team->id);
});

/**
 * The seam Phase 5 left open: a breach is a domain event, and this is what
 * hangs the general case off it.
 */
it('fires when an SLA target is missed', function (): void {
    $calendar = BusinessCalendar::factory()->alwaysOpen()->utc()->create(['is_default' => true]);
    $policy = SlaPolicy::factory()->default()->on($calendar)->create();
    SlaGoal::factory()->for($policy, 'policy')->metric('first_response', 60)->create();

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_SLA_BREACHED)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create();

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));
    $ticket = triggerTicket(['priority_id' => priority('normal')->id]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 11:30', 'UTC'));
    app(SweepSlaTimersJob::class)->handle(
        app(SlaEngine::class),
        app(SlaEscalator::class),
    );

    expect($ticket->fresh()->priority_id)->toBe(priority('urgent')->id);
});

// -----------------------------------------------------------------
// Scheduled rules
// -----------------------------------------------------------------

it('runs a scheduled rule against the open tickets that match', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', 'UTC'));

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_SCHEDULED)
        ->matching([['field' => 'age_minutes', 'operator' => 'greater_than', 'value' => 60]])
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create(['trigger_config' => ['cron' => '* * * * *']]);

    $old = triggerTicket(['priority_id' => priority('low')->id]);
    $old->forceFill(['created_at' => now()->subHours(5)])->save();

    $fresh = triggerTicket(['priority_id' => priority('low')->id]);

    $summary = app(RunScheduledRulesJob::class)->handle(app(AutomationEngine::class), app(AutomationGuard::class));

    expect($old->fresh()->priority_id)->toBe(priority('urgent')->id)
        ->and($fresh->fresh()->priority_id)->toBe(priority('low')->id)
        ->and($summary['matched'])->toBe(1);
});

it('leaves resolved and closed tickets out of a scheduled sweep', function (): void {
    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_SCHEDULED)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create(['trigger_config' => ['cron' => '* * * * *']]);

    $resolved = triggerTicket(['priority_id' => priority('low')->id]);
    app(TicketService::class)->transition($resolved, status('resolved'), makeAgent());

    app(RunScheduledRulesJob::class)->handle(app(AutomationEngine::class), app(AutomationGuard::class));

    expect($resolved->fresh()->priority_id)->toBe(priority('low')->id);
});

/**
 * A cron expression is read in the application's timezone, not UTC: an
 * administrator who writes "0 3 * * *" means three in the morning where the
 * desk is, the same as every other schedule in the instance.
 */
it('only runs a scheduled rule in the minute its cron asks for', function (): void {
    $zone = config('app.timezone');

    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_SCHEDULED)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create(['trigger_config' => ['cron' => '0 3 * * *']]);

    $ticket = triggerTicket(['priority_id' => priority('low')->id]);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 10:00', $zone));
    app(RunScheduledRulesJob::class)->handle(app(AutomationEngine::class), app(AutomationGuard::class));

    expect($ticket->fresh()->priority_id)->toBe(priority('low')->id);

    $this->travelTo(CarbonImmutable::parse('2025-06-02 03:00', $zone));
    app(RunScheduledRulesJob::class)->handle(app(AutomationEngine::class), app(AutomationGuard::class));

    expect($ticket->fresh()->priority_id)->toBe(priority('urgent')->id);
});

/**
 * A cron expression that does not parse must never run, rather than running
 * every minute.
 */
it('never runs a rule whose schedule does not parse', function (): void {
    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_SCHEDULED)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create(['trigger_config' => ['cron' => 'every other tuesday']]);

    $ticket = triggerTicket(['priority_id' => priority('low')->id]);

    app(RunScheduledRulesJob::class)->handle(app(AutomationEngine::class), app(AutomationGuard::class));

    expect($ticket->fresh()->priority_id)->toBe(priority('low')->id);
});

it('gives each ticket in a scheduled sweep its own cascade', function (): void {
    AutomationRule::factory()
        ->on(AutomationRule::TRIGGER_SCHEDULED)
        ->then([['type' => 'set_priority', 'priority_id' => priority('urgent')->id]])
        ->create(['trigger_config' => ['cron' => '* * * * *']]);

    triggerTicket(['priority_id' => priority('low')->id]);
    triggerTicket(['priority_id' => priority('low')->id]);
    triggerTicket(['priority_id' => priority('low')->id]);

    $summary = app(RunScheduledRulesJob::class)->handle(app(AutomationEngine::class), app(AutomationGuard::class));

    // All three, not one — the loop guard must not treat a sweep as one
    // cascade and refuse the rule after the first ticket.
    expect($summary['matched'])->toBe(3)
        ->and(Ticket::query()->where('priority_id', priority('urgent')->id)->count())->toBe(3);
});

// -----------------------------------------------------------------
// Webhooks
// -----------------------------------------------------------------

it('queues a webhook rather than calling out inline', function (): void {
    // Only the webhook is faked: a bare Bus::fake() would also swallow the job
    // that runs the rule, and then there would be no webhook to assert about.
    Bus::fake([DeliverWebhookJob::class]);

    AutomationRule::factory()
        ->then([['type' => 'webhook', 'url' => 'https://example.test/hook']])
        ->create();

    triggerTicket();

    Bus::assertDispatched(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->url === 'https://example.test/hook');
});

/**
 * A rule that can post to any URL is a request forgery primitive. Requiring
 * https at least keeps the payload off the wire in clear text and rules out
 * the file:// and gopher:// shapes of that attack.
 */
it('refuses a webhook url that is not https', function (string $url): void {
    Bus::fake([DeliverWebhookJob::class]);

    AutomationRule::factory()->then([['type' => 'webhook', 'url' => $url]])->create();

    $ticket = triggerTicket();

    Bus::assertNotDispatched(DeliverWebhookJob::class);

    expect(AutomationExecution::query()->sole()->actions[0]['detail'])->toBe('not an https url');
})->with([
    'http' => ['http://example.test/hook'],
    'file' => ['file:///etc/passwd'],
    'not a url' => ['example.test'],
    'empty' => [''],
]);

it('signs the payload when the rule carries a secret', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    $payload = ['trigger' => 'ticket.created', 'ticket' => ['key' => 'SUP-1']];

    (new DeliverWebhookJob('https://example.test/hook', $payload, 'shared-secret'))->handle();

    Http::assertSent(function ($request) use ($payload): bool {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $request->hasHeader('X-Ticktz-Signature', 'sha256='.hash_hmac('sha256', $body, 'shared-secret'))
            && $request->hasHeader('X-Ticktz-Event', 'ticket.created');
    });
});

it('sends no signature when there is no secret', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    (new DeliverWebhookJob('https://example.test/hook', ['trigger' => 'x']))->handle();

    Http::assertSent(fn ($request): bool => ! $request->hasHeader('X-Ticktz-Signature'));
});

it('throws on a failed delivery so the queue retries it', function (): void {
    Http::fake(['*' => Http::response('nope', 500)]);

    expect(fn () => (new DeliverWebhookJob('https://example.test/hook', ['trigger' => 'x']))->handle())
        ->toThrow(RequestException::class);
});
