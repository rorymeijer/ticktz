<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Runs the rules for a trigger against a ticket.
 *
 * The order is fixed and the log is complete: every active rule for the
 * trigger is evaluated in `position` order, every evaluation is recorded
 * — including the ones that decided to do nothing, and which condition
 * decided it — and a matching rule with `stop_processing` ends the pass.
 *
 * Recording the skips is the difference between an automation feature people
 * use and one they turn off. "Why did my rule not fire?" is unanswerable from
 * a log of successes.
 */
class AutomationEngine
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly ActionRunner $actions,
        private readonly AutomationGuard $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return Collection<int, AutomationExecution>
     */
    public function run(string $trigger, Ticket $ticket, array $context = []): Collection
    {
        $executions = collect();

        if ($this->guard->atMaxDepth()) {
            // The cascade has gone deeper than anyone intended. Stopping here
            // is the only safe thing; the depth on the last executions that
            // did run says where to look.
            return $executions;
        }

        $rules = AutomationRule::query()->for($trigger)->get();

        if ($rules->isEmpty()) {
            return $executions;
        }

        // The conditions read these; loading once beats a query per rule.
        $ticket->loadMissing(['status', 'labels']);

        foreach ($rules as $rule) {
            $execution = $this->apply($rule, $ticket, $trigger, $context);

            if ($execution === null) {
                continue;
            }

            $executions->push($execution);

            if ($execution->status === AutomationExecution::STATUS_MATCHED && $rule->stop_processing) {
                break;
            }
        }

        return $executions;
    }

    /**
     * Evaluate one rule and, if it holds, do what it says.
     *
     * Returns null when the rule was not even evaluated, which happens only
     * when the loop guard has already run it on this ticket in this cascade.
     *
     * @param  array<string, mixed>  $context
     */
    public function apply(AutomationRule $rule, Ticket $ticket, string $trigger, array $context = []): ?AutomationExecution
    {
        // A rule that has already run on this ticket in this cascade must not
        // run again, however it was reached. This is what stops a rule that
        // edits a ticket from triggering itself.
        if (! $this->guard->claim((int) $rule->getKey(), $ticket->getKey())) {
            return null;
        }

        $startedAt = microtime(true);

        try {
            [$matches, $reason, $failedCondition] = $this->conditions->evaluate($rule, $ticket, $context);
        } catch (Throwable $exception) {
            report($exception);

            return $this->record($rule, $ticket, $trigger, AutomationExecution::STATUS_FAILED, [
                'reason' => 'condition error: '.mb_substr($exception->getMessage(), 0, 200),
                'duration_ms' => $this->elapsed($startedAt),
            ]);
        }

        if (! $matches) {
            return $this->record($rule, $ticket, $trigger, AutomationExecution::STATUS_SKIPPED, [
                'reason' => $reason,
                // Kept structurally as well, so the admin screen can print it
                // with names rather than ids and in the reader's language.
                'context' => $failedCondition ? ['condition' => $failedCondition] : null,
                'duration_ms' => $this->elapsed($startedAt),
            ]);
        }

        $results = $this->actions->run($rule, $ticket, $context);
        $anyActionFailed = collect($results)->contains(fn (array $result): bool => ($result['ok'] ?? false) === false);

        return $this->record(
            $rule,
            $ticket,
            $trigger,
            // A rule whose actions all refused did not do its job, and saying
            // it "matched" would hide that behind a green row.
            $anyActionFailed ? AutomationExecution::STATUS_FAILED : AutomationExecution::STATUS_MATCHED,
            [
                'actions' => $results,
                'duration_ms' => $this->elapsed($startedAt),
                'matched' => true,
            ],
        );
    }

    /**
     * Every rule that would be considered for a trigger, in the order they run.
     * Used by the admin UI's dry run.
     *
     * @return Collection<int, AutomationRule>
     */
    public function rulesFor(string $trigger): Collection
    {
        return AutomationRule::query()->for($trigger)->get();
    }

    /**
     * Would this rule match this ticket, without doing anything about it?
     *
     * @param  array<string, mixed>  $context
     * @return array{0: bool, 1: string|null, 2: array<string, mixed>|null}
     */
    public function preview(AutomationRule $rule, Ticket $ticket, array $context = []): array
    {
        $ticket->loadMissing(['status', 'labels']);

        return $this->conditions->evaluate($rule, $ticket, $context);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(
        AutomationRule $rule,
        Ticket $ticket,
        string $trigger,
        string $status,
        array $extra = [],
    ): AutomationExecution {
        $matched = (bool) ($extra['matched'] ?? false);

        $rule->newQuery()->whereKey($rule->getKey())->update([
            'run_count' => $rule->run_count + 1,
            'match_count' => $rule->match_count + ($matched ? 1 : 0),
            'last_run_at' => now(),
        ]);

        return AutomationExecution::query()->create([
            'automation_rule_id' => $rule->getKey(),
            'ticket_id' => $ticket->getKey(),
            'trigger' => $trigger,
            'status' => $status,
            'reason' => isset($extra['reason']) ? mb_substr((string) $extra['reason'], 0, 500) : null,
            'context' => $extra['context'] ?? null,
            'actions' => $extra['actions'] ?? null,
            'depth' => $this->guard->depth(),
            'duration_ms' => (int) ($extra['duration_ms'] ?? 0),
            'occurred_at' => now(),
        ]);
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
