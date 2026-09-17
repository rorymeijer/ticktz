<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Housekeeping for token-authenticated requests.
 *
 * Sanctum already stamps `last_used_at`. What it cannot do is tell the
 * response which token answered it, and a caller that cannot see its own rate
 * limit has to discover it by being throttled. So every API response carries
 * the limit headers, and — when the token has an expiry — how long it has
 * left, which is how an integration finds out its credential is ageing out
 * before the morning it stops working.
 */
class TrackApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $token = $request->user()?->currentAccessToken();

        if ($token instanceof ApiToken) {
            $response->headers->set('X-Ticktz-Token', (string) $token->getKey());

            if ($token->expires_at !== null) {
                $response->headers->set('X-Ticktz-Token-Expires', $token->expires_at->toIso8601String());
            }
        }

        $response->headers->set('X-Ticktz-Version', (string) config('ticktz.version'));

        return $response;
    }
}
