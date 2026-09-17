<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who am I, and what may this token do?
 *
 * The first call every integration makes, and the one that turns "it returns
 * 403" into an answer: the scopes on the token, the permissions behind them,
 * and the rate limit in force. Without it a caller debugging a 403 has to
 * guess whether the token is wrong, the role is wrong, or both.
 *
 * It is outside the scope middleware on purpose — a token with no usable
 * scopes still needs to be able to find that out.
 */
class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        return new JsonResponse([
            'data' => [
                'user' => [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'scope_level' => $user->scopeLevel(),
                ],
                'token' => $token instanceof ApiToken ? [
                    'id' => $token->getKey(),
                    'name' => $token->name,
                    'scopes' => $token->scopeNames(),
                    'rate_limit' => $token->rate_limit ?? (int) config('ticktz.rate_limits.api'),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ] : null,
                // The permissions behind the scopes, so a caller can see the
                // intersection rather than deduce it from a 403.
                'permissions' => $user->permissionNames(),
                'version' => config('ticktz.version'),
            ],
        ]);
    }
}
