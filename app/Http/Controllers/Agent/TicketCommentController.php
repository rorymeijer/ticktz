<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreCommentRequest;
use App\Models\Ticket;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\TicketService;
use Illuminate\Http\RedirectResponse;

class TicketCommentController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly AttachmentService $attachments,
    ) {}

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
