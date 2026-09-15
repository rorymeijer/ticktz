<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SettingsRepository;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer portal landing page.
 *
 * Phase 3 fills this with request types, categories and "my requests"; for now
 * it gives requesters a home that is not the agent console.
 */
class PortalController extends Controller
{
    public function index(SettingsRepository $settings): Response
    {
        return Inertia::render('Portal/Index', [
            'branding' => [
                'title' => $settings->string('portal.title'),
                'welcome_message' => $settings->string('portal.welcome_message'),
                'support_email' => $settings->string('portal.support_email'),
            ],
        ]);
    }
}
