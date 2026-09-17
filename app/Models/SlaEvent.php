<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to a clock, and when.
 *
 * This is the record a dispute about a breach is settled with, so rows are
 * written and never updated — the same rule the audit log follows.
 *
 * It is also load-bearing for idempotency: before an escalation fires, the
 * sweep asks whether an `escalated` event already exists for that threshold.
 * The job may run every minute; the escalation happens once.
 *
 * @property array<string, mixed>|null $context
 */
class SlaEvent extends Model
{
    public const STARTED = 'started';

    public const PAUSED = 'paused';

    public const RESUMED = 'resumed';

    public const MET = 'met';

    public const BREACHED = 'breached';

    public const ESCALATED = 'escalated';

    public const CANCELLED = 'cancelled';

    public const RECALCULATED = 'recalculated';

    protected $fillable = ['sla_timer_id', 'ticket_id', 'event', 'occurred_at', 'context'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'context' => 'array',
        ];
    }

    /** @return BelongsTo<SlaTimer, $this> */
    public function timer(): BelongsTo
    {
        return $this->belongsTo(SlaTimer::class, 'sla_timer_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'metric' => $this->timer?->metric,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'context' => $this->context,
        ];
    }
}
