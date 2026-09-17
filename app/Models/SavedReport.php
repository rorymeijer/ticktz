<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question somebody asks often enough to have named it.
 *
 * Stores the filters, not the figures. A saved report that cached its numbers
 * would be a screenshot with a date on it — and the one thing anybody wants
 * from "SLA compliance, servicedesk, this month" is what it says *today*.
 *
 * @property array<string, mixed>|null $filters
 */
class SavedReport extends Model
{
    use Auditable;

    /** @var array<int, string> */
    public const REPORTS = ['sla', 'volume', 'workload', 'cycle_time'];

    protected $fillable = ['name', 'description', 'report', 'filters', 'created_by', 'is_shared'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Reports this person may open: the shared ones, and their own.
     *
     * @param  Builder<SavedReport>  $query
     * @return Builder<SavedReport>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $scoped) => $scoped
            ->where('is_shared', true)
            ->orWhere('created_by', $user->getKey()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'report' => $this->report,
            'filters' => $this->filters ?? [],
            'is_shared' => $this->is_shared,
            'is_mine' => $this->relationLoaded('author') && $this->author
                ? $this->author->is(auth()->user())
                : null,
            'author' => $this->relationLoaded('author') ? $this->author?->toSummaryArray() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
