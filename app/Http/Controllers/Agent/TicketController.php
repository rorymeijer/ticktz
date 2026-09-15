<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Queue;
use App\Models\SlaEvent;
use App\Models\SlaTimer;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Models\TicketStatus;
use App\Models\User;
use App\Models\WorkflowTransition;
use App\Services\AuditLogger;
use App\Services\Tickets\AttachmentService;
use App\Services\Tickets\TicketFilter;
use App\Services\Tickets\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The agent console: the ticket list and the ticket detail page.
 */
class TicketController extends Controller
{
    /**
     * Audit events that belong in the ticket thread. Everything else the audit
     * log records (field edits, label changes) stays in the audit screen.
     *
     * @var array<int, string>
     */
    private const TIMELINE_EVENTS = ['created', 'ticket.assigned', 'ticket.unassigned', 'ticket.transitioned'];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketFilter $filter,
        private readonly AttachmentService $attachments,
    ) {}

    public function index(Request $request): Response
    {
        // `tickets.view` is enforced for the whole console by the route group.

        /** @var User $user */
        $user = $request->user();

        $queue = $request->filled('queue_slug')
            ? Queue::query()->availableTo($user)->where('slug', $request->string('queue_slug'))->first()
            : null;

        if ($queue) {
            $this->authorize('view', $queue);
        }

        $filters = $this->requestFilters($request);

        $query = Ticket::query()
            ->visibleTo($user)
            ->with([
                'status', 'priority', 'requester', 'assignee', 'team', 'organization', 'labels',
                // The badge reads the tightest live clock off the ticket, and
                // falls back to a missed one when nothing is still running, so
                // both are loaded — one eager load rather than a query per row.
                'slaTimers' => fn ($timers) => $timers
                    ->where(fn ($query) => $query
                        ->whereIn('status', SlaTimer::LIVE)
                        ->orWhereNotNull('breached_at'))
                    ->with('calendar'),
            ]);

        if ($queue) {
            $this->filter->applyQueue($query, $queue, $user);
        }

        $this->filter->apply($query, $filters, $user);

        $this->filter->applySort(
            $query,
            $request->string('sort_by')->toString() ?: $queue?->sort_by,
            $request->string('sort_direction')->toString() ?: $queue?->sort_direction,
        );

        $tickets = $query
            ->paginate(config('ticktz.per_page.tickets'))
            ->withQueryString()
            ->through(fn (Ticket $ticket) => $ticket->toListArray());

        return Inertia::render('Agent/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'sort' => [
                'by' => $request->string('sort_by')->toString() ?: ($queue?->sort_by ?? 'updated_at'),
                'direction' => $request->string('sort_direction')->toString() ?: ($queue?->sort_direction ?? 'desc'),
            ],
            'queue' => $queue?->toSummaryArray(),
            'queues' => Queue::query()
                ->availableTo($user)
                ->with('team:id,name')
                ->orderBy('position')
                ->get()
                ->map(fn (Queue $item) => $item->toSummaryArray())
                ->all(),
            'options' => $this->filterOptions(),
            'can' => [
                'create' => $user->can('create', Ticket::class),
                'export' => $user->can('export', Ticket::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('tickets.create');

        return Inertia::render('Agent/Tickets/Create', [
            'options' => $this->filterOptions() + [
                'requesters' => $this->requesterOptions($request),
            ],
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $this->authorize('tickets.create');

        $data = $request->validated();

        $ticket = $this->tickets->create($data, $request->user());

        if ($request->hasFile('attachments')) {
            $this->attachments->storeMany($ticket, $request->file('attachments'), $request->user());
        }

        return redirect()
            ->route('agent.tickets.show', $ticket)
            ->with('success', __('tickets.flash.created', ['key' => $ticket->key]));
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();
        $canSeeInternal = $user->can('viewInternal', $ticket);

        $ticket->load([
            'status', 'priority', 'workflow.transitions.toStatus', 'workflow.statuses',
            'queue:id,name,slug', 'requester.organization:id,name', 'assignee', 'team:id,name',
            'organization:id,name', 'labels', 'watchers',
            'links.relatedTicket.status', 'inverseLinks.ticket.status',
            'slaTimers.calendar', 'slaPolicy.calendar',
        ]);

        $comments = $ticket->comments()
            ->with(['author', 'attachments'])
            ->when(! $canSeeInternal, fn ($query) => $query->where('is_internal', false))
            ->get()
            ->map(fn (Comment $comment) => $comment->toDisplayArray());

        return Inertia::render('Agent/Tickets/Show', [
            'ticket' => $this->detailPayload($ticket, $user),
            'timeline' => $this->timeline($ticket, $comments->all()),
            'transitions' => $this->availableTransitions($ticket, $user),
            'options' => $this->filterOptions() + ['assignees' => $this->assigneeOptions()],
            'can' => [
                'update' => $user->can('update', $ticket),
                'assign' => $user->can('assign', $ticket),
                'transition' => $user->can('transition', $ticket),
                'comment' => $user->can('comment', $ticket),
                'comment_internal' => $user->can('commentInternally', $ticket),
                'link' => $user->can('link', $ticket),
                'delete' => $user->can('delete', $ticket),
            ],
            'isWatching' => $ticket->watchers->contains('id', $user->getKey()),
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $this->tickets->update($ticket, $request->validated(), $request->user());

        return back()->with('success', __('tickets.flash.updated'));
    }

    public function destroy(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('delete', $ticket);

        app(AuditLogger::class)->deleted($ticket, "Deleted ticket {$ticket->key}");
        $ticket->delete();

        return redirect()->route('agent.tickets.index')->with('success', __('tickets.flash.deleted'));
    }

    // -----------------------------------------------------------------
    // Payload helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Ticket $ticket, User $user): array
    {
        return $ticket->toListArray() + [
            'description' => $ticket->description,
            'queue' => $ticket->queue?->only('id', 'name', 'slug'),
            'workflow' => ['id' => $ticket->workflow_id, 'name' => $ticket->workflow->name],
            'watchers' => $ticket->watchers->map(fn (User $watcher) => $watcher->toSummaryArray())->all(),
            'first_response_at' => $ticket->first_response_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'reopen_count' => $ticket->reopen_count,
            'links' => $this->linkPayload($ticket, $user),
            'sla_timers' => $ticket->slaTimers
                ->sortBy('metric')
                ->map(fn (SlaTimer $timer) => $timer->toDisplayArray())
                ->values()
                ->all(),
            'sla_events' => $ticket->slaEvents()
                ->with('timer:id,metric')
                ->latest('occurred_at')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (SlaEvent $event) => $event->toDisplayArray())
                ->all(),
            'sla_policy' => $ticket->slaPolicy ? [
                'id' => $ticket->slaPolicy->id,
                'name' => $ticket->slaPolicy->name,
                'calendar' => $ticket->slaPolicy->calendar?->name,
            ] : null,
        ];
    }

    /**
     * Links in both directions, presented from this ticket's point of view.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linkPayload(Ticket $ticket, User $user): array
    {
        $outgoing = $ticket->links
            ->filter(fn (TicketLink $link) => $link->relatedTicket && $user->can('view', $link->relatedTicket))
            ->map(fn (TicketLink $link) => [
                'id' => $link->id,
                'type' => $link->type,
                'ticket' => [
                    'key' => $link->relatedTicket->key,
                    'subject' => $link->relatedTicket->subject,
                    'status' => $link->relatedTicket->status?->toSummaryArray(),
                ],
            ]);

        $incoming = $ticket->inverseLinks
            ->filter(fn (TicketLink $link) => $link->ticket && $user->can('view', $link->ticket))
            ->map(fn (TicketLink $link) => [
                'id' => $link->id,
                'type' => TicketLink::inverseType($link->type),
                'ticket' => [
                    'key' => $link->ticket->key,
                    'subject' => $link->ticket->subject,
                    'status' => $link->ticket->status?->toSummaryArray(),
                ],
            ]);

        return $outgoing->concat($incoming)->values()->all();
    }

    /**
     * The conversation interleaved with the system events from the audit log,
     * oldest first — the way a person reads a thread.
     *
     * Ordering uses the audit log's own id as the tie-breaker. Timestamps only
     * have second precision, so a reply and the status change it triggered
     * routinely share one; the audit sequence is the record of what actually
     * happened first.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<int, array<string, mixed>>
     */
    private function timeline(Ticket $ticket, array $comments): array
    {
        $entries = AuditLogEntry::query()
            ->forSubject($ticket)
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get();

        // Every comment is accompanied by an audit entry carrying its id; that
        // entry's position is the comment's position in the thread.
        $commentSequence = $entries
            ->filter(fn (AuditLogEntry $entry) => isset($entry->context['comment_id']))
            ->mapWithKeys(fn (AuditLogEntry $entry) => [(int) $entry->context['comment_id'] => $entry->id])
            ->all();

        $items = array_map(
            fn (array $comment) => $comment + ['sequence' => $commentSequence[$comment['id']] ?? PHP_INT_MAX],
            $comments,
        );

        foreach ($entries as $entry) {
            if (! in_array($entry->event, self::TIMELINE_EVENTS, true)) {
                continue;
            }

            $items[] = [
                'id' => 'event-'.$entry->id,
                'type' => 'event',
                'event' => $entry->event,
                'description' => $entry->description,
                'actor' => $entry->user?->toSummaryArray()
                    ?? ['name' => $entry->actor_label ?? __('tickets.timeline.system')],
                'created_at' => $entry->created_at?->toIso8601String(),
                'sequence' => $entry->id,
            ];
        }

        usort($items, fn (array $a, array $b) => [$a['created_at'], $a['sequence']] <=> [$b['created_at'], $b['sequence']]);

        return array_map(
            static fn (array $item) => Arr::except($item, ['sequence']),
            $items,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableTransitions(Ticket $ticket, User $user): array
    {
        if (! $user->can('transition', $ticket)) {
            return [];
        }

        return $ticket->workflow
            ->transitionsFrom($ticket->status)
            ->filter(fn (WorkflowTransition $transition) => $transition->required_permission === null
                || $user->hasPermission($transition->required_permission))
            ->map(fn (WorkflowTransition $transition) => $transition->toSummaryArray())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestFilters(Request $request): array
    {
        $filters = [];

        foreach (TicketFilter::KEYS as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->input($key);
            }
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'statuses' => TicketStatus::query()->orderBy('position')->get()
                ->map(fn (TicketStatus $status) => $status->toSummaryArray())->all(),
            'priorities' => Priority::query()->orderBy('level')->get()
                ->map(fn (Priority $priority) => $priority->toSummaryArray())->all(),
            'labels' => Label::query()->orderBy('name')->get()
                ->map(fn (Label $label) => $label->toSummaryArray())->all(),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'sources' => Ticket::SOURCES,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assigneeOptions(): array
    {
        return User::query()
            ->active()
            ->agents()
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn (User $user) => $user->toSummaryArray())
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requesterOptions(Request $request): array
    {
        return User::query()
            ->active()
            ->search($request->string('requester_search')->toString())
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (User $user) => $user->toSummaryArray() + [
                'organization_id' => $user->organization_id,
            ])
            ->all();
    }
}
