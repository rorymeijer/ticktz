<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\DailyMetric;
use App\Models\SlaTimer;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the daily rollups from the tickets they summarise.
 *
 * The whole pipeline is one idea: **a day is recomputed, never incremented.**
 * Counters bumped as things happen drift the first time a queued job is
 * retried, a ticket is edited straight in the database, or a deploy lands
 * mid-request — and nothing about a wrong counter announces itself. A day
 * rebuilt from its source rows is either right or reproducibly wrong, and
 * re-running it is always safe.
 *
 * That makes the cost the thing to watch, so each day is one grouped query per
 * metric per dimension rather than a walk over tickets, and a rebuild replaces
 * exactly the dates it was asked for.
 *
 * ### Which day does a number belong to?
 *
 * - **Volume** buckets on when it happened: created on its creation date,
 *   resolved on its resolution date. A ticket raised in September and resolved
 *   in October counts once in each.
 * - **SLA outcomes** bucket on the date the clock *finished*, not the date the
 *   ticket was raised. Bucketing by creation date means September's compliance
 *   figure keeps changing for weeks after September ends, which is no way to
 *   report a number to anybody. An outcome recorded is an outcome.
 * - **Durations** bucket with the event that completes them — a first response
 *   on the day it was sent, a resolution on the day it was resolved.
 */
class MetricsCollector
{
    /**
     * Dimensions a ticket is split by, and the column each reads.
     *
     * @var array<string, string>
     */
    private const DIMENSIONS = [
        'queue' => 'queue_id',
        'team' => 'team_id',
        'assignee' => 'assignee_id',
        'priority' => 'priority_id',
        'request_type' => 'request_type_id',
        'organization' => 'organization_id',
    ];

    /**
     * Rebuild every metric for every day in the range.
     *
     * @return int the number of rows written
     */
    public function rebuild(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $written = 0;

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $written += $this->rebuildDay($day);
        }

