<?php

declare(strict_types=1);

use App\Models\DailyMetric;
use App\Models\Queue;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Services\Reports\MetricsCollector;
use App\Services\Reports\ReportBuilder;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * Rebuild the rollups for the window the tests work in.
 */
function rebuild(int $days = 10): void
{
    app(MetricsCollector::class)->rebuild(
        CarbonImmutable::today()->subDays($days),
        CarbonImmutable::today(),
    );
}

/**
 * A finished SLA clock, so the compliance figures have something to count.
 */
function slaOutcome(Ticket $ticket, string $metric, string $status, CarbonImmutable $at): SlaTimer
{
    return SlaTimer::query()->create([
        'ticket_id' => $ticket->getKey(),
        'metric' => $metric,
        'target_minutes' => 60,
        'started_at' => $at->subHours(2),
        'due_at' => $at->subHour(),
        'status' => $status,
        'completed_at' => $status === SlaTimer::STATUS_MET ? $at : null,
        'breached_at' => $status === SlaTimer::STATUS_BREACHED ? $at : null,
    ]);
}

/*
|--------------------------------------------------------------------------
| The acceptance criterion
|--------------------------------------------------------------------------
|
| From the brief: "een SLA-compliance-dashboard toont correcte cijfers over een
| periode; export werkt."
|
*/

it('reports the correct SLA compliance over a period', function (): void {
    $yesterday = CarbonImmutable::yesterday()->setTime(10, 0);

    // Seven met, three breached: 70%.
    foreach (range(1, 7) as $i) {
        slaOutcome(Ticket::factory()->create(), 'resolution', SlaTimer::STATUS_MET, $yesterday);
    }

    foreach (range(1, 3) as $i) {
        slaOutcome(Ticket::factory()->create(), 'resolution', SlaTimer::STATUS_BREACHED, $yesterday);
    }

    rebuild();

    $data = app(ReportBuilder::class)->build('sla', [
        'from' => CarbonImmutable::today()->subDays(7)->toDateString(),
        'to' => CarbonImmutable::today()->toDateString(),
    ]);

    expect($data['summary']['met'])->toBe(7)
        ->and($data['summary']['breached'])->toBe(3)
        ->and($data['summary']['compliance'])->toBe(70.0);
});

it('counts the two clocks separately', function (): void {
    $yesterday = CarbonImmutable::yesterday()->setTime(10, 0);

    slaOutcome(Ticket::factory()->create(), 'first_response', SlaTimer::STATUS_MET, $yesterday);
    slaOutcome(Ticket::factory()->create(), 'first_response', SlaTimer::STATUS_BREACHED, $yesterday);
    slaOutcome(Ticket::factory()->create(), 'resolution', SlaTimer::STATUS_MET, $yesterday);

    rebuild();

    $data = app(ReportBuilder::class)->build('sla', []);

    expect($data['by_metric']['first_response']['compliance'])->toBe(50.0)
        ->and($data['by_metric']['resolution']['compliance'])->toBe(100.0);
});

/**
 * An outcome recorded is an outcome. Bucketing by the ticket's creation date
 * would mean a month's compliance figure keeps changing for weeks after the
 * month ends, which is no way to report a number to anybody.
 */
it('buckets an SLA outcome on the day the clock finished, not the day the ticket was raised', function (): void {
    $ticket = Ticket::factory()->create(['created_at' => CarbonImmutable::today()->subDays(5)]);

    slaOutcome($ticket, 'resolution', SlaTimer::STATUS_MET, CarbonImmutable::yesterday()->setTime(9, 0));

    rebuild();

    $onFinishDay = DailyMetric::query()
        ->where('date', CarbonImmutable::yesterday()->toDateString())
        ->where('metric', 'sla.resolution.met')
        ->where('dimension', 'all')
        ->value('count');

    $onCreationDay = DailyMetric::query()
        ->where('date', CarbonImmutable::today()->subDays(5)->toDateString())
        ->where('metric', 'sla.resolution.met')
        ->where('dimension', 'all')
        ->value('count');

    expect($onFinishDay)->toBe(1)->and($onCreationDay)->toBe(0);
});

