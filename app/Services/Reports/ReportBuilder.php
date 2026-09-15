<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\DailyMetric;
use App\Models\Organization;
use App\Models\Priority;
use App\Models\Queue;
use App\Models\RequestType;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns the daily rollups into the four questions people actually ask.
 *
 * Reads {@see DailyMetric} and nothing else. Every report is the same three
 * steps — pick a metric, sum it over a period, optionally split it by one
 * dimension — which is why they share this class rather than having four of
 * their own.
 *
 * Averages are computed as `sum(total) / sum(count)` across the range, never
 * as the mean of the daily averages. The second is the mistake that makes a
 * quiet Sunday weigh as much as a busy Monday, and it is wrong by an amount
 * nobody can eyeball.
 */
class ReportBuilder
{
    /** @var array<int, string> */
    public const REPORTS = ['sla', 'volume', 'workload', 'cycle_time'];

    /**
     * A report, ready to draw.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(string $report, array $filters): array
    {
        [$from, $to] = $this->period($filters);

        $dimension = $this->dimension($filters);

        return match ($report) {
            'volume' => $this->volume($from, $to, $dimension),
            'workload' => $this->workload($from, $to),
            'cycle_time' => $this->cycleTime($from, $to, $dimension),
            default => $this->sla($from, $to, $dimension),
        } + [
            'report' => $report,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dimension' => $dimension,
        ];
    }

    /**
     * SLA compliance: of the clocks that finished in this period, how many
     * were met.
     *
     * @return array<string, mixed>
     */
    private function sla(CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $series = [];
        $totals = ['met' => 0, 'breached' => 0];

        foreach (['first_response', 'resolution'] as $metric) {
            $met = $this->daily(DailyMetric::slaMetric($metric, 'met'), $from, $to);
            $breached = $this->daily(DailyMetric::slaMetric($metric, 'breached'), $from, $to);

            $series[$metric] = $this->days($from, $to)
                ->map(fn (string $date) => [
                    'date' => $date,
                    'met' => $met[$date]['count'] ?? 0,
                    'breached' => $breached[$date]['count'] ?? 0,
                ])
                ->all();

            $totals['met'] += array_sum(array_column($met, 'count'));
            $totals['breached'] += array_sum(array_column($breached, 'count'));
        }

        $byMetric = [];

        foreach (['first_response', 'resolution'] as $metric) {
            $met = array_sum(array_column($series[$metric], 'met'));
            $breached = array_sum(array_column($series[$metric], 'breached'));

            $byMetric[$metric] = [
                'met' => $met,
                'breached' => $breached,
                'compliance' => $this->percentage($met, $met + $breached),
            ];
        }

        return [
            // Keyed by clock, and under its own name: the other three reports
            // hand back a flat list of days under `series`, and one key that
            // is sometimes a list and sometimes a map is a payload every
            // consumer has to special-case.
            'slaSeries' => $series,
            'summary' => [
                'met' => $totals['met'],
                'breached' => $totals['breached'],
                'compliance' => $this->percentage($totals['met'], $totals['met'] + $totals['breached']),
            ],
            'by_metric' => $byMetric,
            'breakdown' => $dimension === 'all' ? [] : $this->slaBreakdown($from, $to, $dimension),
        ];
    }

