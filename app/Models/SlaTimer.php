<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Database\Factories\SlaTimerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ticket's clock for one metric.
 *
 * The target is *copied* here from the goal rather than read through it. An
 * administrator who shortens a target on Tuesday has not thereby breached
 * every ticket opened on Monday, and a policy that is deleted does not erase
 * the promises it made. The goal is kept only as provenance.
 *
 * @property string $metric
 * @property string $status
 * @property int $target_minutes
 * @property int $paused_minutes
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $breached_at
 */
class SlaTimer extends Model
{
    /** @use HasFactory<SlaTimerFactory> */
    use Auditable, HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_MET = 'met';

    public const STATUS_BREACHED = 'breached';

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses whose clock can still change. */
    public const LIVE = [self::STATUS_RUNNING, self::STATUS_PAUSED];

    protected $fillable = [
        'ticket_id', 'sla_policy_id', 'sla_goal_id', 'business_calendar_id',
        'metric', 'target_minutes', 'started_at', 'due_at', 'paused_at',
        'paused_minutes', 'completed_at', 'breached_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'breached_at' => 'immutable_datetime',
            'target_minutes' => 'integer',
            'paused_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    /** @return BelongsTo<SlaGoal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(SlaGoal::class, 'sla_goal_id');
    }

    /** @return BelongsTo<BusinessCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }

    /** @return HasMany<SlaEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SlaEvent::class);
    }

    /**
     * @param  Builder<SlaTimer>  $query
     * @return Builder<SlaTimer>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isBreached(): bool
    {
        return $this->status === self::STATUS_BREACHED;
    }

    /**
     * Working minutes left before the target, negative once it is past.
     *
     * A paused clock does not count down, so its remaining time is frozen at
     * whatever it was when the pause began.
     */
    public function minutesRemaining(?CarbonImmutable $now = null): ?int
    {
        if (! $this->isLive()) {
            return null;
        }

        $reference = $this->status === self::STATUS_PAUSED
            ? ($this->paused_at ?? CarbonImmutable::now())
            : ($now ?? CarbonImmutable::now());

        $calendar = $this->calendar;

        if (! $calendar) {
            return (int) round($reference->diffInMinutes($this->due_at, false));
        }

        // Past the due date the answer is how far past, which the calendar
        // measures in the other direction.
        return $reference->greaterThanOrEqualTo($this->due_at)
            ? -$calendar->workingMinutesBetween($this->due_at, $reference)
            : $calendar->workingMinutesBetween($reference, $this->due_at);
    }

    /**
     * How much of the target has been used, as a percentage. Can exceed 100.
     */
    public function percentElapsed(?CarbonImmutable $now = null): int
    {
        if ($this->target_minutes <= 0) {
            return 100;
        }

        $remaining = $this->minutesRemaining($now);

        if ($remaining === null) {
            // A finished clock is either wholly used or exactly on target;
            // either way the bar is full.
            return 100;
        }

        return (int) round((($this->target_minutes - $remaining) / $this->target_minutes) * 100);
    }

    /**
     * What the agent console shows: enough to render the badge without
     * a second query per ticket.
     *
     * @return array<string, mixed>
     */
    public function toDisplayArray(?CarbonImmutable $now = null): array
    {
        return [
            'id' => $this->id,
            'metric' => $this->metric,
            'status' => $this->status,
            'target_minutes' => $this->target_minutes,
            'due_at' => $this->due_at->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'breached_at' => $this->breached_at?->toIso8601String(),
            'minutes_remaining' => $this->minutesRemaining($now),
            'percent_elapsed' => $this->percentElapsed($now),
        ];
    }
}
