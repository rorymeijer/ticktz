<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workflow is the set of statuses a ticket may hold and the transitions
 * between them.
 *
 * @property string $name
 * @property bool $is_default
 */
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use Auditable, HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsToMany<TicketStatus, $this>
     */
    public function statuses(): BelongsToMany
    {
        return $this->belongsToMany(TicketStatus::class, 'workflow_statuses')
            ->withPivot(['is_initial', 'position'])
            ->orderBy('workflow_statuses.position');
    }

    /**
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class)->orderBy('position');
    }

    public function initialStatus(): ?TicketStatus
    {
        return $this->statuses->firstWhere('pivot.is_initial', true)
            ?? $this->statuses->first();
    }

    public static function default(): ?self
    {
        return static::query()->where('is_active', true)->where('is_default', true)->first()
            ?? static::query()->where('is_active', true)->first();
    }

    /**
     * Transitions available from a given status: the ones declared for that
     * status plus any declared "from anywhere".
     *
     * @return Collection<int, WorkflowTransition>
     */
    public function transitionsFrom(TicketStatus $status): Collection
    {
        return $this->transitions
            ->filter(fn (WorkflowTransition $transition) => $transition->from_status_id === null
                || $transition->from_status_id === $status->getKey())
            // A transition to the status you are already in is noise.
            ->reject(fn (WorkflowTransition $transition) => $transition->to_status_id === $status->getKey())
            ->values();
    }
}
