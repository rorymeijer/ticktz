<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreCommentRequest;
use App\Models\ReplyTemplate;
use App\Models\Ticket;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\TicketPlaceholders;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TicketCommentController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * The canned replies this agent may use on this ticket, already filled in.
     *
     * Rendering happens here rather than in the browser for two reasons. The
     * placeholder vocabulary lives on the server, and duplicating it in React
     * would give the feature two answers to "what does {{ requester.name }}
     * mean" — the agent's copy and the automation's would drift, which is the
     * one thing a shared template set exists to prevent. And the values come
     * from the ticket's relations, which the show page does not carry.
     *
     * Authorised as a comment, not as a read: a template is the words you are
     * about to send, so whoever may not send anything has no business seeing
     * the list.
     */
    public function templates(Request $request, Ticket $ticket, TicketPlaceholders $placeholders): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user?->can('comment', $ticket) || $user?->can('commentInternally', $ticket),
            403,
        );

        $canReply = $user->can('comment', $ticket);
        $canNote = $user->can('commentInternally', $ticket);

        $templates = ReplyTemplate::query()
            ->usableBy($user)
            ->forLocale($ticket->requester?->locale)
            ->get()
            // An agent who may only write notes is not offered the replies,
            // and vice versa. Offering a template that the save would refuse
            // is an invitation to write an answer and lose it.
            ->filter(fn (ReplyTemplate $template): bool => $template->is_internal ? $canNote : $canReply)
            ->map(fn (ReplyTemplate $template) => $template->toPickerArray(
                $placeholders->render($template->body, $ticket, $user, escape: true),
            ))
            ->values()
            ->all();

        return response()->json(['templates' => $templates]);
    }

    public function store(StoreCommentRequest $request, Ticket $ticket): RedirectResponse
    {
        $internal = $request->boolean('is_internal');

        $comment = $this->tickets->comment(
            $ticket,
            $request->string('body')->toString(),
            $request->user(),
            internal: $internal,
        );

        if ($request->hasFile('attachments')) {
            $this->attachments->storeMany($ticket, $request->file('attachments'), $request->user(), $comment);
        }

        return back()->with('success', __($internal ? 'tickets.flash.note_added' : 'tickets.flash.replied'));
    }
}
