<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\QueueResource;
use App\Models\Queue;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The saved views of the desk, read-only.
 *
 * Queues are configuration an administrator owns in the UI; an integration
 * reads them to discover how this desk is organised and then asks for the
 * tickets. Creating queues over the API would be a second way to shape the
 * console with none of the validation the admin screen does.
 *
 * The open counts are a single aggregate query, not one per queue.
 */
class QueueController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $queues = Queue::query()
            ->availableTo($user)
            ->with('team')
            ->withCount(['tickets as open_tickets_count' => fn ($query) => $query->whereHas(
                'status',
                fn ($status) => $status->whereIn('category', TicketStatus::OPEN_CATEGORIES),
            )])
            ->orderBy('position')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return QueueResource::collection($queues);
    }

    public function show(Request $request, Queue $queue): QueueResource
    {
        /** @var User $user */
        $user = $request->user();

        // The same scope the list uses, applied to one record: a queue the
        // caller could not have seen in the list must not be readable by id.
        abort_unless(
            Queue::query()->availableTo($user)->whereKey($queue->getKey())->exists(),
            404,
        );

        $queue->load('team');

        return QueueResource::make($queue);
    }
}