/**
 * The engine stamps `breached_at` the moment a target passes and leaves the
 * clock running until the work is done. A promise already broken is a breach
 * whether or not the ticket has been finished since — counting only the
 * completed ones reported a desk with visibly late tickets at 100%.
 */
it('counts a clock that is past due but still running as a breach', function (): void {
    $yesterday = CarbonImmutable::yesterday()->setTime(10, 0);

    SlaTimer::query()->create([
        'ticket_id' => Ticket::factory()->create()->getKey(),
        'metric' => 'resolution',
        'target_minutes' => 60,
        'started_at' => $yesterday->subHours(3),
        'due_at' => $yesterday->subHours(2),
        'status' => SlaTimer::STATUS_RUNNING,
        'breached_at' => $yesterday,
    ]);

    rebuild();

    $data = app(ReportBuilder::class)->build('sla', []);

    expect($data['summary']['breached'])->toBe(1)
        ->and($data['summary']['compliance'])->toBe(0.0);
});

/**
 * A clock that breached and was then finished late carries both columns. It is
 * one outcome, and it is a breach.
 */
it('counts a clock that breached and then completed late only once', function (): void {
    $yesterday = CarbonImmutable::yesterday()->setTime(10, 0);

    SlaTimer::query()->create([
        'ticket_id' => Ticket::factory()->create()->getKey(),
        'metric' => 'resolution',
        'target_minutes' => 60,
        'started_at' => $yesterday->subHours(4),
        'due_at' => $yesterday->subHours(3),
        'status' => SlaTimer::STATUS_BREACHED,
        'breached_at' => $yesterday,
        'completed_at' => $yesterday->addHour(),
    ]);

    rebuild();

    $data = app(ReportBuilder::class)->build('sla', []);

    expect($data['summary']['breached'])->toBe(1)
        ->and($data['summary']['met'])->toBe(0);
});

it('leaves a clock that is neither finished nor late out of the figures', function (): void {
    slaOutcome(Ticket::factory()->create(), 'resolution', SlaTimer::STATUS_RUNNING, CarbonImmutable::yesterday());

    rebuild();

    $data = app(ReportBuilder::class)->build('sla', []);

    expect($data['summary']['met'])->toBe(0)
        ->and($data['summary']['breached'])->toBe(0)
        // No outcomes means no percentage, not 0% — a desk that has recorded
        // nothing has not failed everything.
        ->and($data['summary']['compliance'])->toBeNull();
});

it('splits compliance by a dimension', function (): void {
    $yesterday = CarbonImmutable::yesterday()->setTime(10, 0);
    $first = Queue::query()->first();
    $second = Queue::query()->skip(1)->first();

    slaOutcome(Ticket::factory()->create(['queue_id' => $first->id]), 'resolution', SlaTimer::STATUS_MET, $yesterday);
    slaOutcome(Ticket::factory()->create(['queue_id' => $first->id]), 'resolution', SlaTimer::STATUS_MET, $yesterday);
    slaOutcome(
        Ticket::factory()->create(['queue_id' => $second->id]),
        'resolution',
        SlaTimer::STATUS_BREACHED,
        $yesterday,
    );

    rebuild();

    $data = app(ReportBuilder::class)->build('sla', ['dimension' => 'queue']);

    $rows = collect($data['breakdown'])->keyBy('id');

    expect($rows[$first->id]['compliance'])->toBe(100.0)
        ->and($rows[$second->id]['compliance'])->toBe(0.0);
});

