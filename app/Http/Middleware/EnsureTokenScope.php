<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiScopes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate every API route sits behind: `scope:tickets.write`.
 *
 * Two checks, and both have to pass:
 *
 *  1. the token was issued with this scope, and
 *  2. its owner still holds the permissions the scope leans on.
 *
 * The second is the one that matters. Without it a token is a snapshot of
 * yesterday's access that keeps working after the account it belongs to has
 * been demoted — so a token is defined as the *intersection* of what it was
 * issued for and what its owner may do today. Take someone's agent role away
 * and every token they ever minted narrows with it, including the ones
 * everybody has forgotten about.
 *
 * Per-record authorisation still happens in the controllers. This decides
 * which endpoints a token may reach, not which rows it may see.
 */
class EnsureTokenScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny('unauthenticated', __('api.errors.unauthenticated'), 401);
        }

        if (! $user->is_active) {
            return $this->deny('account_inactive', __('api.errors.inactive'), 403);
        }

        $token = $user->currentAccessToken();

        foreach ($scopes as $scope) {
            // A session-authenticated request (the token-management UI calling
            // its own API) has no token to check abilities on; RBAC below is
            // the whole check there.
            if ($token !== null && ! $token->can($scope)) {
                return $this->deny('missing_scope', __('api.errors.missing_scope', ['scope' => $scope]), 403, [
                    'required_scope' => $scope,
                ]);
            }

            if (! $user->hasAnyPermission(...ApiScopes::permissionsFor($scope))) {
                return $this->deny('forbidden', __('api.errors.forbidden_scope', ['scope' => $scope]), 403, [
                    'required_scope' => $scope,
                ]);
            }
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function deny(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }
}
