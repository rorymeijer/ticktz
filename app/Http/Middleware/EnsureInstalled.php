<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Install\InstallationState;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an un-installed instance to the wizard.
 *
 * Without this, a fresh deployment answers every URL with a database error, or
 * worse with a login form nobody can get past because there are no accounts —
 * and the person deploying has no way to tell which of those it is.
 *
 * The API answers with JSON instead of a redirect: a script that gets a 302 to
 * an HTML setup page has learned nothing, while `503 not_installed` is
 * actionable.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallationState::isInstalled()) {
            return $next($request);
        }

        // The health endpoint has to keep answering. An orchestrator watching
        // it during a first deployment should see an unhealthy instance, not
        // a redirect it will follow into an HTML page.
        if ($request->is('health', 'up', 'install', 'install/*')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'not_installed',
                    'message' => 'This Ticktz instance has not been set up yet. Open it in a browser to finish installation.',
                ],
            ], 503);
        }

        return redirect()->route('install.show');
    }
}
