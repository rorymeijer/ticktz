<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * When this happens, and these things are true, do that.
 *
 * The vocabulary is defined here as constants rather than left open, and the
 * admin form is built from these lists. That is the point: an administrator
 * can only write a rule the engine knows how to run, so a rule that silently
 * does nothing is not a state this feature has.
 *
 * @property array<int, array<string, mixed>>|null $conditions
 * @property array<int, array<string, mixed>> $actions
 * @property array<string, mixed>|null $trigger_config
 */
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use Auditable, HasFactory;

    // -----------------------------------------------------------------
    // Triggers
    // -----------------------------------------------------------------

    public const TRIGGER_CREATED = 'ticket.created';

    public const TRIGGER_UPDATED = 'ticket.updated';

    public const TRIGGER_COMMENTED = 'ticket.commented';

    public const TRIGGER_TRANSITIONED = 'ticket.transitioned';

    public const TRIGGER_ASSIGNED = 'ticket.assigned';

    public const TRIGGER_SLA_BREACHED = 'sla.breached';

    public const TRIGGER_SLA_ESCALATED = 'sla.escalated';

    /** Runs on a schedule over the tickets the conditions select. */
    public const TRIGGER_SCHEDULED = 'scheduled';

    /** @var array<int, string> */
    public const TRIGGERS = [
        self::TRIGGER_CREATED,
        self::TRIGGER_UPDATED,
        self::TRIGGER_COMMENTED,
        self::TRIGGER_TRANSITIONED,
        self::TRIGGER_ASSIGNED,
        self::TRIGGER_SLA_BREACHED,
        self::TRIGGER_SLA_ESCALATED,
        self::TRIGGER_SCHEDULED,
    ];

    // -----------------------------------------------------------------
    // Conditions
    // -----------------------------------------------------------------

    /**
     * The fields a condition can test, and how each one is compared.
     *
     * `id` fields match on the foreign key, `text` on a string, `set` on a
     * many-to-many, `age` on elapsed minutes, and `flag` on a boolean. The
     * evaluator switches on this type, so adding a field is one entry here and
     * one case there.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'status' => 'id',
        'status_category' => 'text',
        'priority' => 'id',
        'queue' => 'id',
        'team' => 'id',
        'assignee' => 'id',
        'requester' => 'id',
        'organization' => 'id',
        'request_type' => 'id',
        'email_channel' => 'id',
        'source' => 'text',
        'subject' => 'text',
        'description' => 'text',
        'label' => 'set',
        'age_minutes' => 'age',
        'minutes_since_activity' => 'age',
        'minutes_since_requester_reply' => 'age',
        'is_assigned' => 'flag',
        'is_first_response_sent' => 'flag',
        'sla_breached' => 'flag',
        // Only meaningful on ticket.commented.
        'comment_is_internal' => 'flag',
        'comment_body' => 'text',
        // Only meaningful on ticket.updated: did this change touch the field?
        // A set, not a text: one change can touch several fields at once, and
        // the question is whether the named one is among them.
        'changed_field' => 'set',
    ];

    /** @var array<int, string> */
    public const OPERATORS = [
        'is', 'is_not', 'is_one_of', 'is_none_of',
        'contains', 'not_contains', 'starts_with',
        'is_empty', 'is_not_empty',
        'greater_than', 'less_than',
        'is_true', 'is_false',
    ];

    /**
     * Operators that carry no value — "is empty" needs nothing to compare to,
     * and a form that asks for one is a form that invites a meaningless rule.
     *
     * @var array<int, string>
     */
    public const VALUELESS_OPERATORS = ['is_empty', 'is_not_empty', 'is_true', 'is_false'];

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    public const ACTION_ASSIGN_USER = 'assign_user';

    public const ACTION_ASSIGN_TEAM = 'assign_team';

    public const ACTION_SET_PRIORITY = 'set_priority';

    public const ACTION_SET_QUEUE = 'set_queue';

    public const ACTION_TRANSITION = 'transition';

    public const ACTION_ADD_LABEL = 'add_label';

    public const ACTION_REMOVE_LABEL = 'remove_label';

    public const ACTION_ADD_COMMENT = 'add_comment';

    public const ACTION_ADD_WATCHER = 'add_watcher';

    public const ACTION_WEBHOOK = 'webhook';

    /** @var array<int, string> */
    public const ACTIONS = [
        self::ACTION_ASSIGN_USER,
        self::ACTION_ASSIGN_TEAM,
        self::ACTION_SET_PRIORITY,
        self::ACTION_SET_QUEUE,
        self::ACTION_TRANSITION,
        self::ACTION_ADD_LABEL,
        self::ACTION_REMOVE_LABEL,
        self::ACTION_ADD_COMMENT,
        self::ACTION_ADD_WATCHER,
        self::ACTION_WEBHOOK,
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'trigger', 'trigger_config',
        'match_type', 'conditions', 'actions', 'is_active', 'position',
        'stop_processing', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'stop_processing' => 'boolean',
            'position' => 'integer',
            'run_count' => 'integer',
            'match_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    /** @return HasMany<AutomationExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<AutomationRule>  $query
     * @return Builder<AutomationRule>
     */
    public function scopeFor(Builder $query, string $trigger): Builder
    {
        return $query->where('is_active', true)
            ->where('trigger', $trigger)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The cron expression a scheduled rule runs on. Hourly on the hour when
     * nothing is configured — often enough to be useful, rare enough that a
     * misconfigured rule is not a runaway.
     */
    public function cronExpression(): string
    {
        $expression = trim((string) ($this->trigger_config['cron'] ?? ''));

        return $expression !== '' ? $expression : '0 * * * *';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conditionList(): array
    {
        return array_values(array_filter(
            $this->conditions ?? [],
            static fn ($condition): bool => is_array($condition)
                && isset(self::FIELDS[$condition['field'] ?? ''])
                && in_array($condition['operator'] ?? null, self::OPERATORS, true),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function actionList(): array
    {
        return array_values(array_filter(
            $this->actions ?? [],
            static fn ($action): bool => is_array($action)
                && in_array($action['type'] ?? null, self::ACTIONS, true),
        ));
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
            'trigger' => $this->trigger,
            'trigger_config' => $this->trigger_config ?? [],
            'match_type' => $this->match_type,
            'conditions' => $this->conditionList(),
            'actions' => $this->actionList(),
            'is_active' => $this->is_active,
            'position' => $this->position,
            'stop_processing' => $this->stop_processing,
            'run_count' => $this->run_count,
            'match_count' => $this->match_count,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
        ];
    }
}
