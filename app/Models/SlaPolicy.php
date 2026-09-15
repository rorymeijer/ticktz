<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Which promise applies to which tickets.
 *
 * A policy is matched by its `conditions`, which are deliberately a small
 * fixed set of "this ticket's X is one of these" tests rather than a general
 * expression language: policies are configured once and read on every ticket
 * creation, and an expression evaluator belongs in the automation engine
 * (phase 6), where a rule is genuinely arbitrary.
 *
 * @property array<string, array<int, int>>|null $conditions
 */
class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use Auditable, HasFactory;

    /**
     * The ticket attributes a policy can match on, mapped to the ticket column
     * each one tests. Anything not in this list is not matchable — which is
     * the point: an administrator cannot write a condition the engine will
     * silently ignore.
     *
     * @var array<string, string>
     */
    public const CONDITIONS = [
        'queue_ids' => 'queue_id',
        'team_ids' => 'team_id',
        'request_type_ids' => 'request_type_id',
        'organization_ids' => 'organization_id',
        'priority_ids' => 'priority_id',
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'business_calendar_id',
        'is_active', 'is_default', 'conditions', 'position',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<BusinessCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }

    /** @return HasMany<SlaGoal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(SlaGoal::class);
    }

    /** @return HasMany<SlaTimer, $this> */
    public function timers(): HasMany
    {
        return $this->hasMany(SlaTimer::class);
    }

    /**
     * @param  Builder<SlaPolicy>  $query
     * @return Builder<SlaPolicy>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('id');
    }

    /**
     * Does this policy claim the given ticket?
     *
     * Every condition present must match; a condition that is absent or empty
     * is not a test. A policy with no conditions at all therefore matches
     * everything, which is what makes it usable as the catch-all.
     */
    public function matches(Ticket $ticket): bool
    {
        foreach (self::CONDITIONS as $key => $column) {
            $wanted = array_filter(array_map('intval', $this->conditions[$key] ?? []));

            if ($wanted === []) {
                continue;
            }

            if (! in_array((int) $ticket->getAttribute($column), $wanted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The goal for a metric, given what this ticket is.
     *
     * Specificity wins: a goal naming both the priority and the request type
     * beats one naming only the priority, which beats the catch-all. That
     * ordering is done in PHP over the policy's own goals rather than in SQL,
     * because there are a handful of them and the ranking is clearer read as
     * a sort than as a CASE expression.
     */
    public function goalFor(string $metric, Ticket $ticket): ?SlaGoal
    {
        return $this->goals
            ->filter(fn (SlaGoal $goal): bool => $goal->is_active
                && $goal->metric === $metric
                && $goal->appliesTo($ticket))
            ->sortByDesc(fn (SlaGoal $goal): int => $goal->specificity())
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'business_calendar_id' => $this->business_calendar_id,
            'calendar_name' => $this->calendar?->name,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'position' => $this->position,
            'conditions' => array_merge(
                array_fill_keys(array_keys(self::CONDITIONS), []),
                $this->conditions ?? [],
            ),
            'goals' => $this->goals
                ->sortBy([['metric', 'asc'], ['target_minutes', 'asc']])
                ->map(fn (SlaGoal $goal) => $goal->toAdminArray())
                ->values()
                ->all(),
        ];
    }
}
