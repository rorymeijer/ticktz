<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ApprovalStepFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage of an approval, and the rule for finding the people in it.
 *
 * Resolution happens **once, when the step opens**, and the names it produces
 * are written into `approval_decisions`. A team gaining a member tomorrow does
 * not quietly change who was asked today, and an `all` step cannot grow a new
 * blocker halfway through. The cost is that fixing a wrongly-configured step
 * means cancelling the run and starting again, which is the right way round:
 * an approval that silently changed its mind about who it was asking would be
 * worth nothing as evidence.
 */
class ApprovalStep extends Model
{
    /** @use HasFactory<ApprovalStepFactory> */
    use Auditable, HasFactory;

    /** The first yes settles the step. */
    public const ANY = 'any';

    /** Everybody named has to agree. */
    public const ALL = 'all';

    /** @var array<int, string> */
    public const MODES = [self::ANY, self::ALL];

    /** @var array<int, string> */
    public const APPROVER_TYPES = ['users', 'team', 'role', 'manager', 'field'];

    protected $fillable = [
        'approval_workflow_id', 'name', 'mode', 'approver_type',
        'approver_ids', 'approver_field', 'due_hours', 'position',
    ];

    protected function casts(): array
    {
        return [
            'approver_ids' => 'array',
            'due_hours' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<ApprovalWorkflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id');
    }

    public function label(): string
    {
        return $this->name ?: __('approvals.steps.number', ['number' => $this->position + 1]);
    }

    /**
     * The people this step asks, for this ticket.
     *
     * Always returns active users with an e-mail address: an approval waiting
     * on a disabled account waits forever, and one addressed to nobody is a
     * ticket that silently never moves again.
     *
     * @return Collection<int, User>
     */
    public function approversFor(Ticket $ticket): Collection
    {
        $users = match ($this->approver_type) {
            'users' => User::query()->whereIn('id', $this->approver_ids ?? [])->get(),
            'team' => User::query()
                ->whereHas('teams', fn ($teams) => $teams->whereIn('teams.id', $this->approver_ids ?? []))
                ->get(),
            'role' => User::query()
                ->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $this->approver_ids ?? []))
                ->get(),
            'manager' => $this->managerOf($ticket),
            'field' => $this->fromField($ticket),
            default => User::query()->whereRaw('1 = 0')->get(),
        };

        return $users
            ->filter(fn (User $user) => $user->is_active && filled($user->email))
            // The requester approving their own request is not an approval.
            // Silently dropping them is better than a step nobody can settle:
            // if that empties the step, the service says so out loud.
            ->reject(fn (User $user) => $user->getKey() === $ticket->requester_id)
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function managerOf(Ticket $ticket): Collection
    {
        $ticket->loadMissing('requester.manager');

        $manager = $ticket->requester?->manager;

        return $manager
            ? new Collection([$manager])
            : new Collection;
    }

    /**
     * Read the approver out of an answer on the form.
     *
     * A request type can ask "who signs off on this?" and have the answer be
     * the approver, which is how a desk handles budget holders it does not
     * have an org chart for.
     *
     * @return Collection<int, User>
     */
    private function fromField(Ticket $ticket): Collection
    {
        $key = trim((string) $this->approver_field);

        if ($key === '') {
            return new Collection;
        }

        $value = $ticket->customField($key);

        if (blank($value)) {
            return new Collection;
        }

        // A `user` field stores an id; an `email` field stores an address.
        // Both are reasonable ways to ask the question, so both are read.
        $user = is_numeric($value)
            ? User::query()->find((int) $value)
            : User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $value)])->first();

        return $user ? new Collection([$user]) : new Collection;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label(),
            'mode' => $this->mode,
            'approver_type' => $this->approver_type,
            'approver_ids' => $this->approver_ids ?? [],
            'approver_field' => $this->approver_field,
            'due_hours' => $this->due_hours,
            'position' => $this->position,
        ];
    }
}
