<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTokenScope;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackApiToken;
use App\Support\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Mounted at /api, versioned inside the file. Its own stack: no
        // session, no CSRF, no cookies — an API request authenticates with a
        // token or not at all.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            SetLocale::class,
            TrackApiToken::class,
        ]);

        $middleware->alias([
            'scope' => EnsureTokenScope::class,
        ]);

        // Trust the reverse proxy that terminates TLS in the compose stack so
        // generated URLs and the `secure` cookie flag stay correct.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A client calling /api without an Accept header still gets JSON.
        // Laravel's default is to guess from the request, and an integration
        // that forgets the header receives an HTML error page it cannot parse
        // — which turns a clear 422 into "the API returned garbage".
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One error shape across the whole API, so a caller writes one
        // handler: {"error": {"code": ..., "message": ...}}. The default
        // Laravel body differs per exception type, and the codes here are
        // stable strings rather than class names that move when we refactor.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiExceptionRenderer::render($exception, $request);
        });
    })->create();
