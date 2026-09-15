<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One evaluation of one rule against one ticket.
 *
 * Skipped evaluations are recorded too, with the condition that failed. An
 * automation you cannot inspect is an automation nobody trusts, and "why did
 * my rule not fire?" is the question this table exists to answer — a log of
 * only the successes cannot answer it.
 *
 * Rows are written and never updated, like the audit log.
 *
 * @property array<int, array<string, mixed>>|null $actions
 * @property array<string, mixed>|null $context
 */
class AutomationExecution extends Model
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'automation_rule_id', 'ticket_id', 'trigger', 'status',
        'reason', 'context', 'actions', 'depth', 'duration_ms', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'context' => 'array',
            'depth' => 'integer',
            'duration_ms' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AutomationRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'rule' => $this->rule?->only('id', 'name'),
            'ticket' => $this->ticket?->only('id', 'key'),
            'trigger' => $this->trigger,
            'status' => $this->status,
            'reason' => $this->reason,
            // The condition that decided a skip, so the page can print it the
            // way it prints the rule itself.
            'condition' => $this->context['condition'] ?? null,
            'actions' => $this->actions ?? [],
            'depth' => $this->depth,
            'duration_ms' => $this->duration_ms,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
