<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One approval, running against one ticket.
 *
 * Separate from {@see ApprovalWorkflow} on purpose: this is what happened,
 * that is what was configured. Editing a workflow must never rewrite an
 * approval somebody has already answered, and an approval raised by hand has
 * no workflow behind it at all.
 *
 * `current_position` holds the step's *position*, not its id, so deleting a
 * step from the definition cannot strand a run in a stage that no longer
 * exists.
 */
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use Auditable, HasFactory;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** @var array<int, string> */
    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED];

    protected $fillable = [
        'ticket_id', 'approval_workflow_id', 'subject', 'reason', 'status',
        'current_position', 'requested_by', 'due_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'current_position' => 'integer',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<ApprovalWorkflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<ApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('position')->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * The decisions belonging to the step that is currently open.
     *
     * @return Collection<int, ApprovalDecision>
     */
    public function currentDecisions(): Collection
    {
        $decisions = $this->relationLoaded('decisions') ? $this->decisions : $this->decisions()->get();

        return $decisions->where('position', $this->current_position)->values();
    }

    /**
     * Approvals still waiting on this person.
     *
     * Reads the decision rows rather than re-resolving the step, because who
     * was asked is a fact recorded at the time, not a query run now.
     *
     * @param  Builder<ApprovalRequest>  $query
     * @return Builder<ApprovalRequest>
     */
    public function scopeAwaiting(Builder $query, User $user): Builder
    {
        return $query
            ->where('status', self::PENDING)
            ->whereHas('decisions', fn (Builder $decisions) => $decisions
                ->where('approver_id', $user->getKey())
                ->where('decision', ApprovalDecision::PENDING)
                // Only the open step counts: being named in step three is not
                // something to act on while step one is still running.
                ->whereColumn('approval_decisions.position', 'approval_requests.current_position'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'reason' => $this->reason,
            'status' => $this->status,
            'current_position' => $this->current_position,
            'is_overdue' => $this->isOverdue(),
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'workflow' => $this->relationLoaded('workflow') && $this->workflow
                ? ['id' => $this->workflow->id, 'name' => $this->workflow->translatedName()]
                : null,
            'requested_by' => $this->relationLoaded('requester') ? $this->requester?->toSummaryArray() : null,
        ];
    }

    /**
     * The whole run, step by step, as the ticket panel draws it.
     *
     * @return array<string, mixed>
     */
    public function toDetailArray(): array
    {
        $decisions = $this->relationLoaded('decisions') ? $this->decisions : $this->decisions()->get();

        $steps = $decisions
            ->groupBy('position')
            ->map(fn (Collection $group, int|string $position) => [
                'position' => (int) $position,
                'name' => $group->first()->step_name,
                'mode' => $group->first()->mode,
                'state' => $this->stateOfStep($group, (int) $position),
                'decisions' => $group->map(fn (ApprovalDecision $decision) => $decision->toSummaryArray())->all(),
            ])
            ->values()
            ->all();

        return $this->toSummaryArray() + [
            'ticket_key' => $this->relationLoaded('ticket') ? $this->ticket?->key : null,
            'steps' => $steps,
        ];
    }

    /**
     * @param  Collection<int, ApprovalDecision>  $group
     */
    private function stateOfStep(Collection $group, int $position): string
    {
        if ($group->contains(fn (ApprovalDecision $decision) => $decision->decision === ApprovalDecision::REJECTED)) {
            return 'rejected';
        }

        if ($group->every(fn (ApprovalDecision $decision) => $decision->decision !== ApprovalDecision::PENDING)) {
            return 'approved';
        }

        // Unsettled decisions on a cancelled run are nobody's to answer.
        if ($this->status === self::CANCELLED) {
            return 'cancelled';
        }

        // A step past the open one has not been asked yet; "pending" would
        // suggest somebody is sitting on it.
        return $position === $this->current_position && $this->isPending() ? 'open' : 'waiting';
    }
}
