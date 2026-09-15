<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApprovalRequest;
use App\Models\Comment;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns a domain event into the JSON somebody else's server receives.
 *
 * Built by naming fields, never by handing over a model — the same rule the
 * API resources follow, and for the same reason: a column added next year must
 * not start appearing on an external endpoint because nobody remembered to
 * exclude it.
 *
 * Two deliberate omissions:
 *
 * - **Internal notes.** `ticket.commented` carries `is_internal` and, when the
 *   note is internal, no body. A webhook receiver has no policy to run and no
 *   user to check, so the only safe assumption is that whoever operates the
 *   endpoint is not entitled to the agent's side of the conversation.
 * - **Descriptions on update.** `ticket.updated` says *which* fields changed,
 *   not what they changed to, beyond the scalar ones. Anything more is a
 *   ticket export over a webhook.
 *
 * Every payload carries the same envelope — event, id, timestamp, data — so a
 * receiver can dispatch on one field and log the rest.
 */
class WebhookPayload
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function envelope(string $event, array $data): array
    {
        return [
            'event' => $event,
            // Not a delivery id: the same event fanned out to four endpoints
            // carries the same one, which is what lets a receiver that is
            // subscribed twice notice it.
            'id' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
            'instance' => config('app.url'),
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ticket(Ticket $ticket): array
    {
        $ticket->loadMissing(['status', 'priority', 'requester', 'assignee', 'team', 'queue', 'organization']);

        return [
            'id' => $ticket->getKey(),
            'key' => $ticket->key,
            'subject' => $ticket->subject,
            'source' => $ticket->source,
            'status' => $ticket->status?->slug,
            'status_category' => $ticket->status?->category,
            'priority' => $ticket->priority?->slug,
            'queue' => $ticket->queue?->slug,
            'team' => $ticket->team?->name,
            'organization' => $ticket->organization?->name,
            'requester' => self::person($ticket->requester),
            'assignee' => self::person($ticket->assignee),
            'approval_state' => $ticket->approval_state,
            'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'url' => rtrim((string) config('app.url'), '/').'/agent/tickets/'.$ticket->key,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function person(?User $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function created(Ticket $ticket, ?User $actor): array
    {
        return self::envelope('ticket.created', [
            'ticket' => self::ticket($ticket),
            'actor' => self::person($actor),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function updated(Ticket $ticket, array $changes, ?User $actor): array
    {
        return self::envelope('ticket.updated', [
            'ticket' => self::ticket($ticket),
            // Field names only. What a subject or description changed *to* is
            // a read of the ticket, which the receiver can do with a token if
            // it is entitled to one.
            'changed' => array_keys($changes),
            'actor' => self::person($actor),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function assigned(Ticket $ticket, ?User $assignee, ?User $previous, ?User $actor): array
    {
        return self::envelope('ticket.assigned', [
            'ticket' => self::ticket($ticket),
            'assignee' => self::person($assignee),
            'previous_assignee' => self::person($previous),
            'actor' => self::person($actor),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function transitioned(Ticket $ticket, string $from, string $to, ?User $actor): array
    {
        return self::envelope('ticket.transitioned', [
            'ticket' => self::ticket($ticket),
            'from' => $from,
            'to' => $to,
            'actor' => self::person($actor),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function commented(Ticket $ticket, Comment $comment, ?User $actor): array
    {
        return self::envelope('ticket.commented', [
            'ticket' => self::ticket($ticket),
            'comment' => [
                'id' => $comment->getKey(),
                'is_internal' => $comment->is_internal,
                'source' => $comment->source,
                // The flag always; the text only when it was public anyway.
                'body' => $comment->is_internal ? null : $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ],
            'actor' => self::person($actor),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function slaBreached(Ticket $ticket, SlaTimer $timer): array
    {
        return self::envelope('sla.breached', [
            'ticket' => self::ticket($ticket),
            'timer' => [
                'id' => $timer->getKey(),
                'metric' => $timer->metric,
                'target_minutes' => $timer->target_minutes,
                'due_at' => $timer->due_at?->toIso8601String(),
                'breached_at' => $timer->breached_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function approvalCompleted(Ticket $ticket, ApprovalRequest $approval, string $outcome): array
    {
        return self::envelope('approval.completed', [
            'ticket' => self::ticket($ticket),
            'approval' => [
                'id' => $approval->getKey(),
                'outcome' => $outcome,
                'completed_at' => $approval->completed_at?->toIso8601String(),
            ],
        ]);
    }
}
