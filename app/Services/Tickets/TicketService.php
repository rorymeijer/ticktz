<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketCommented;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketTransitioned;
use App\Events\Tickets\TicketUpdated;
use App\Models\ApprovalRequest;
use App\Models\Comment;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Models\Workflow;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every state change a ticket can undergo.
 *
 * Controllers validate and authorise; this class owns the invariants —
 * numbering, lifecycle timestamps, watcher bookkeeping, the audit trail and
 * the domain events later phases listen to. Nothing writes to `tickets`
 * outside this service.
 */
class TicketService
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Ticket
    {
        $workflow = $this->resolveWorkflow($attributes['workflow_id'] ?? null);
        $status = $this->resolveInitialStatus($workflow, $attributes['status_id'] ?? null);
        $priority = $this->resolvePriority($attributes['priority_id'] ?? null);

        /** @var User $requester */
        $requester = $attributes['requester'] ?? User::query()->findOrFail($attributes['requester_id']);

        $ticket = DB::transaction(function () use ($attributes, $workflow, $status, $priority, $requester, $actor): Ticket {
            $sequence = $this->numbers->next();

            $ticket = new Ticket([
                'subject' => trim((string) $attributes['subject']),
                'description' => $attributes['description'] ?? null,
                'status_id' => $status->getKey(),
                'priority_id' => $priority->getKey(),
                'workflow_id' => $workflow->getKey(),
                'queue_id' => $attributes['queue_id'] ?? null,
                'request_type_id' => $attributes['request_type_id'] ?? null,
                'email_channel_id' => $attributes['email_channel_id'] ?? null,
                'requester_id' => $requester->getKey(),
                'assignee_id' => $attributes['assignee_id'] ?? null,
                'team_id' => $attributes['team_id'] ?? null,
                // A ticket inherits its requester's organisation so that
                // organisation-wide visibility works without a second lookup.
                'organization_id' => $attributes['organization_id'] ?? $requester->organization_id,
                'created_by' => $actor?->getKey(),
                'source' => $attributes['source'] ?? 'agent',
            ]);

            $ticket->forceFill([
                'key' => $sequence['key'],
                'prefix' => $sequence['prefix'],
                'number' => $sequence['number'],
                'last_activity_at' => now(),
            ])->save();

            if (! empty($attributes['label_ids'])) {
                $ticket->labels()->sync($attributes['label_ids']);
            }

            $this->addWatcher($ticket, $requester, automatic: true);

            if ($actor && ! $actor->is($requester)) {
                $this->addWatcher($ticket, $actor, automatic: true);
            }

            return $ticket;
        });

        $ticket->load(['status', 'priority', 'requester', 'assignee', 'team', 'labels']);

        $this->auditAs($actor)->created($ticket, "Created ticket {$ticket->key}: {$ticket->subject}");

        TicketCreated::dispatch($ticket, $actor);

        if ($ticket->assignee_id) {
            TicketAssigned::dispatch($ticket, $ticket->assignee, null, $actor, true);
        }

        return $ticket;
    }

    /**
     * Update the editable fields of a ticket. Status changes do not go through
     * here — they must respect the workflow, so they go through transition().
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Ticket $ticket, array $attributes, ?User $actor = null): Ticket
    {
        $editable = Arr::only($attributes, [
            'subject', 'description', 'priority_id', 'queue_id', 'team_id', 'organization_id',
        ]);

        // Loaded rather than assumed: a service method must not depend on how
        // its caller fetched the model. An automation rule operating on a
        // ticket it just looked up by id has no relations loaded, and the
        // lazy load that used to happen here was an N+1 in production and a
        // hard failure in development.
        $ticket->loadMissing('assignee');

        $previousAssignee = $ticket->assignee;
        $assigneeChanged = array_key_exists('assignee_id', $attributes)
            && (int) $attributes['assignee_id'] !== (int) $ticket->assignee_id;

        if ($assigneeChanged) {
            $editable['assignee_id'] = $attributes['assignee_id'] ?: null;
        }

        $ticket->fill($editable);

        if (! $ticket->isDirty() && ! array_key_exists('label_ids', $attributes)) {
            return $ticket;
        }

        $original = $ticket->getOriginal();
        $ticket->forceFill(['last_activity_at' => now()])->save();

        if (array_key_exists('label_ids', $attributes)) {
            $ticket->labels()->sync($attributes['label_ids'] ?? []);
            $ticket->load('labels');
        }

        $changes = Arr::except($ticket->getChanges(), ['updated_at', 'last_activity_at']);

        if ($changes !== []) {
            $this->auditAs($actor)->updated($ticket, "Updated ticket {$ticket->key}");

            TicketUpdated::dispatch(
                $ticket,
                $changes,
                Arr::only($original, array_keys($changes)),
                $actor,
            );
        }

        if ($assigneeChanged) {
            $ticket->load('assignee');
            TicketAssigned::dispatch($ticket, $ticket->assignee, $previousAssignee, $actor);

            if ($ticket->assignee) {
                $this->addWatcher($ticket, $ticket->assignee, automatic: true);
            }
        }

        return $ticket;
    }

    public function assign(Ticket $ticket, ?User $assignee, ?User $actor = null): Ticket
    {
        // Same reason as update(): the caller may have fetched this ticket
        // without its relations, and this method must work either way.
        $ticket->loadMissing('assignee');

        $previous = $ticket->assignee;

        if ((int) $ticket->assignee_id === (int) $assignee?->getKey()) {
            return $ticket;
        }

        $ticket->forceFill([
            'assignee_id' => $assignee?->getKey(),
            'last_activity_at' => now(),
        ])->save();

        $ticket->setRelation('assignee', $assignee);

        $this->auditAs($actor)->log(
            $ticket,
            $assignee ? 'ticket.assigned' : 'ticket.unassigned',
            $assignee
                ? "Assigned {$ticket->key} to {$assignee->name}"
                : "Unassigned {$ticket->key}",
            ['assignee_id' => $previous?->getKey()],
            ['assignee_id' => $assignee?->getKey()],
        );

        if ($assignee) {
            $this->addWatcher($ticket, $assignee, automatic: true);
        }

        TicketAssigned::dispatch($ticket, $assignee, $previous, $actor);

        return $ticket;
    }

    /**
     * Move a ticket to a new status, enforcing the workflow.
     *
     * @throws RuntimeException when the transition is not legal
     */
    public function transition(Ticket $ticket, TicketStatus $target, ?User $actor = null, ?string $comment = null): Ticket
    {
        $from = $ticket->status;

        if ($from->is($target)) {
            return $ticket;
        }

        $ticket->loadMissing('workflow.transitions', 'workflow.statuses');

        $allowed = $ticket->workflow
            ->transitionsFrom($from)
            ->first(fn ($transition) => $transition->to_status_id === $target->getKey());

        if ($allowed === null) {
            throw new RuntimeException(__('tickets.errors.illegal_transition', [
                'from' => $from->name,
                'to' => $target->name,
            ]));
        }

        // The approval gate. Enforced here rather than in the controller so an
        // automation rule, an inbound e-mail and an agent's click all meet the
        // same wall — a gate the API can walk around is not a gate. The state
        // is a cached column on the ticket, maintained by ApprovalService, so
        // this costs nothing on a transition that is not gated.
        if ($allowed->requires_approval && $ticket->approval_state !== ApprovalRequest::APPROVED) {
            throw new RuntimeException(__('tickets.errors.approval_required', [
                'to' => $target->name,
            ]));
        }

        $timestamps = ['status_id' => $target->getKey(), 'last_activity_at' => now()];

        // Lifecycle bookkeeping. A ticket that comes back from resolved counts
        // as reopened, which reporting and SLAs both care about.
        if ($target->category === TicketStatus::CATEGORY_RESOLVED) {
            $timestamps['resolved_at'] = now();
        }

        if ($target->category === TicketStatus::CATEGORY_CLOSED) {
            $timestamps['closed_at'] = now();
            $timestamps['resolved_at'] = $ticket->resolved_at ?? now();
        }

        if ($from->isResolved() && ! $target->isResolved()) {
            $timestamps['reopened_at'] = now();
            $timestamps['resolved_at'] = null;
            $timestamps['closed_at'] = null;
            $timestamps['reopen_count'] = $ticket->reopen_count + 1;
        }

        $ticket->forceFill($timestamps)->save();
        $ticket->setRelation('status', $target);

        if (filled($comment)) {
            $this->comment($ticket, $comment, $actor, internal: false);
        }

        $this->auditAs($actor)->log(
            $ticket,
            'ticket.transitioned',
            "Moved {$ticket->key} from {$from->name} to {$target->name}",
            ['status_id' => $from->getKey()],
            ['status_id' => $target->getKey()],
        );

        TicketTransitioned::dispatch($ticket, $from, $target, $actor);

        return $ticket;
    }

    public function comment(
        Ticket $ticket,
        string $body,
        ?User $author = null,
        bool $internal = false,
        string $source = 'web',
        ?string $messageId = null,
    ): Comment {
        $comment = $ticket->comments()->create([
            'user_id' => $author?->getKey(),
            'body' => $body,
            'is_internal' => $internal,
            'source' => $source,
            'email_message_id' => $messageId,
        ]);

        $touch = ['last_activity_at' => now()];

        if (! $internal) {
            $isRequester = $author !== null && (int) $author->getKey() === (int) $ticket->requester_id;

            if ($isRequester) {
                $touch['last_requester_reply_at'] = now();
            } else {
                $touch['last_public_reply_at'] = now();
                // The first public reply from anyone but the requester is the
                // first response the SLA measures.
                $touch['first_response_at'] = $ticket->first_response_at ?? now();
            }
        }

        $ticket->forceFill($touch)->save();

        if ($author) {
            $this->addWatcher($ticket, $author, automatic: true);
        }

        $this->auditAs($author)->log(
            $ticket,
            $internal ? 'ticket.note_added' : 'ticket.replied',
            $internal ? "Added an internal note to {$ticket->key}" : "Replied to {$ticket->key}",
            context: ['comment_id' => $comment->getKey()],
        );

        $comment->load('author');

        TicketCommented::dispatch($ticket, $comment, $author);

        return $comment;
    }

    /**
     * Attribute the entry to the acting user.
     *
     * Outside an HTTP request — a seeder, a console command, inbound e-mail —
     * there is no authenticated user for the logger to pick up, so the caller's
     * actor has to be passed explicitly or the trail reads "System" for work a
     * person did.
     */
    private function auditAs(?User $actor): AuditLogger
    {
        return $actor ? $this->audit->actingAs($actor) : $this->audit;
    }

    public function addWatcher(Ticket $ticket, User $user, bool $automatic = false): void
    {
        $ticket->watchers()->syncWithoutDetaching([
            $user->getKey() => ['is_automatic' => $automatic],
        ]);
    }

    public function removeWatcher(Ticket $ticket, User $user): void
    {
        $ticket->watchers()->detach($user->getKey());
    }

    private function resolveWorkflow(mixed $workflowId): Workflow
    {
        $workflow = $workflowId
            ? Workflow::query()->with('statuses')->find($workflowId)
            : null;

        $workflow ??= Workflow::query()->with('statuses')->where('is_default', true)->first()
            ?? Workflow::query()->with('statuses')->where('is_active', true)->first();

        if (! $workflow) {
            throw new RuntimeException('No workflow is configured. Run the seeders.');
        }

        return $workflow;
    }

    private function resolveInitialStatus(Workflow $workflow, mixed $statusId): TicketStatus
    {
        if ($statusId) {
            $status = $workflow->statuses->firstWhere('id', (int) $statusId);

            if ($status) {
                return $status;
            }
        }

        $status = $workflow->initialStatus();

        if (! $status) {
            throw new RuntimeException("Workflow [{$workflow->slug}] has no statuses.");
        }

        return $status;
    }

    private function resolvePriority(mixed $priorityId): Priority
    {
        $priority = $priorityId ? Priority::query()->find($priorityId) : null;
        $priority ??= Priority::default();

        if (! $priority) {
            throw new RuntimeException('No priorities are configured. Run the seeders.');
        }

        return $priority;
    }
}
