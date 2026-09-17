<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Ldap\LdapAuthenticator;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for both account sources.
 *
 * A local account is checked against its password hash; if that fails (or the
 * account does not exist locally at all) every enabled directory is tried.
 * Both mechanisms can be active at the same time, which is the normal
 * configuration: staff sign in with their AD account, external contacts with a
 * local one.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Not `email`: directories are commonly keyed on sAMAccountName.
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim((string) $this->input('email'));
        $password = (string) $this->input('password');
        $remember = $this->boolean('remember');

        $user = $this->attemptLocal($login, $password) ?? $this->attemptDirectory($login, $password);

        if (! $user) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => trans('auth.inactive')]);
        }

        Auth::login($user, $remember);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->ip(),
        ])->saveQuietly();

        RateLimiter::clear($this->throttleKey());
    }

    private function attemptLocal(string $login, string $password): ?User
    {
        $user = User::query()
            ->where('directory', 'local')
            ->where(fn ($query) => $query->where('email', $login)->orWhere('username', $login))
            ->first();

        if (! $user || $user->password === null) {
            return null;
        }

        return Hash::check($password, $user->password) ? $user : null;
    }

    private function attemptDirectory(string $login, string $password): ?User
    {
        return app(LdapAuthenticator::class)->attempt($login, $password);
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $limit = (int) config('ticktz.rate_limits.login', 5);

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $limit)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->string('email')).'|'.$this->ip());
    }
}
