<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\QueueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved filter over tickets.
 *
 * A queue does not own tickets — it describes them. That means a ticket can
 * appear in several queues at once ("Unassigned" and "Breaching today"), which
 * is how service desks actually work.
 *
 * @property array<string, mixed>|null $filters
 * @property array<int, string>|null $columns
 */
class Queue extends Model
{
    /** @use HasFactory<QueueFactory> */
    use Auditable, HasFactory;

    /**
     * Columns a queue may show, and the default set.
     */
    public const AVAILABLE_COLUMNS = [
        'key', 'subject', 'status', 'priority', 'requester', 'assignee',
        'team', 'organization', 'labels', 'created_at', 'updated_at',
    ];

    public const DEFAULT_COLUMNS = ['key', 'subject', 'status', 'priority', 'requester', 'assignee', 'updated_at'];

    public const SORTABLE = ['created_at', 'updated_at', 'last_activity_at', 'priority_id', 'key', 'subject'];

    protected $fillable = [
        'name', 'slug', 'description', 'team_id', 'filters',
        'sort_by', 'sort_direction', 'columns', 'is_shared', 'owner_id',
        'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
            'is_shared' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Queues the given user may open: shared ones, their own, and their teams'.
     *
     * @param  Builder<Queue>  $query
     */
    public function scopeAvailableTo(Builder $query, User $user): void
    {
        $teamIds = $user->teamIds();

        $query->where('is_active', true)->where(function (Builder $scoped) use ($user, $teamIds): void {
            $scoped->where(fn (Builder $shared) => $shared->where('is_shared', true)->whereNull('team_id'))
                ->orWhere('owner_id', $user->getKey())
                ->orWhereIn('team_id', $teamIds);

            if ($user->hasPermission('queues.manage')) {
                $scoped->orWhereNotNull('id');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public function visibleColumns(): array
    {
        $columns = array_values(array_intersect($this->columns ?? [], self::AVAILABLE_COLUMNS));

        return $columns === [] ? self::DEFAULT_COLUMNS : $columns;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'team' => $this->relationLoaded('team') ? $this->team?->only('id', 'name') : null,
            'filters' => $this->filters ?? [],
            'sort_by' => $this->sort_by,
            'sort_direction' => $this->sort_direction,
            'columns' => $this->visibleColumns(),
        ];
    }
}
