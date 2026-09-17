<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\WorkflowTransitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One legal status change inside a workflow.
 *
 * @property int|null $from_status_id NULL means "from any status"
 * @property bool $requires_comment
 * @property bool $requires_assignee
 * @property string|null $required_permission
 */
class WorkflowTransition extends Model
{
    /** @use HasFactory<WorkflowTransitionFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'workflow_id', 'from_status_id', 'to_status_id', 'name',
        'requires_comment', 'requires_assignee', 'requires_approval', 'required_permission', 'position',
    ];

    protected function casts(): array
    {
        return [
            'requires_comment' => 'boolean',
            'requires_assignee' => 'boolean',
            'requires_approval' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<TicketStatus, $this>
     */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'from_status_id');
    }

    /**
     * @return BelongsTo<TicketStatus, $this>
     */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'to_status_id');
    }

    public function label(): string
    {
        return $this->name ?: ($this->toStatus?->name ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->label(),
            'to_status_id' => $this->to_status_id,
            'to_status' => $this->toStatus?->toSummaryArray(),
            'requires_comment' => $this->requires_comment,
            'requires_assignee' => $this->requires_assignee,
            'requires_approval' => $this->requires_approval,
        ];
    }
}
