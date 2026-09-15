<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal self-registration is off by default: on an internal service desk,
 * accounts come from the directory or from an administrator. Instances that
 * serve the general public turn it on in Settings -> Portal.
 */
class EnsureSelfRegistrationIsEnabled
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->bool('portal.allow_self_registration'), 404);

        return $next($request);
    }
}
