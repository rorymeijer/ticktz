<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\DailyMetric;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What needs this person's attention today.
 *
 * Deliberately not a reporting screen. Reporting answers "how are we doing";
 * this answers "what should I do next", so every figure is a link to a list
 * somebody can act on, and nothing here is an aggregate over a period.
 *
 * The one exception is the fortnight sparkline, which comes out of the daily
 * rollups rather than the ticket table — a dashboard people leave open all day
 * should not run a grouped aggregate over the whole desk every time it loads.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'cards' => $this->cards($user),
            'recent' => $this->recent($user),
            'trend' => $user->can('reports.view') ? $this->trend() : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cards(User $user): array
    {
        $cards = [];

        if ($user->can('tickets.view')) {
            $cards[] = [
                'key' => 'assigned_to_me',
                // `open()` rather than a status-id list: `is_open` is a
                // derived attribute, not a column, so filtering on it matches
                // nothing at all — silently, which on a dashboard means every
                // figure reads zero and looks like a quiet day.
                'count' => Ticket::query()
                    ->where('assignee_id', $user->getKey())
                    ->open()
                    ->count(),
                'href' => '/agent/tickets?assignee=me',
                'tone' => 'default',
            ];

            $cards[] = [
                'key' => 'unassigned',
                'count' => Ticket::query()
                    ->whereNull('assignee_id')
                    ->open()
                    ->count(),
                'href' => '/agent/tickets?queue_slug=unassigned',
                'tone' => 'default',
            ];

            // Breaching soon is the one number on this page that is genuinely
            // urgent, so it is counted live rather than read from a rollup
            // that could be a quarter of an hour behind.
            $cards[] = [
                'key' => 'breaching_soon',
                'count' => SlaTimer::query()
                    ->whereIn('status', SlaTimer::LIVE)
                    ->whereBetween('due_at', [now(), now()->addHours(4)])
                    ->count(),
                'href' => '/agent/tickets?sla=breaching',
                'tone' => 'warning',
            ];

            $cards[] = [
                'key' => 'breached',
                'count' => Ticket::query()
                    ->where('sla_breached', true)
                    ->open()
                    ->count(),
                'href' => '/agent/tickets?sla=breached',
                'tone' => 'danger',
            ];
        }

        $waiting = ApprovalRequest::query()->awaiting($user)->count();

        if ($waiting > 0 || $user->can('approvals.view')) {
            $cards[] = [
                'key' => 'awaiting_approval',
                'count' => $waiting,
                'href' => '/approvals',
                'tone' => $waiting > 0 ? 'warning' : 'default',
            ];
        }

        if (! $user->isAgent()) {
            $cards[] = [
                'key' => 'open_requests',
                'count' => Ticket::query()
                    ->where('requester_id', $user->getKey())
                    ->open()
                    ->count(),
                'href' => '/portal/requests',
                'tone' => 'default',
            ];
        }

        return $cards;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recent(User $user): array
    {
        if (! $user->can('tickets.view')) {
            return [];
        }

        return Ticket::query()
            ->visibleTo($user)
            // Everything `toListArray()` reaches for. Lazy loading is
            // prevented in development, so a missing relation here is a hard
            // failure on the first page after signing in.
            ->with(['status', 'priority', 'requester', 'assignee', 'team', 'organization', 'labels', 'slaTimers.calendar'])
            ->latest('last_activity_at')
            ->limit(8)
            ->get()
            ->map(fn (Ticket $ticket) => $ticket->toListArray())
            ->all();
    }

    /**
     * A fortnight of arrivals and departures, from the rollups.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trend(): array
    {
        $from = CarbonImmutable::today()->subDays(13);

        $rows = DailyMetric::query()
            ->whereIn('metric', [DailyMetric::TICKETS_CREATED, DailyMetric::TICKETS_RESOLVED])
            ->where('dimension', 'all')
            ->between($from->toDateString(), CarbonImmutable::today()->toDateString())
            ->get(['date', 'metric', 'count'])
            ->groupBy(fn (DailyMetric $row) => $row->date->toDateString());

        $series = [];

        for ($day = $from; $day->lessThanOrEqualTo(CarbonImmutable::today()); $day = $day->addDay()) {
            $date = $day->toDateString();
            $forDay = $rows->get($date);

            $series[] = [
                'date' => $date,
                'created' => (int) ($forDay?->firstWhere('metric', DailyMetric::TICKETS_CREATED)?->count ?? 0),
                'resolved' => (int) ($forDay?->firstWhere('metric', DailyMetric::TICKETS_RESOLVED)?->count ?? 0),
            ];
        }

        return $series;
    }
}
