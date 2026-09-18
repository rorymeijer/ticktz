<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Jobs\Automation\DeliverWebhookJob;
use App\Models\AutomationRule;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Queue;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tickets\Assignability;
use App\Services\Tickets\TicketNumberGenerator;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Does what a matching rule says to do.
 *
 * Everything goes through {@see TicketService} rather than writing to the
 * model directly, so an automated assignment is audited, emits its events and
 * respects the workflow exactly as a human's would. An automation that can
 * make a ticket do something an agent cannot is an automation that will
 * eventually produce a ticket nobody can explain.
 *
 * Each action reports what it did. A rule that "ran" but changed nothing —
 * because the ticket was already in that state, or the transition was not
 * legal — says so in the execution log rather than looking like a success.
 */
class ActionRunner
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly AutomationGuard $guard,
    ) {}

    /**
     * A ticket service whose audit trail is attributed to the rule.
     *
     * Without this every automated change is logged as "system", and an
     * operator reading the trail of a ticket that reassigned itself at three
     * in the morning cannot tell which rule did it. The logger's `as()` clones
     * rather than mutates, so the service is built per rule.
     */
    private function ticketsFor(AutomationRule $rule): TicketService
    {
        return new TicketService(
            $this->numbers,
            $this->audit->as('automation', $rule->name),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>> one entry per action, for the log
     */
    public function run(AutomationRule $rule, Ticket $ticket, array $context = []): array
    {
        $results = [];
        $tickets = $this->ticketsFor($rule);

        foreach ($rule->actionList() as $action) {
            $type = (string) $action['type'];

            try {
                // The guard is flagged for the whole action so anything
                // downstream can tell this change came from a rule.
                $outcome = $this->guard->whileRunning(
                    fn (): array => $this->perform($type, $action, $ticket, $rule, $tickets, $context),
                );

                $results[] = ['type' => $type] + $outcome;
            } catch (Throwable $exception) {
                report($exception);

                // One failed action does not abandon the rest: a webhook to an
                // unreachable server must not stop the assignment that was
                // supposed to happen alongside it.
                $results[] = [
                    'type' => $type,
                    'ok' => false,
                    'detail' => mb_substr($exception->getMessage(), 0, 200),
                ];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, detail?: string}
     */
    private function perform(
        string $type,
        array $action,
        Ticket $ticket,
        AutomationRule $rule,
        TicketService $tickets,
        array $context,
    ): array {
        return match ($type) {
            AutomationRule::ACTION_ASSIGN_USER => $this->assignUser($tickets, $ticket, Arr::get($action, 'user_id')),
            AutomationRule::ACTION_ASSIGN_TEAM => $this->assignTeam($tickets, $ticket, Arr::get($action, 'team_id')),
            AutomationRule::ACTION_SET_PRIORITY => $this->setPriority($tickets, $ticket, Arr::get($action, 'priority_id')),
            AutomationRule::ACTION_SET_QUEUE => $this->setQueue($tickets, $ticket, Arr::get($action, 'queue_id')),
            AutomationRule::ACTION_TRANSITION => $this->transition($tickets, $ticket, Arr::get($action, 'status_id')),
            AutomationRule::ACTION_ADD_LABEL => $this->label($ticket, Arr::get($action, 'label_id'), attach: true),
            AutomationRule::ACTION_REMOVE_LABEL => $this->label($ticket, Arr::get($action, 'label_id'), attach: false),
            AutomationRule::ACTION_ADD_COMMENT => $this->comment($tickets, $ticket, $action),
            AutomationRule::ACTION_ADD_WATCHER => $this->watcher($tickets, $ticket, Arr::get($action, 'user_id')),
            AutomationRule::ACTION_WEBHOOK => $this->webhook($ticket, $rule, $action, $context),
            default => ['ok' => false, 'detail' => 'unknown action'],
        };
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function assignUser(TicketService $tickets, Ticket $ticket, mixed $userId): array
    {
        $user = $userId ? User::query()->find((int) $userId) : null;

        if (! $user || ! $user->is_active) {
            return ['ok' => false, 'detail' => 'no such active user'];
        }

        if ((int) $ticket->assignee_id === (int) $user->getKey()) {
            return ['ok' => true, 'detail' => 'already assigned'];
        }

        // The same team rule the console and the API enforce. A rule three
        // doors keep and the fourth does not is a way in, and this is the door
        // nobody is watching: an automation assigns at three in the morning,
        // and the trail says a person did not.
        if (! Assignability::allows($ticket, $user)) {
            return ['ok' => false, 'detail' => 'not on this ticket\'s team'];
        }

        $tickets->assign($ticket, $user, null);

        return ['ok' => true, 'detail' => $user->name];
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function assignTeam(TicketService $tickets, Ticket $ticket, mixed $teamId): array
    {
        $team = $teamId ? Team::query()->find((int) $teamId) : null;

        if (! $team) {
            return ['ok' => false, 'detail' => 'no such team'];
        }

        if ((int) $ticket->team_id === (int) $team->getKey()) {
            return ['ok' => true, 'detail' => 'already on this team'];
        }

        $tickets->update($ticket, ['team_id' => $team->getKey()], null);

        return ['ok' => true, 'detail' => $team->name];
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function setPriority(TicketService $tickets, Ticket $ticket, mixed $priorityId): array
    {
        $priority = $priorityId ? Priority::query()->find((int) $priorityId) : null;

        if (! $priority) {
            return ['ok' => false, 'detail' => 'no such priority'];
        }

        if ((int) $ticket->priority_id === (int) $priority->getKey()) {
            return ['ok' => true, 'detail' => 'already '.$priority->name];
        }

        $tickets->update($ticket, ['priority_id' => $priority->getKey()], null);

        return ['ok' => true, 'detail' => $priority->name];
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function setQueue(TicketService $tickets, Ticket $ticket, mixed $queueId): array
    {
        $queue = $queueId ? Queue::query()->find((int) $queueId) : null;

        if (! $queue) {
            return ['ok' => false, 'detail' => 'no such queue'];
        }

        if ((int) $ticket->queue_id === (int) $queue->getKey()) {
            return ['ok' => true, 'detail' => 'already in this queue'];
        }

        $tickets->update($ticket, ['queue_id' => $queue->getKey()], null);

        return ['ok' => true, 'detail' => $queue->name];
    }

    /**
     * A transition the workflow does not allow is reported, not forced. The
     * workflow is the authority on what a ticket may do, and a rule that could
     * override it would make the workflow a suggestion.
     *
     * @return array{ok: bool, detail?: string}
     */
    private function transition(TicketService $tickets, Ticket $ticket, mixed $statusId): array
    {
        $status = $statusId ? TicketStatus::query()->find((int) $statusId) : null;

        if (! $status) {
            return ['ok' => false, 'detail' => 'no such status'];
        }

        if ((int) $ticket->status_id === (int) $status->getKey()) {
            return ['ok' => true, 'detail' => 'already '.$status->name];
        }

        try {
            $tickets->transition($ticket, $status, null);
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => 'transition not allowed by the workflow'];
        }

        return ['ok' => true, 'detail' => $status->name];
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function label(Ticket $ticket, mixed $labelId, bool $attach): array
    {
        $label = $labelId ? Label::query()->find((int) $labelId) : null;

        if (! $label) {
            return ['ok' => false, 'detail' => 'no such label'];
        }

        if ($attach) {
            $ticket->labels()->syncWithoutDetaching([$label->getKey()]);
        } else {
            $ticket->labels()->detach($label->getKey());
        }

        $ticket->load('labels');

        return ['ok' => true, 'detail' => $label->name];
    }

    /**
     * Always public or always internal as the rule says, and never a reply
     * "from" anybody: an automated comment has no author, which is what makes
     * it read as the system talking rather than as an agent.
     *
     * @param  array<string, mixed>  $action
     * @return array{ok: bool, detail?: string}
     */
    private function comment(TicketService $tickets, Ticket $ticket, array $action): array
    {
        $body = trim((string) Arr::get($action, 'body', ''));

        if ($body === '') {
            return ['ok' => false, 'detail' => 'empty comment'];
        }

        $tickets->comment(
            $ticket,
            $this->fill($body, $ticket),
            null,
            internal: (bool) Arr::get($action, 'internal', true),
            source: 'automation',
        );

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function watcher(TicketService $tickets, Ticket $ticket, mixed $userId): array
    {
        $user = $userId ? User::query()->find((int) $userId) : null;

        if (! $user || ! $user->is_active) {
            return ['ok' => false, 'detail' => 'no such active user'];
        }

        $tickets->addWatcher($ticket, $user, automatic: true);

        return ['ok' => true, 'detail' => $user->name];
    }

    /**
     * Queued on its own queue: an unreachable endpoint must not hold up the
     * rest of the rule, and retries are the queue's job, not this class's.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, detail?: string}
     */
    private function webhook(Ticket $ticket, AutomationRule $rule, array $action, array $context): array
    {
        $url = trim((string) Arr::get($action, 'url', ''));

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            return ['ok' => false, 'detail' => 'not an https url'];
        }

        DeliverWebhookJob::dispatch(
            $url,
            [
                'rule' => ['id' => $rule->id, 'name' => $rule->name, 'slug' => $rule->slug],
                'trigger' => $rule->trigger,
                'ticket' => $ticket->toListArray(),
                'occurred_at' => now()->toIso8601String(),
            ],
            (string) Arr::get($action, 'secret', ''),
        );

        return ['ok' => true, 'detail' => 'queued'];
    }

    /**
     * The handful of placeholders an automated comment may use. Substitution,
     * never evaluation — the same rule the e-mail templates follow.
     */
    private function fill(string $body, Ticket $ticket): string
    {
        $ticket->loadMissing(['status', 'priority', 'requester', 'assignee']);

        $values = [
            'ticket.key' => $ticket->key,
            'ticket.subject' => $ticket->subject,
            'ticket.status' => (string) $ticket->status?->name,
            'ticket.priority' => (string) $ticket->priority?->name,
            'requester.name' => (string) $ticket->requester?->name,
            'assignee.name' => (string) $ticket->assignee?->name,
        ];

        return preg_replace_callback(
            '/\{\{(.*?)\}\}/s',
            static fn (array $matches): string => $values[trim($matches[1])] ?? '',
            $body,
        ) ?? $body;
    }
}