        return $written;
    }

    public function rebuildDay(CarbonImmutable $day): int
    {
        $date = $day->toDateString();
        $start = $day->startOfDay();
        $end = $day->endOfDay();

        $rows = [
            ...$this->volume($date, $start, $end),
            ...$this->durations($date, $start, $end),
            ...$this->slaOutcomes($date, $start, $end),
        ];

        return DB::transaction(function () use ($date, $rows): int {
            // Replace, not merge. A metric that produced rows yesterday and
            // none today must end up with none today — an upsert alone would
            // leave the old figures standing and the day would never go down.
            DailyMetric::query()->where('date', $date)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DailyMetric::query()->insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * How many tickets were created, resolved and reopened.
     *
     * @return array<int, array<string, mixed>>
     */
    private function volume(string $date, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            ...$this->countBy($date, DailyMetric::TICKETS_CREATED, 'created_at', $start, $end),
            ...$this->countBy($date, DailyMetric::TICKETS_RESOLVED, 'resolved_at', $start, $end),
            ...$this->countBy($date, DailyMetric::TICKETS_REOPENED, 'reopened_at', $start, $end),
        ];
    }

    /**
     * Count tickets whose `$column` falls on this day, once overall and once
     * per dimension.
     *
     * @return array<int, array<string, mixed>>
     */
    private function countBy(
        string $date,
        string $metric,
        string $column,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $rows = [];

        $base = fn () => Ticket::query()->whereBetween($column, [$start, $end]);

        $rows[] = $this->row($date, $metric, 'all', 0, (int) $base()->count(), 0);

        foreach (self::DIMENSIONS as $dimension => $ticketColumn) {
            $grouped = $base()
                ->selectRaw("COALESCE({$ticketColumn}, 0) as dimension_id, COUNT(*) as aggregate")
                ->groupBy('dimension_id')
                ->pluck('aggregate', 'dimension_id');

            foreach ($grouped as $id => $aggregate) {
                $rows[] = $this->row($date, $metric, $dimension, (int) $id, (int) $aggregate, 0);
            }
        }

        return $rows;
    }

    /**
     * How long things took, in seconds.
     *
     * Stored as a sum and a count rather than an average, so a range covering
     * a quiet Sunday and a busy Monday weights them by the work done rather
     * than treating the two days as equals.
     *
     * @return array<int, array<string, mixed>>
     */
    private function durations(string $date, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [];

        $specs = [
            DailyMetric::TIME_FIRST_RESPONSE => ['first_response_at', 'created_at'],
            DailyMetric::TIME_RESOLUTION => ['resolved_at', 'created_at'],
        ];

        foreach ($specs as $metric => [$endColumn, $startColumn]) {
            $seconds = $this->secondsExpression($startColumn, $endColumn);

            $base = fn () => Ticket::query()
                ->whereBetween($endColumn, [$start, $end])
                ->whereNotNull($startColumn);

            $overall = $base()
                ->selectRaw("COUNT(*) as aggregate, COALESCE(SUM({$seconds}), 0) as total")
                ->first();

            $rows[] = $this->row(
                $date,
                $metric,
                'all',
                0,
                (int) ($overall->aggregate ?? 0),
                (int) ($overall->total ?? 0),
            );

            foreach (self::DIMENSIONS as $dimension => $ticketColumn) {
                $grouped = $base()
                    ->selectRaw(
                        "COALESCE({$ticketColumn}, 0) as dimension_id, "
                        ."COUNT(*) as aggregate, COALESCE(SUM({$seconds}), 0) as total"
                    )
                    ->groupBy('dimension_id')
                    ->get();

                foreach ($grouped as $group) {
                    $rows[] = $this->row(
                        $date,
                        $metric,
                        $dimension,
                        (int) $group->dimension_id,
                        (int) $group->aggregate,
                        (int) $group->total,
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * How many SLA clocks were met and how many were breached.
     *
     * Bucketed on the moment the clock finished — `completed_at` for a met
     * one, `breached_at` for a breached one — so a figure, once reported, does
     * not move.
     *
     * @return array<int, array<string, mixed>>
     */
    private function slaOutcomes(string $date, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [];

        // A breach is `breached_at`, not `status = 'breached'`. The engine
        // stamps that column the moment a target passes and leaves the clock
        // running until the work is actually done — so a promise already
        // broken on a ticket still being worked has a breach date and a
        // `running` status. Counting only the finished ones reported a desk
        // with visibly late tickets at 100% compliance, which is exactly the
        // kind of wrong number a dashboard is trusted to never produce.
        //
        // A timer that breached and then completed late carries both columns,
        // and is counted once — as a breach, on the day it broke.
        $outcomes = [
            'met' => ['completed_at', fn ($query) => $query
                ->where('sla_timers.status', SlaTimer::STATUS_MET)
                ->whereNull('sla_timers.breached_at')],
            'breached' => ['breached_at', fn ($query) => $query],
        ];

        foreach ($outcomes as $label => [$column, $narrow]) {
            foreach (['first_response', 'resolution'] as $slaMetric) {
                $metric = DailyMetric::slaMetric($slaMetric, $label);

                $base = fn () => $narrow(
                    SlaTimer::query()
                        ->where('sla_timers.metric', $slaMetric)
                        ->whereNotNull("sla_timers.{$column}")
                        ->whereBetween("sla_timers.{$column}", [$start, $end])
                );

                $rows[] = $this->row($date, $metric, 'all', 0, (int) $base()->count(), 0);

                foreach (self::DIMENSIONS as $dimension => $ticketColumn) {
                    // The dimension lives on the ticket, not the timer, so the
                    // split needs the join. One join per dimension per day is
                    // the price of not denormalising six columns onto every
                    // timer row and keeping them in step forever.
                    $grouped = $base()
                        ->join('tickets', 'tickets.id', '=', 'sla_timers.ticket_id')
                        ->selectRaw("COALESCE(tickets.{$ticketColumn}, 0) as dimension_id, COUNT(*) as aggregate")
                        ->groupBy('dimension_id')
                        ->pluck('aggregate', 'dimension_id');

                    foreach ($grouped as $id => $aggregate) {
                        $rows[] = $this->row($date, $metric, $dimension, (int) $id, (int) $aggregate, 0);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Seconds between two timestamp columns, in the dialect at hand.
     *
     * SQLite has no TIMESTAMPDIFF and MySQL has no julianday, and the tests
     * run on one while production runs on the other — so the difference is
     * spelled out here once rather than discovered in CI.
     *
     * The ROUND is load-bearing. `julianday` returns a float, so a difference
     * of exactly ten hours comes back as 35999.999... and a bare CAST
     * truncates it to 35999. One second per row is invisible in a test and
     * accumulates into figures that disagree with the MySQL instance the same
     * data would produce — a reporting bug nobody would ever catch by reading
     * the dashboard.
     */
    private function secondsExpression(string $from, string $to): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(ROUND((julianday({$to}) - julianday({$from})) * 86400) AS INTEGER)"
            : "TIMESTAMPDIFF(SECOND, {$from}, {$to})";
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $date, string $metric, string $dimension, int $id, int $count, int $total): array
    {
        $now = now();

        return [
            'date' => $date,
            'metric' => $metric,
            'dimension' => $dimension,
            'dimension_id' => $id,
            'count' => $count,
            'total' => $total,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
