<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Liveness/readiness probe for load balancers and container orchestrators.
 *
 * Returns 200 only when every hard dependency answers. The individual checks
 * are reported so an operator can see *which* dependency is down without
 * shelling into the container.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn () => DB::connection()->getPdo() !== null),
            'cache' => $this->check(static function (): bool {
                Cache::put('ticktz:health', 1, 10);

                return Cache::get('ticktz:health') === 1;
            }),
            'queue' => $this->check(static fn () => Queue::connection()->getConnectionName() !== null),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'application' => config('app.name'),
            'version' => config('ticktz.version'),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * @param  callable():bool  $probe
     * @return array{ok: bool, error?: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => (bool) $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
        }
    }
}
