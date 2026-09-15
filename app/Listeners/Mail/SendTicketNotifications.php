<?php

declare(strict_types=1);

namespace App\Listeners\Mail;

use App\Events\Tickets\TicketAssigned;
use App\Events\Tickets\TicketCommented;
use App\Events\Tickets\TicketCreated;
use App\Events\Tickets\TicketTransitioned;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Mail\TicketMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Turns domain events into e-mail.
 *
 * Queued, so a slow or unreachable SMTP server never delays the request that
 * created the ticket. Every handler decides *who* gets the mail; the mailer
 * decides what it says.
 *
 * Laravel discovers the handlers in this class by the event each `handle*`
 * method type-hints — do not also register it manually, or every notification
 * goes out twice.
 */
class SendTicketNotifications implements ShouldQueue
{
    public string $queue;

    public function __construct(private readonly TicketMailer $mailer)
    {
        $this->queue = (string) config('ticktz.queues.mail');
    }

    public function handleTicketCreated(TicketCreated $event): void
    {
        $ticket = $event->ticket;

        if ($ticket->requester) {
            // Always confirm to the requester, including when they filed it
            // themselves: the e-mail is their copy of the record, and it is
            // what they reply to when they remember another detail.
            $this->deliver(fn () => $this->mailer->send('ticket.created.requester', $ticket, $ticket->requester));
        }

        $this->deliver(fn () => $this->mailer->sendMany(
            'ticket.created.agent',
            $ticket,
            $this->agentAudience($ticket, exclude: $event->actor),
        ));
    }

    public function handleTicketCommented(TicketCommented $event): void
    {
        $ticket = $event->ticket;
        $comment = $event->comment;

        if ($comment->is_internal) {
            // Internal notes never leave the building.
            $this->deliver(fn () => $this->mailer->sendMany(
                'ticket.replied.agent',
                $ticket,
                $this->agentAudience($ticket, exclude: $event->actor),
                $comment,
            ));

            return;
        }

        $authorIsRequester = $comment->user_id !== null
            && (int) $comment->user_id === (int) $ticket->requester_id;

        if ($authorIsRequester) {
            $this->deliver(fn () => $this->mailer->sendMany(
                'ticket.replied.agent',
                $ticket,
                $this->agentAudience($ticket, exclude: $event->actor),
                $comment,
            ));

            return;
        }

        if ($ticket->requester) {
            $this->deliver(fn () => $this->mailer->send('ticket.replied.requester', $ticket, $ticket->requester, $comment));
        }

        $this->deliver(fn () => $this->mailer->sendMany(
            'ticket.replied.agent',
            $ticket,
            $this->agentAudience($ticket, exclude: $event->actor)
                ->reject(fn (User $user) => (int) $user->getKey() === (int) $ticket->requester_id),
            $comment,
        ));
    }

    public function handleTicketAssigned(TicketAssigned $event): void
    {
        // Assigning something to yourself is not news.
        if (! $event->assignee || $this->actorIs($event->actor, $event->assignee)) {
            return;
        }

        // A ticket filed with an assignee already on it dispatches both events.
        // The assignee has just had the "new ticket" mail; a second one saying
        // it is theirs adds nothing.
        if ($event->onCreation) {
            return;
        }

        $this->deliver(fn () => $this->mailer->send('ticket.assigned.agent', $event->ticket, $event->assignee));
    }

    public function handleTicketTransitioned(TicketTransitioned $event): void
    {
        if ($event->toStatus->category !== TicketStatus::CATEGORY_RESOLVED) {
            return;
        }

        $ticket = $event->ticket;

        if (! $ticket->requester) {
            return;
        }

        // The comment the agent left with the transition explains the fix, so
        // it belongs in the resolution notice.
        $comment = $ticket->comments()
            ->where('is_internal', false)
            ->latest('id')
            ->first();

        $this->deliver(fn () => $this->mailer->send('ticket.resolved.requester', $ticket, $ticket->requester, $comment));
    }

    /**
     * Send, and decide what an unreachable mail server costs.
     *
     * On a real queue connection a failure is allowed to fail the job: that is
     * what the queue is for, and the retry will deliver the notification once
     * the mail server is back. On the `sync` connection there is no job and no
     * retry — the exception would surface in the request that created the
     * ticket, so an SMTP server being down would stop tickets being created at
     * all. There it is logged and swallowed. The ticket matters more than the
     * notification about it.
     */
    private function deliver(callable $send): void
    {
        if (config('queue.default') !== 'sync') {
            $send();

            return;
        }

        try {
            $send();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Agents who should hear about this ticket: the assignee and the watchers
     * who can work tickets. Requesters are handled separately.
     *
     * @return Collection<int, User>
     */
    private function agentAudience(Ticket $ticket, ?User $exclude): Collection
    {
        $ticket->loadMissing(['assignee', 'watchers.roles']);

        return collect([$ticket->assignee])
            ->concat($ticket->watchers->all())
            ->filter()
            ->filter(fn (User $user) => $user->is_active && $user->isAgent())
            ->reject(fn (User $user) => $exclude && (int) $user->getKey() === (int) $exclude->getKey())
            ->unique('id')
            ->values();
    }

    private function actorIs(?User $actor, ?User $subject): bool
    {
        return $actor !== null && $subject !== null && (int) $actor->getKey() === (int) $subject->getKey();
    }
}
