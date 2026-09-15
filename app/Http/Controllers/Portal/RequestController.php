<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My requests" — the requester's own view of their tickets.
 *
 * This controller is deliberately separate from the agent one. The payload is
 * built from scratch rather than filtered down from the agent payload: a view
 * that starts with everything and removes the sensitive parts is one edit away
 * from leaking them.
 */
class RequestController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly AttachmentService $attachments,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filter = $request->string('status')->toString();

        $requests = Ticket::query()
            ->visibleTo($user)
            ->with(['status', 'requester', 'assignee', 'requestType'])
            ->when($filter === 'open', fn ($query) => $query->whereHas(
                'status',
                fn ($status) => $status->whereIn('category', TicketStatus::OPEN_CATEGORIES)
            ))
            ->when($filter === 'closed', fn ($query) => $query->whereHas(
                'status',
                fn ($status) => $status->whereIn('category', ['resolved', 'closed'])
            ))
            ->latest('last_activity_at')
            ->paginate(config('ticktz.per_page.portal'))
            ->withQueryString()
            ->through(fn (Ticket $ticket) => [
                'key' => $ticket->key,
                'subject' => $ticket->subject,
                'status' => $ticket->status?->toSummaryArray(),
                'request_type' => $ticket->requestType?->translatedName(),
                'requester' => $ticket->requester?->toSummaryArray(),
                'is_mine' => (int) $ticket->requester_id === $user->getKey(),
                'created_at' => $ticket->created_at?->toIso8601String(),
                'last_activity_at' => $ticket->last_activity_at?->toIso8601String(),
            ]);

        return Inertia::render('Portal/Requests/Index', [
            'requests' => $requests,
            'filters' => ['status' => $filter ?: null],
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();

        $ticket->load(['status', 'priority', 'requestType', 'assignee', 'customFieldValues.field']);

        // Only public comments are ever loaded here. The portal has no concept
        // of an internal note.
        $comments = $ticket->comments()
            ->where('is_internal', false)
            ->with(['author', 'attachments'])
            ->get()
            ->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->author?->toSummaryArray() ?? ['name' => __('tickets.timeline.system')],
                'is_mine' => (int) $comment->user_id === $user->getKey(),
                'created_at' => $comment->created_at?->toIso8601String(),
                'attachments' => $comment->attachments
                    ->where('is_internal', false)
                    ->map(fn ($attachment) => $attachment->toSummaryArray())
                    ->values()
                    ->all(),
            ]);

        return Inertia::render('Portal/Requests/Show', [
            'request' => [
                'key' => $ticket->key,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'status' => $ticket->status?->toSummaryArray(),
                'priority' => $ticket->priority?->toSummaryArray(),
                'request_type' => $ticket->requestType?->translatedName(),
                'assignee' => $ticket->assignee?->toSummaryArray(),
                'created_at' => $ticket->created_at?->toIso8601String(),
                'resolved_at' => $ticket->resolved_at?->toIso8601String(),
                // Agents-only fields are filtered out for the portal.
                'fields' => $ticket->customFieldSummary(includePrivate: false),
                'attachments' => $ticket->attachments()
                    ->whereNull('comment_id')
                    ->where('is_internal', false)
                    ->get()
                    ->map(fn ($attachment) => $attachment->toSummaryArray())
                    ->all(),
            ],
            'comments' => $comments,
            'canComment' => $user->can('comment', $ticket),
        ]);
    }

    public function comment(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('comment', $ticket);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:65000'],
            'attachments' => ['array', 'max:5'],
            'attachments.*' => AttachmentService::rules(),
        ]);

        // A requester can never post an internal note, whatever the payload says.
        $comment = $this->tickets->comment($ticket, $data['body'], $request->user(), internal: false);

        if ($request->hasFile('attachments')) {
            $this->attachments->storeMany($ticket, $request->file('attachments'), $request->user(), $comment);
        }

        return back()->with('success', __('portal.flash.replied'));
    }
}
