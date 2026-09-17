<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\CommentResource;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\RichText;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The conversation on a ticket.
 *
 * Internal notes are filtered out of the list for anyone without
 * `tickets.comment.internal`, and asking to *write* one without that
 * permission is refused rather than quietly downgraded to a public reply — a
 * note that was meant to be internal and went out to the customer is the one
 * mistake this endpoint must not make on the caller's behalf.
 */
class CommentController extends ApiController
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        // Not-found rather than forbidden, for the same reason as the ticket
        // endpoints: see TicketController::assertVisible().
        abort_unless(
            Ticket::query()->visibleTo($user)->whereKey($ticket->getKey())->exists(),
            404,
        );

        $comments = $ticket->comments()
            ->with('author')
            ->unless(
                $user->can('commentInternally', $ticket),
                fn ($query) => $query->where('is_internal', false),
            )
            ->orderBy('created_at')
            ->paginate($this->perPage($request));

        return CommentResource::collection($comments);
    }

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless(
            Ticket::query()->visibleTo($request->user())->whereKey($ticket->getKey())->exists(),
            404,
        );

        $this->authorize('comment', $ticket);

        $validated = $request->validate([
            'body' => ['required', 'string', new RichText],
            'internal' => ['sometimes', 'boolean'],
        ]);

        $internal = (bool) ($validated['internal'] ?? false);

        if ($internal && $request->user()?->cannot('commentInternally', $ticket)) {
            return $this->error('internal_not_allowed', __('api.errors.internal_not_allowed'), 403);
        }

        $comment = $this->tickets->comment(
            $ticket,
            $validated['body'],
            $request->user(),
            internal: $internal,
            source: 'api',
        );

        return CommentResource::make($comment)->response()->setStatusCode(201);
    }
}
