<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Queue;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketFilter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Queue overview: every queue the agent may open, with a live count.
 */
class QueueController extends Controller
{
    public function __construct(private readonly TicketFilter $filter) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Queue::class);

        /** @var User $user */
        $user = $request->user();

        $queues = Queue::query()
            ->availableTo($user)
            ->with('team:id,name')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $summaries = $queues->map(function (Queue $queue) use ($user): array {
            $count = $this->filter
                ->applyQueue(Ticket::query()->visibleTo($user), $queue, $user)
                ->count();

            return $queue->toSummaryArray() + ['ticket_count' => $count];
        });

        return Inertia::render('Agent/Queues/Index', [
            'queues' => $summaries->all(),
            'canManage' => $user->can('create', Queue::class),
        ]);
    }
}
