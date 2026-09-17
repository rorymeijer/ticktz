<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal self-registration. Reachable only when the corresponding setting is
 * on (see EnsureSelfRegistrationIsEnabled); the account always lands in the
 * default role, never in an agent role.
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'locale' => app()->getLocale(),
            'directory' => 'local',
            'is_active' => true,
            'organization_id' => Organization::matchingEmail($validated['email'])?->getKey(),
        ]);

        $defaultRole = Role::query()->where('is_default', true)->first()
            ?? Role::query()->where('name', Role::REQUESTER)->first();

        if ($defaultRole) {
            $user->roles()->attach($defaultRole);
        }

        $audit->as('system', 'Self-registration')->created($user, 'Account self-registered through the portal');

        event(new Registered($user));

        Auth::login($user);

        return redirect()->intended(route('portal.index', absolute: false));
    }
}
