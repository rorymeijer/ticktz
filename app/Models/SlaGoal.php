<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SlaGoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One promise: this metric, this many working minutes.
 *
 * @property string $metric
 * @property int $target_minutes
 * @property array<int, array{at: int, actions: array<int, array<string, mixed>>}>|null $escalations
 */
class SlaGoal extends Model
{
    /** @use HasFactory<SlaGoalFactory> */
    use Auditable, HasFactory;

    /** How long until a human answers the requester. */
    public const METRIC_FIRST_RESPONSE = 'first_response';

    /** How long until the ticket is resolved. */
    public const METRIC_RESOLUTION = 'resolution';

    /** @var array<int, string> */
    public const METRICS = [self::METRIC_FIRST_RESPONSE, self::METRIC_RESOLUTION];

    /**
     * What an escalation can do. Deliberately short: anything more elaborate
     * is an automation rule triggered by the breach event (phase 6), not an
     * SLA feature.
     *
     * @var array<int, string>
     */
    public const ACTIONS = ['notify', 'raise_priority', 'assign_team'];

    /**
     * Who an escalation can notify.
     *
     * @var array<int, string>
     */
    public const NOTIFY_TARGETS = ['assignee', 'team_leads', 'watchers'];

    protected $fillable = [
        'sla_policy_id', 'metric', 'priority_id', 'request_type_id',
        'target_minutes', 'escalations', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_minutes' => 'integer',
            'escalations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    /** @return BelongsTo<Priority, $this> */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    /** @return BelongsTo<RequestType, $this> */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    /**
     * A NULL priority or request type means "any", so the goal applies unless
     * it names something the ticket is not.
     */
    public function appliesTo(Ticket $ticket): bool
    {
        if ($this->priority_id !== null && (int) $this->priority_id !== (int) $ticket->priority_id) {
            return false;
        }

        return $this->request_type_id === null
            || (int) $this->request_type_id === (int) $ticket->request_type_id;
    }

    /**
     * How specific this goal is, used to pick between goals that all apply.
     */
    public function specificity(): int
    {
        return ($this->priority_id !== null ? 1 : 0) + ($this->request_type_id !== null ? 2 : 0);
    }

    /**
     * The escalation thresholds, as percentages of the target, in order.
     * A threshold of 100 is the breach itself.
     *
     * @return array<int, array{at: int, actions: array<int, array<string, mixed>>}>
     */
    public function escalationSteps(): array
    {
        $steps = [];

        foreach ($this->escalations ?? [] as $step) {
            $at = (int) ($step['at'] ?? 0);
            $actions = array_values(array_filter(
                $step['actions'] ?? [],
                static fn ($action): bool => is_array($action)
                    && in_array($action['type'] ?? null, self::ACTIONS, true),
            ));

            if ($at > 0 && $actions !== []) {
                $steps[] = ['at' => $at, 'actions' => $actions];
            }
        }

        usort($steps, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'sla_policy_id' => $this->sla_policy_id,
            'metric' => $this->metric,
            'priority_id' => $this->priority_id,
            'request_type_id' => $this->request_type_id,
            'target_minutes' => $this->target_minutes,
            'escalations' => $this->escalationSteps(),
            'is_active' => $this->is_active,
        ];
    }
}
