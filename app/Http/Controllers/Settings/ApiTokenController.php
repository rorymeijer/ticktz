<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiScopes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a person manages their own API tokens.
 *
 * Under /settings rather than /admin because a token belongs to a person, not
 * to the instance: it acts as them and carries their permissions. Anyone who
 * may use the API at all may mint one for themselves.
 *
 * The plaintext is returned once, through the flash bag, and never stored.
 * That is not a nicety — a token we can show again is one that can be read off
 * a screen by whoever is standing behind it, and "regenerate" is the honest
 * alternative to "show me that again".
 */
class ApiTokenController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $tokens = ApiToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->latest('id')
            ->get()
            ->map(fn (ApiToken $token) => $token->toDisplayArray())
            ->all();

        return Inertia::render('Settings/ApiTokens', [
            'tokens' => $tokens,
            // Only the scopes this person could actually exercise. Offering a
            // checkbox that mints a token which then 403s is a bug report
            // waiting to happen.
            'scopes' => array_map(
                fn (string $scope) => [
                    'key' => $scope,
                    'label' => __('api.scopes.'.$scope),
                    'permissions' => ApiScopes::permissionsFor($scope),
                ],
                ApiScopes::availableTo($user),
            ),
            'defaultRateLimit' => (int) config('ticktz.rate_limits.api'),
            // The plaintext of a token just created, read straight off the
            // one-shot flash. It is a page prop rather than a globally shared
            // one: a credential has no business riding along on every response
            // in the session, and this way it exists on exactly the render
            // that shows it.
            'newToken' => $request->session()->get('token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $available = ApiScopes::availableTo($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'scopes' => ['nullable', 'array'],
            // Validated against what *this* user may have, not against the
            // whole catalogue: the check that a token cannot exceed its owner
            // belongs at issue time as well as at request time.
            'scopes.*' => [Rule::in($available)],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $scopes = ApiScopes::sanitise($validated['scopes'] ?? []);

        $expiresAt = isset($validated['expires_in_days'])
            ? now()->addDays((int) $validated['expires_in_days'])
            : null;

        $new = $user->createToken($validated['name'], $scopes, $expiresAt);

        /** @var ApiToken $token */
        $token = $new->accessToken;
        $token->forceFill([
            'description' => $validated['description'] ?? null,
            'rate_limit' => $validated['rate_limit'] ?? null,
        ])->save();

        // The scopes are audited, the token is not. An audit trail that
        // records the credential is a second place it leaks from.
        $this->audit->actingAs($user)->log(
            $token,
            'api_token.created',
            "Created API token {$token->name}",
            context: ['scopes' => $scopes, 'expires_at' => $expiresAt?->toIso8601String()],
        );

        return back()->with('token', [
            'id' => $token->getKey(),
            'name' => $token->name,
            'plain' => $new->plainTextToken,
        ]);
    }

    public function destroy(Request $request, ApiToken $token): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // A token is revocable by its owner, and by anyone who administers
        // users — an account that has left the building must not keep a live
        // credential because only they could have withdrawn it.
        abort_unless(
            ((int) $token->tokenable_id === (int) $user->getKey()
                && $token->tokenable_type === $user->getMorphClass())
            || $user->hasPermission('users.manage'),
            403,
        );

        $name = $token->name;
        $token->delete();

        $this->audit->actingAs($user)->log(
            $user,
            'api_token.revoked',
            "Revoked API token {$name}",
        );

        return back();
    }
}
