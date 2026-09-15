<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AuditLogger;
use App\Services\Automation\AutomationGuard;
use App\Services\SettingsRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);
        $this->app->singleton(AuditLogger::class);
        // The automation loop guard holds the state of the cascade currently
        // running. Every collaborator has to see the same instance or a rule
        // cannot tell that it caused the change it is now reacting to.
        $this->app->singleton(AutomationGuard::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

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
    }
}