    /**
     * Compliance per queue, team, priority — whichever was asked for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function slaBreakdown(CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $met = $this->grouped(
            [DailyMetric::slaMetric('first_response', 'met'), DailyMetric::slaMetric('resolution', 'met')],
            $from,
            $to,
            $dimension,
        );

        $breached = $this->grouped(
            [DailyMetric::slaMetric('first_response', 'breached'), DailyMetric::slaMetric('resolution', 'breached')],
            $from,
            $to,
            $dimension,
        );

        $labels = $this->labels($dimension);

        return collect(array_unique([...array_keys($met), ...array_keys($breached)]))
            ->map(function (int $id) use ($met, $breached, $labels): array {
                $metCount = $met[$id]['count'] ?? 0;
                $breachedCount = $breached[$id]['count'] ?? 0;

                return [
                    'id' => $id,
                    'label' => $labels[$id] ?? __('reports.unassigned'),
                    'met' => $metCount,
                    'breached' => $breachedCount,
                    'total' => $metCount + $breachedCount,
                    'compliance' => $this->percentage($metCount, $metCount + $breachedCount),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * How many tickets arrived and how many left.
     *
     * @return array<string, mixed>
     */
    private function volume(CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $created = $this->daily(DailyMetric::TICKETS_CREATED, $from, $to);
        $resolved = $this->daily(DailyMetric::TICKETS_RESOLVED, $from, $to);
        $reopened = $this->daily(DailyMetric::TICKETS_REOPENED, $from, $to);

        $series = $this->days($from, $to)
            ->map(fn (string $date) => [
                'date' => $date,
                'created' => $created[$date]['count'] ?? 0,
                'resolved' => $resolved[$date]['count'] ?? 0,
                'reopened' => $reopened[$date]['count'] ?? 0,
            ])
            ->all();

        $createdTotal = array_sum(array_column($series, 'created'));
        $resolvedTotal = array_sum(array_column($series, 'resolved'));

        return [
            'series' => $series,
            'summary' => [
                'created' => $createdTotal,
                'resolved' => $resolvedTotal,
                'reopened' => array_sum(array_column($series, 'reopened')),
                // Above 100% means the desk closed more than arrived — it is
                // working through a backlog. Below, the backlog is growing.
                'clearance' => $this->percentage($resolvedTotal, $createdTotal),
            ],
            'breakdown' => $dimension === 'all'
                ? []
                : $this->countBreakdown(DailyMetric::TICKETS_CREATED, $from, $to, $dimension),
        ];
    }

