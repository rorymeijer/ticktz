<?php

declare(strict_types=1);

namespace App\Services\Automation;

/**
 * Stops automation eating itself.
 *
 * A rule that sets a field emits `ticket.updated`, which is a trigger, which
 * can run the rule again. Two rules that each undo the other do the same thing
 * more slowly and less obviously. This is the failure mode every automation
 * engine has, and it does not announce itself: it looks like a busy worker and
 * an audit log filling up.
 *
 * Two limits, both cheap:
 *
 * 1. **A rule runs at most once per ticket per cascade.** This kills direct
 *    self-triggering and any A→B→A ring, however long the ring is.
 * 2. **A cascade is at most `ticktz.automation.max_depth` deep.** A backstop
 *    for anything the first limit somehow does not catch, and a useful signal
 *    in the execution log: depth 0 is a human's change, depth 3 means rules
 *    have been reacting to rules for a while.
 *
 * The guard is request-scoped, held as a singleton. A cascade lives inside one
 * job, and the depth and the set of pairs already run are handed to the next
 * job explicitly rather than inferred — a queued job runs in a fresh process
 * and would otherwise start every cascade from zero.
 */
class AutomationGuard
{
    /**
     * Pairs of "rule N already ran on ticket M" in the current cascade.
     *
     * @var array<string, true>
     */
    private array $seen = [];

    private int $depth = 0;

    /**
     * True while actions are running, so anything that asks can tell that a
     * change was made by automation rather than by a person.
     */
    private bool $running = false;

    /**
     * Begin a cascade with what the job was handed.
     *
     * @param  array<int, string>  $seen
     */
    public function enter(int $depth, array $seen = []): void
    {
        $this->depth = max(0, $depth);
        $this->seen = array_fill_keys($seen, true);
    }

    public function reset(): void
    {
        $this->seen = [];
        $this->depth = 0;
        $this->running = false;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * The pairs run so far, to hand to whatever this cascade triggers next.
     *
     * @return array<int, string>
     */
    public function seen(): array
    {
        return array_keys($this->seen);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Mark the window in which actions execute.
     */
    public function whileRunning(callable $callback): mixed
    {
        $previous = $this->running;
        $this->running = true;

        try {
            return $callback();
        } finally {
            $this->running = $previous;
        }
    }

    public function atMaxDepth(): bool
    {
        return $this->depth >= (int) config('ticktz.automation.max_depth', 5);
    }

    /**
     * Claim this rule for this ticket. False means it has already run in this
     * cascade and must not run again.
     */
    public function claim(int $ruleId, ?int $ticketId): bool
    {
        $key = $ruleId.':'.($ticketId ?? 0);

        if (isset($this->seen[$key])) {
            return false;
        }

        $this->seen[$key] = true;

        return true;
    }

    /**
     * Has this pair already run? Asked before the work, so a refusal can be
     * recorded rather than silently dropped.
     */
    public function hasRun(int $ruleId, ?int $ticketId): bool
    {
        return isset($this->seen[$ruleId.':'.($ticketId ?? 0)]);
    }
}
