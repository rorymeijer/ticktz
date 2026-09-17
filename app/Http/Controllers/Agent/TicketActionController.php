<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The verbs on a ticket that are not "edit a field": assign, transition,
 * watch, link.
 *
 * Each is its own endpoint rather than a mode of the update endpoint, so the
 * permission check, the audit entry and the emitted event are unambiguous.
 */
class TicketActionController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('assign', $ticket);

        $data = $request->validate([
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $assignee = $data['assignee_id'] ? User::query()->find($data['assignee_id']) : null;

        if ($assignee && ! $assignee->isAgent()) {
            throw ValidationException::withMessages([
                'assignee_id' => __('tickets.errors.assignee_not_agent'),
            ]);
        }

        $this->tickets->assign($ticket, $assignee, $request->user());

        return back()->with('success', __($assignee ? 'tickets.flash.assigned' : 'tickets.flash.unassigned'));
    }

    /**
     * Claim a ticket for yourself — the single most common action in a queue.
     */
    public function claim(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('assign', $ticket);

        $this->tickets->assign($ticket, $request->user(), $request->user());

        return back()->with('success', __('tickets.flash.claimed'));
    }

    public function transition(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('transition', $ticket);

        $data = $request->validate([
            'status_id' => ['required', 'integer', Rule::exists('ticket_statuses', 'id')],
            'comment' => ['nullable', 'string', 'max:100000'],
        ]);

        $target = TicketStatus::query()->findOrFail($data['status_id']);

        $transition = $ticket->workflow->transitionsFrom($ticket->status)
            ->firstWhere('to_status_id', $target->getKey());

        if ($transition?->required_permission && ! $request->user()->hasPermission($transition->required_permission)) {
            abort(403);
        }

        if ($transition?->requires_comment && blank($data['comment'] ?? null)) {
            throw ValidationException::withMessages(['comment' => __('tickets.errors.comment_required')]);
        }

        if ($transition?->requires_assignee && ! $ticket->isAssigned()) {
            throw ValidationException::withMessages(['status_id' => __('tickets.errors.assignee_required')]);
        }

        try {
            $this->tickets->transition($ticket, $target, $request->user(), $data['comment'] ?? null);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['status_id' => $exception->getMessage()]);
        }

        return back()->with('success', __('tickets.flash.transitioned', ['status' => $target->name]));
    }

    public function watch(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('watch', $ticket);

        $this->tickets->addWatcher($ticket, $request->user());

        return back()->with('success', __('tickets.flash.watching'));
    }

    public function unwatch(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('watch', $ticket);

        $this->tickets->removeWatcher($ticket, $request->user());

        return back()->with('success', __('tickets.flash.not_watching'));
    }

    public function addWatcher(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $this->tickets->addWatcher($ticket, User::query()->findOrFail($data['user_id']));

        return back()->with('success', __('tickets.flash.watcher_added'));
    }

    public function removeWatcher(Request $request, Ticket $ticket, User $user): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $this->tickets->removeWatcher($ticket, $user);

        return back()->with('success', __('tickets.flash.watcher_removed'));
    }

    public function link(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('link', $ticket);

        $data = $request->validate([
            'related_key' => ['required', 'string', 'max:32'],
            'type' => ['required', Rule::in(TicketLink::TYPES)],
        ]);

        $related = Ticket::query()->where('key', mb_strtoupper(trim($data['related_key'])))->first();

        if (! $related) {
            throw ValidationException::withMessages(['related_key' => __('tickets.errors.link_not_found')]);
        }

        if ($related->is($ticket)) {
            throw ValidationException::withMessages(['related_key' => __('tickets.errors.link_self')]);
        }

        $this->authorize('view', $related);

        $ticket->links()->firstOrCreate(
            ['related_ticket_id' => $related->getKey(), 'type' => $data['type']],
            ['created_by' => $request->user()->getKey()],
        );

        return back()->with('success', __('tickets.flash.linked', ['key' => $related->key]));
    }

    public function unlink(Request $request, Ticket $ticket, TicketLink $link): RedirectResponse
    {
        $this->authorize('link', $ticket);

        abort_unless((int) $link->ticket_id === $ticket->getKey(), 404);

        $link->delete();

        return back()->with('success', __('tickets.flash.unlinked'));
    }
}