it('ignores outcomes outside the period', function (): void {
    slaOutcome(Ticket::factory()->create(), 'resolution', SlaTimer::STATUS_MET, CarbonImmutable::today()->subDays(8));
    slaOutcome(Ticket::factory()->create(), 'resolution', SlaTimer::STATUS_MET, CarbonImmutable::yesterday());

    rebuild();

    $data = app(ReportBuilder::class)->build('sla', [
        'from' => CarbonImmutable::today()->subDays(3)->toDateString(),
        'to' => CarbonImmutable::today()->toDateString(),
    ]);

    expect($data['summary']['met'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The pipeline
|--------------------------------------------------------------------------
*/

/**
 * A counter bumped as things happen drifts the first time a job is retried.
 * A day rebuilt from its source rows is either right or reproducibly wrong.
 */
it('produces the same figures however many times it runs', function (): void {
    slaOutcome(Ticket::factory()->create(), 'resolution', SlaTimer::STATUS_MET, CarbonImmutable::yesterday());

    rebuild();
    rebuild();
    rebuild();

    $data = app(ReportBuilder::class)->build('sla', []);

    expect($data['summary']['met'])->toBe(1);
});

/**
 * A metric that produced rows yesterday and none today must end up with none.
 * An upsert alone would leave the old figures standing.
 */
it('clears a figure that no longer has anything behind it', function (): void {
    $timer = slaOutcome(Ticket::factory()->create(), 'resolution', SlaTimer::STATUS_MET, CarbonImmutable::yesterday());

    rebuild();
    expect(app(ReportBuilder::class)->build('sla', [])['summary']['met'])->toBe(1);

    $timer->delete();
    rebuild();

    expect(app(ReportBuilder::class)->build('sla', [])['summary']['met'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Volume and cycle time
|--------------------------------------------------------------------------
*/

it('counts tickets created and resolved on their own days', function (): void {
    Ticket::factory()->create([
        'created_at' => CarbonImmutable::today()->subDays(3)->setTime(9, 0),
        'resolved_at' => CarbonImmutable::yesterday()->setTime(15, 0),
    ]);

    rebuild();

    $data = app(ReportBuilder::class)->build('volume', []);
    $byDate = collect($data['series'])->keyBy('date');

    expect($byDate[CarbonImmutable::today()->subDays(3)->toDateString()]['created'])->toBe(1)
        ->and($byDate[CarbonImmutable::yesterday()->toDateString()]['resolved'])->toBe(1)
        ->and($data['summary']['created'])->toBe(1)
        ->and($data['summary']['resolved'])->toBe(1);
});

it('averages a duration by the work done, not by the day', function (): void {
    // One ticket on a quiet day that took 10 hours; three on a busy day that
    // took 1 hour each. Weighted: (36000 + 3600*3) / 4 = 11700 seconds.
    // Averaging the daily averages would give (36000 + 3600) / 2 = 19800.
    Ticket::factory()->create([
        'created_at' => CarbonImmutable::today()->subDays(3)->setTime(8, 0),
        'resolved_at' => CarbonImmutable::today()->subDays(3)->setTime(18, 0),
    ]);

    foreach (range(1, 3) as $i) {
        Ticket::factory()->create([
            'created_at' => CarbonImmutable::yesterday()->setTime(9, 0),
            'resolved_at' => CarbonImmutable::yesterday()->setTime(10, 0),
        ]);
    }

    rebuild();

    $data = app(ReportBuilder::class)->build('cycle_time', []);

    expect($data['summary']['resolution'])->toBe(11700)
        ->and($data['summary']['measured'])->toBe(4);
});

it('reports who resolved what', function (): void {
    $agent = makeAgent();

    foreach (range(1, 2) as $i) {
        Ticket::factory()->create([
            'assignee_id' => $agent->getKey(),
            'created_at' => CarbonImmutable::yesterday()->setTime(9, 0),
            'resolved_at' => CarbonImmutable::yesterday()->setTime(11, 0),
        ]);
    }

    rebuild();

    $data = app(ReportBuilder::class)->build('workload', []);
    $row = collect($data['rows'])->firstWhere('id', $agent->getKey());

    expect($row['resolved'])->toBe(2)
        ->and($row['average_seconds'])->toBe(7200)
        ->and($data['summary']['resolved'])->toBe(2);
});

/**
 * `dimension_id` is 0 rather than NULL for "none", because MySQL treats NULLs
 * as distinct in a unique index and the rebuild's replace-then-insert would
 * quietly become an append.
 */
it('records an unassigned ticket under dimension zero', function (): void {
    Ticket::factory()->create([
        'assignee_id' => null,
        'created_at' => CarbonImmutable::yesterday()->setTime(9, 0),
    ]);

    rebuild();

    expect(
        DailyMetric::query()
            ->where('date', CarbonImmutable::yesterday()->toDateString())
            ->where('metric', DailyMetric::TICKETS_CREATED)
            ->where('dimension', 'assignee')
            ->where('dimension_id', 0)
            ->value('count'),
    )->toBe(1);
});
