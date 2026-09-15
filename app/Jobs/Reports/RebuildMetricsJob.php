<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Services\Reports\MetricsCollector;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Rebuilds the daily rollups for a window.
 *
 * Runs on the low queue, because nothing is waiting on it: the dashboards read
 * whatever the last rebuild produced, and a figure that is fifteen minutes old
 * is a figure people can work with.
 *
 * Scheduled twice, for two different reasons. Every quarter of an hour it
 * rebuilds **today**, so the dashboard moves during the day. Overnight it
 * rebuilds the **last week**, which is what catches a ticket resolved at
 * 23:59, a backdated edit, or a run the queue dropped — the rebuild is
 * idempotent, so doing it again costs a query and fixes anything that drifted.
 */
class RebuildMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        $this->onQueue((string) config('ticktz.queues.low'));
    }

    /**
     * The last `$days` days, today included.
     */
    public static function forRecentDays(int $days): self
    {
        $today = CarbonImmutable::today();

        return new self($today->subDays(max($days - 1, 0))->toDateString(), $today->toDateString());
    }

    public function handle(MetricsCollector $collector): void
    {
        $written = $collector->rebuild(
            CarbonImmutable::parse($this->from),
            CarbonImmutable::parse($this->to),
        );

        Log::debug('Rebuilt report metrics.', [
            'from' => $this->from,
            'to' => $this->to,
            'rows' => $written,
        ]);
    }
}