    /**
     * Who resolved what.
     *
     * Always split by assignee — that is what the question means — so it takes
     * no dimension of its own.
     *
     * @return array<string, mixed>
     */
    private function workload(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $resolved = $this->grouped([DailyMetric::TICKETS_RESOLVED], $from, $to, 'assignee');
        $duration = $this->grouped([DailyMetric::TIME_RESOLUTION], $from, $to, 'assignee');

        $labels = $this->labels('assignee');

        $rows = collect(array_keys($resolved))
            ->map(function (int $id) use ($resolved, $duration, $labels): array {
                $count = $resolved[$id]['count'] ?? 0;
                $seconds = $duration[$id]['total'] ?? 0;
                $measured = $duration[$id]['count'] ?? 0;

                return [
                    'id' => $id,
                    'label' => $labels[$id] ?? __('reports.unassigned'),
                    'resolved' => $count,
                    'average_seconds' => $measured > 0 ? (int) round($seconds / $measured) : null,
                ];
            })
            ->sortByDesc('resolved')
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'summary' => [
                'agents' => count(array_filter($rows, fn (array $row) => $row['id'] !== 0)),
                'resolved' => array_sum(array_column($rows, 'resolved')),
            ],
        ];
    }

    /**
     * How long the desk takes: to answer, and to finish.
     *
     * @return array<string, mixed>
     */
    private function cycleTime(CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $response = $this->daily(DailyMetric::TIME_FIRST_RESPONSE, $from, $to);
        $resolution = $this->daily(DailyMetric::TIME_RESOLUTION, $from, $to);

        $series = $this->days($from, $to)
            ->map(fn (string $date) => [
                'date' => $date,
                'first_response' => $this->average($response[$date] ?? null),
                'resolution' => $this->average($resolution[$date] ?? null),
            ])
            ->all();

        return [
            'series' => $series,
            'summary' => [
                'first_response' => $this->averageOf($response),
                'resolution' => $this->averageOf($resolution),
                'measured' => array_sum(array_column($resolution, 'count')),
            ],
            'breakdown' => $dimension === 'all'
                ? []
                : $this->durationBreakdown(DailyMetric::TIME_RESOLUTION, $from, $to, $dimension),
        ];
    }

    // -----------------------------------------------------------------
    // Reading the rollups
    // -----------------------------------------------------------------

    /**
     * One metric, day by day, keyed by date.
     *
     * @return array<string, array{count: int, total: int}>
     */
    private function daily(string $metric, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DailyMetric::query()
            ->where('metric', $metric)
            ->where('dimension', 'all')
            ->between($from->toDateString(), $to->toDateString())
            ->get(['date', 'count', 'total'])
            ->mapWithKeys(fn (DailyMetric $row) => [
                $row->date->toDateString() => ['count' => $row->count, 'total' => $row->total],
            ])
            ->all();
    }

    /**
     * One or more metrics, summed over the period and keyed by dimension id.
     *
     * @param  array<int, string>  $metrics
     * @return array<int, array{count: int, total: int}>
     */
    private function grouped(array $metrics, CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        return DailyMetric::query()
            ->whereIn('metric', $metrics)
            ->where('dimension', $dimension)
            ->between($from->toDateString(), $to->toDateString())
            ->selectRaw('dimension_id, SUM(count) as aggregate, SUM(total) as sum_total')
            ->groupBy('dimension_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->dimension_id => ['count' => (int) $row->aggregate, 'total' => (int) $row->sum_total],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function countBreakdown(string $metric, CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $rows = $this->grouped([$metric], $from, $to, $dimension);
        $labels = $this->labels($dimension);

        return collect($rows)
            ->map(fn (array $row, int $id) => [
                'id' => $id,
                'label' => $labels[$id] ?? __('reports.unassigned'),
                'count' => $row['count'],
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function durationBreakdown(string $metric, CarbonImmutable $from, CarbonImmutable $to, string $dimension): array
    {
        $rows = $this->grouped([$metric], $from, $to, $dimension);
        $labels = $this->labels($dimension);

        return collect($rows)
            ->filter(fn (array $row) => $row['count'] > 0)
            ->map(fn (array $row, int $id) => [
                'id' => $id,
                'label' => $labels[$id] ?? __('reports.unassigned'),
                'count' => $row['count'],
                'average_seconds' => (int) round($row['total'] / $row['count']),
            ])
            ->sortBy('average_seconds')
            ->values()
            ->all();
    }

    /**
     * Names for the ids a dimension produces.
     *
     * One query per report rather than one per row, and `0` is deliberately
     * absent: it means "no dimension" and the caller labels it.
     *
     * @return array<int, string>
     */
    public function labels(string $dimension): array
    {
        return match ($dimension) {
            'queue' => Queue::query()->pluck('name', 'id')->all(),
            'team' => Team::query()->pluck('name', 'id')->all(),
            'assignee' => User::query()->pluck('name', 'id')->all(),
            'priority' => Priority::query()->get()
                ->mapWithKeys(fn (Priority $priority) => [$priority->id => $priority->translatedName()])->all(),
            'request_type' => RequestType::query()->get()
                ->mapWithKeys(fn (RequestType $type) => [$type->id => $type->translatedName()])->all(),
            'organization' => Organization::query()->pluck('name', 'id')->all(),
            default => [],
        };
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(array $filters): array
    {
        $to = $this->date($filters['to'] ?? null) ?? CarbonImmutable::today();
        $from = $this->date($filters['from'] ?? null) ?? $to->subDays(29);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // A year of days is 365 points on a chart and the longest anybody
        // reads at once; beyond that the rollups are still there to query.
        return [$from->max($to->subDays(730)), $to];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function dimension(array $filters): string
    {
        $dimension = (string) ($filters['dimension'] ?? 'all');

        return in_array($dimension, DailyMetric::TABLE_DIMENSIONS, true) ? $dimension : 'all';
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function days(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $days = collect();

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days->push($day->toDateString());
        }

        return $days;
    }

    /**
     * @param  array{count: int, total: int}|null  $row
     */
    private function average(?array $row): ?int
    {
        return $row && $row['count'] > 0 ? (int) round($row['total'] / $row['count']) : null;
    }

    /**
     * @param  array<string, array{count: int, total: int}>  $rows
     */
    private function averageOf(array $rows): ?int
    {
        $count = array_sum(array_column($rows, 'count'));

        return $count > 0 ? (int) round(array_sum(array_column($rows, 'total')) / $count) : null;
    }

    private function percentage(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
