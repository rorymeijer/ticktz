<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ApiToken;
use App\Services\AuditLogger;
use App\Services\Automation\AutomationGuard;
use App\Services\Install\EnvWriter;
use App\Services\SettingsRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);

        // EnvWriter takes the path it edits, so the container cannot guess it.
        // Bound rather than defaulted in the constructor, because a writer
        // that silently falls back to the application's own .env is one that
        // will one day be handed a temp path in a test and edit the real file.
        $this->app->bind(EnvWriter::class, static fn () => EnvWriter::forApplication());
        $this->app->singleton(AuditLogger::class);
        // The automation loop guard holds the state of the cascade currently
        // running. Every collaborator has to see the same instance or a rule
        // cannot tell that it caused the change it is now reacting to.
        $this->app->singleton(AutomationGuard::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Our token model adds a per-token rate limit and a description, so
        // Sanctum has to mint and resolve that class rather than its own.
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        // Catch the two mistakes that actually cost us: an N+1 hiding behind a
        // lazy-loaded relation, and a mass-assignment that silently drops a
        // field. Missing-attribute strictness is deliberately left off — list
        // endpoints select narrow column sets on purpose.
        $strict = $this->app->isLocal() || $this->app->runningUnitTests();
        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);

        $this->configureRateLimiting();
    }

    /**
     * Named throttles used across the application. All three are configurable
     * through `.env` so an operator can loosen them for an internal network or
     * tighten them for an internet-facing portal.
     */
    private function configureRateLimiting(): void
    {
        // Two layers guard sign-in. LoginRequest throttles per account+IP with
        // a friendly message; this one is a coarser per-IP backstop against
        // someone spraying many different usernames from one address, so it
        // sits well above the per-account limit.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(
            (int) config('ticktz.rate_limits.login') * 4
        )->by($request->ip()));

        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute(
            (int) config('ticktz.rate_limits.portal')
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('ticktz.rate_limits.api')
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Pasting images into an editor. The one endpoint in the application
        // where a keystroke becomes a file on disk, so it gets a ceiling of
        // its own — generous enough for somebody assembling a walkthrough out
        // of a dozen screenshots, low enough that a script cannot fill the
        // volume while nobody is looking. Per person, not per IP: an office
        // behind one address is many people.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(
            (int) config('ticktz.rate_limits.uploads')
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // The public API throttles per *token*, not per user. Two integrations
        // owned by the same service account are two callers, and one of them
        // polling in a loop must not be able to lock the other out. A token may
        // carry its own ceiling; without one it gets the instance default.
        //
        // Keying by token id also means revoking a token frees its bucket,
        // rather than leaving an exhausted counter behind under a user id that
        // the replacement token would inherit.
        // The installer. Unauthenticated, and one of its endpoints opens a
        // connection to whatever host and port it is handed — so the limit is
        // per IP and deliberately low. A human filling in a form tests a
        // connection a handful of times; anything using it to sweep a network
        // hits the wall almost immediately.
        RateLimiter::for('install', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('api-token', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();

            if ($token === null) {
                // No token — an unauthenticated request on its way to a 401,
                // or the UI calling its own API with a session. Throttle by IP
                // so a token-guessing loop still meets a wall.
                return Limit::perMinute((int) config('ticktz.rate_limits.api'))->by($request->ip());
            }

            $perMinute = $token->rate_limit ?? (int) config('ticktz.rate_limits.api');

            return Limit::perMinute(max(1, (int) $perMinute))->by('token:'.$token->getKey());
        });
    }
}
