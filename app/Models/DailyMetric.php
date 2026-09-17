<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One day's worth of one measurement, optionally split by one dimension.
 *
 * Deliberately not auditable and deliberately not a domain object: these rows
 * are derived data, rebuilt from the tickets they summarise. Nothing reads a
 * single one of them — every query is a range, grouped and summed.
 *
 * @property string $metric
 * @property string $dimension
 */
class DailyMetric extends Model
{
    public const TABLE_DIMENSIONS = [
        'all', 'queue', 'team', 'assignee', 'priority', 'request_type', 'organization',
    ];

    /** Counting metrics: `count` is the answer, `total` is unused. */
    public const TICKETS_CREATED = 'tickets.created';

    public const TICKETS_RESOLVED = 'tickets.resolved';

    public const TICKETS_REOPENED = 'tickets.reopened';

    /** Duration metrics: the answer is `total / count`, in seconds. */
    public const TIME_FIRST_RESPONSE = 'time.first_response';

    public const TIME_RESOLUTION = 'time.resolution';

    protected $table = 'report_daily_metrics';

    protected $fillable = ['date', 'metric', 'dimension', 'dimension_id', 'count', 'total'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'count' => 'integer',
            'total' => 'integer',
        ];
    }

    /**
     * The SLA outcome metric for one clock and one result.
     *
     * Built rather than listed because the two halves multiply: two metrics
     * (`first_response`, `resolution`) times two outcomes (`met`, `breached`).
     */
    public static function slaMetric(string $slaMetric, string $outcome): string
    {
        return "sla.{$slaMetric}.{$outcome}";
    }

    /**
     * @param  Builder<DailyMetric>  $query
     * @return Builder<DailyMetric>
     */
    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }
}
