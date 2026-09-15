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

        $locale = $this->firstSupported([
            $request->user()?->locale,
            $request->query('lang'),
            $request->session()->get('locale'),
            $request->getPreferredLanguage($supported),
        ], $supported) ?? config('app.locale');

        if ($request->query('lang') && in_array($request->query('lang'), $supported, true)) {
            $request->session()->put('locale', $request->query('lang'));
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
