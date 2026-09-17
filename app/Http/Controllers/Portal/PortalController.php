<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalCategory;
use App\Models\RequestType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SettingsRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portal landing page: what you can ask for, and what you already asked.
 */
class PortalController extends Controller
{
    public function index(Request $request, SettingsRepository $settings): Response
    {
        /** @var User $user */
        $user = $request->user();

        $requestTypes = RequestType::query()
            ->availableTo($user)
            ->with('category')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $categories = PortalCategory::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get()
            ->map(fn (PortalCategory $category) => $category->toPortalArray() + [
                'request_types' => $requestTypes
                    ->where('portal_category_id', $category->getKey())
                    ->map(fn (RequestType $type) => $type->toPortalArray())
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $category) => $category['request_types'] !== [])
            ->values();

        return Inertia::render('Portal/Index', [
            'branding' => $this->branding($settings),
            'categories' => $categories,
            // Types without a category still have to be reachable.
            'uncategorised' => $requestTypes
                ->whereNull('portal_category_id')
                ->map(fn (RequestType $type) => $type->toPortalArray())
                ->values()
                ->all(),
            'recentRequests' => Ticket::query()
                ->visibleTo($user)
                ->with(['status', 'requester'])
                ->latest('last_activity_at')
                ->limit(5)
                ->get()
                ->map(fn (Ticket $ticket) => $this->requestSummary($ticket))
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function branding(SettingsRepository $settings): array
    {
        return [
            'title' => $settings->string('portal.title'),
            'welcome_message' => $settings->string('portal.welcome_message'),
            'support_email' => $settings->string('portal.support_email'),
            'primary_color' => $settings->string('portal.primary_color'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSummary(Ticket $ticket): array
    {
        return [
            'key' => $ticket->key,
            'subject' => $ticket->subject,
            'status' => $ticket->status?->toSummaryArray(),
            'requester' => $ticket->requester?->toSummaryArray(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'last_activity_at' => $ticket->last_activity_at?->toIso8601String(),
        ];
    }
}
