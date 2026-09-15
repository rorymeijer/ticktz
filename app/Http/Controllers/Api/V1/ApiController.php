<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared behaviour for the public API.
 *
 * The only thing worth centralising is the page size. Everything paginates —
 * an endpoint that returns "all tickets" is fine on a desk with two hundred
 * and an outage on one with two hundred thousand — and callers may ask for a
 * larger page up to a ceiling they cannot raise.
 */
abstract class ApiController extends Controller
{
    protected const MAX_PER_PAGE = 100;

    protected function perPage(Request $request): int
    {
        $requested = (int) $request->integer('per_page', (int) config('ticktz.per_page.tickets'));

        return max(1, min($requested, self::MAX_PER_PAGE));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }
}
