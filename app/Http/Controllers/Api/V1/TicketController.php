<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\StoreApiTicketRequest;
use App\Http\Requests\Api\UpdateApiTicketRequest;
use App\Http\Resources\Api\TicketResource;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Tickets\TicketFilter;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Tickets over the public API.
 *
 * Nothing here writes to a ticket directly: every change goes through
 * TicketService, the same path the console and the mail poller use. That is
 * what keeps numbering, watchers, the audit trail, the SLA clocks and the
 * approval gate identical no matter which door the change came in through. An
 * API that reimplements any of that is an API that drifts from the UI.
 *
 * Visibility is the model scope, not a filter written for this controller —
 * `scopeVisibleTo` is the single source of truth, and a second copy of those
 * rules living here is how a token starts seeing tickets the same person
 * cannot see in the browser.
 */
class TicketController extends ApiController
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketFilter $filter,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = Ticket::query()
            ->visibleTo($user)
            ->with(['status', 'priority', 'queue', 'requester', 'assignee', 'team', 'organization', 'labels']);

        $this->filter->apply($query, $request->all(), $user);

        $sort = in_array($request->string('sort')->toString(), ['created_at', 'updated_at', 'last_activity_at'], true)
            ? $request->string('sort')->toString()
            : 'last_activity_at';

        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        return TicketResource::collection(
            $query->orderBy($sort, $direction)
                ->paginate($this->perPage($request))
                ->withQueryString()
        );
    }

    public function show(Request $request, Ticket $ticket): TicketResource|JsonResponse
    {
        $this->assertVisible($request, $ticket);

        $ticket->load(['status', 'priority', 'queue', 'requester', 'assignee', 'team', 'organization', 'labels']);

        return TicketResource::make($ticket);
    }

    public function store(StoreApiTicketRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('create', Ticket::class);

        $data = $request->validated();

        // A token acting on behalf of somebody else has to be entitled to do
        // so. Without this check any token with `tickets.write` could file
        // tickets in a colleague's name, which is impersonation with an audit
        // trail that points at the wrong person.
        $requester = $this->resolveRequester($request, $user);

        if ($requester === null) {
            return $this->error('invalid_requester', __('api.errors.invalid_requester'), 422);
        }

        $ticket = $this->tickets->create([
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'requester' => $requester,
            'priority_id' => $data['priority_id'] ?? null,
            'queue_id' => $data['queue_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'request_type_id' => $data['request_type_id'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'label_ids' => $data['label_ids'] ?? [],
            // Stamped rather than taken from the caller: the source is how the
            // desk knows where its work comes from, and a caller that can
            // claim to be the portal makes that number meaningless.
            'source' => 'api',
        ], $user);

        $ticket->load(['status', 'priority', 'queue', 'requester', 'assignee', 'team', 'organization', 'labels']);

        return TicketResource::make($ticket)
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.tickets.show', $ticket->key));
    }

    public function update(UpdateApiTicketRequest $request, Ticket $ticket): TicketResource|JsonResponse
    {
        $this->assertVisible($request, $ticket);
        $this->authorize('update', $ticket);

        $data = $request->validated();

        if (array_key_exists('assignee_id', $data)) {
            // Assigning is its own permission, so a token that may edit a
            // subject does not thereby get to move work onto somebody's plate.
            $this->authorize('assign', $ticket);
        }

        $this->tickets->update($ticket, $data, $request->user());

        $ticket->load(['status', 'priority', 'queue', 'requester', 'assignee', 'team', 'organization', 'labels']);

        return TicketResource::make($ticket);
    }

    /**
     * Move a ticket through its workflow.
     *
     * Its own endpoint rather than a field on update() because it is not a
     * field edit: the workflow decides which moves are legal, and an approval
     * may be standing in the way. A PATCH that silently refused half its
     * payload would be worse than a 422 from here.
     */
    public function transition(Request $request, Ticket $ticket): TicketResource|JsonResponse
    {
        $this->assertVisible($request, $ticket);
        $this->authorize('transition', $ticket);

        $validated = $request->validate([
            'status' => ['required', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:100000'],
        ]);

        $target = TicketStatus::query()
            ->where('slug', $validated['status'])
            ->orWhere('id', ctype_digit($validated['status']) ? (int) $validated['status'] : 0)
            ->first();

        if ($target === null) {
            return $this->error('unknown_status', __('api.errors.unknown_status'), 422);
        }

        $ticket->loadMissing('status');

        try {
            $this->tickets->transition($ticket, $target, $request->user(), $validated['comment'] ?? null);
        } catch (RuntimeException $exception) {
            // The workflow refused, or an approval is outstanding. Both are the
            // caller asking for something that is not allowed *yet*, which is a
            // 422 with the reason, not a 500.
            return $this->error('transition_refused', $exception->getMessage(), 422);
        }

        $ticket->load(['status', 'priority', 'queue', 'requester', 'assignee', 'team', 'organization', 'labels']);

        return TicketResource::make($ticket);
    }

    /**
     * A ticket the caller cannot see is *not found*, not forbidden.
     *
     * Answering 403 for a ticket that exists and 404 for one that does not
     * tells an caller which keys are real — SUP-1 through SUP-9999 is a short
     * loop, and the answer is a map of the desk's volume. So visibility is
     * checked first and answers 404, and only then does the policy decide
     * whether the *action* is allowed, which is a genuine 403.
     *
     * The check runs the same scope the list endpoint uses, so the two cannot
     * drift apart.
     */
    private function assertVisible(Request $request, Ticket $ticket): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            Ticket::query()->visibleTo($user)->whereKey($ticket->getKey())->exists(),
            404,
        );
    }

    /**
     * Who the ticket is for. Defaults to the token's owner, which is what a
     * script filing its own tickets wants; naming somebody else needs the
     * permission to act on other people's behalf.
     */
    private function resolveRequester(Request $request, User $actor): ?User
    {
        $requested = $request->input('requester_id') ?? $request->input('requester_email');

        if ($requested === null) {
            return $actor;
        }

        if (! $actor->hasPermission('tickets.create')) {
            return null;
        }

        return User::query()
            ->when(
                is_numeric($requested),
                fn ($query) => $query->whereKey((int) $requested),
                fn ($query) => $query->where('email', (string) $requested),
            )
            ->where('is_active', true)
            ->first();
    }
}
