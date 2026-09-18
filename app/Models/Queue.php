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
        'team', 'organization', 'labels', 'sla', 'created_at', 'updated_at',
    ];

    // SLA sits with the other triage signals rather than at the far right:
    // "what breaches next" is read in the same glance as status and priority,
    // and a column past the fold is a column nobody sees.
    public const DEFAULT_COLUMNS = ['key', 'subject', 'status', 'priority', 'sla', 'requester', 'assignee', 'updated_at'];

    public const SORTABLE = ['created_at', 'updated_at', 'last_activity_at', 'priority_id', 'key', 'subject', 'sla_due_at'];

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

    /** @return BelongsTo<Team, $this> */ /**
     * Whether this queue is a saved view rather than somewhere a ticket lives.
     *
     * A queue in Ticktz is two things wearing one name. Most are a place —
     * "Hardware", "Facilities" — and a ticket carries the id of the one it
     * belongs to. Some are a saved view over everything: the seeded
     * "Unassigned" and "Assigned to me" are filters, and they mean something
     * different per viewer and per moment.
     *
     * Only the first kind can be a ticket's home, and nothing used to say so.
     * Pointing a request type at "Unassigned" files every ticket into a queue
     * named after a state the ticket leaves the moment somebody picks it up —
     * and the ticket goes on claiming that queue for the rest of its life,
     * which is how a ticket ends up labelled "Unassigned" while assigned.
     *
     * The test is narrow on purpose. A queue filtered by status is still a
     * perfectly good home; one filtered by *who is looking* or by *whether
     * anybody has it* is not a property of where a ticket belongs.
     */
    public function isView(): bool
    {
        $assignee = (array) ($this->filters['assignee'] ?? []);

        return array_intersect($assignee, ['me', 'unassigned']) !== [];
    }

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
