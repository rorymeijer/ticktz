<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Install\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the installer once the instance is set up.
 *
 * This is the one that matters. The wizard is unauthenticated by necessity —
 * there is nobody to authenticate as yet — and it accepts database
 * credentials, writes `.env` and creates an administrator. Left reachable on a
 * running instance it is a complete takeover in three screens.
 *
 * So once installed the endpoints that *do* anything — testing a connection,
 * running the install — answer 404, as though the feature had never been
 * compiled in. There is deliberately no environment variable, query parameter
 * or header that re-opens them. Re-running the installer is a shell operation,
 * `php artisan ticktz:install --force`, which requires the access that
 * implies.
 *
 * Only the GET that renders the wizard is treated more kindly: it redirects to
 * the login page. It leaks nothing an installed instance does not already
 * serve publicly, and it is the difference between a bookmarked `/install` —
 * or a reload after a response went missing — landing somewhere useful rather
 * than on a 404 that reads like a broken deployment.
 */
class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! InstallationState::isInstalled()) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            return redirect()->route('login');
        }

        abort(404);
    }
}
