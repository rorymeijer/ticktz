<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\UiTranslations;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Bust the client-side asset cache when the build manifest changes.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every Inertia response.
     *
     * Keep this lean: it is serialised into *every* page payload. Anything
     * expensive is wrapped in a closure so Inertia can lazily resolve it.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),

            'app' => [
                'name' => config('app.name'),
                'version' => config('ticktz.version'),
            ],

            'auth' => [
                'user' => fn () => $request->user()?->toInertiaArray(),
            ],

            'locale' => $locale,
            'locales' => collect(config('ticktz.locales'))
                ->map(fn (array $meta, string $code) => ['code' => $code] + $meta)
                ->values()
                ->all(),
            'translations' => fn () => UiTranslations::for($locale),

            // Ziggy's route table plus the current URL. The layouts read
            // `ziggy.location` to decide which navigation item is active, so
            // this has to be present on every response, not just where the
            // `route()` helper is used.
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }
}
