<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the UI language for the current request.
 *
 * Precedence: the signed-in user's own preference, then an explicit `?lang=`
 * override (used by the public portal and by e-mail deep links), then the
 * browser's Accept-Language header, then the configured application default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('ticktz.locales', []));

        $requested = $request->query('lang');

        $locale = $this->firstSupported([
            $requested,
            $request->user()?->locale,
            $request->session()->get('locale'),
            $request->getPreferredLanguage($supported),
        ], $supported) ?? config('app.locale');

        // Remember an explicit choice for guests only. A signed-in user's
        // profile stays the source of truth: `?lang=` applies to that one
        // request (an e-mail deep link, a shared URL) and nothing more.
        if ($requested !== null && in_array($requested, $supported, true) && ! $request->user()) {
            $request->session()->put('locale', $requested);
        }

        app()->setLocale($locale);

        return $next($request);
    }

    /**
     * @param  array<int, mixed>  $candidates
     * @param  array<int, string>  $supported
     */
    private function firstSupported(array $candidates, array $supported): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
