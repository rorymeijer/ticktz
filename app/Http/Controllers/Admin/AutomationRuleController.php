<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Label;
use App\Models\Organization;
use App\Models\Priority;
use App\Models\Queue;
use App\Models\RequestType;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Automation\AutomationEngine;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Building rules, and seeing what they did.
 *
 * The form is built from the engine's own vocabulary — {@see AutomationRule}'s
 * constants — so a rule the UI can express is a rule the engine can run, and
 * the two cannot drift apart.
 */
class AutomationRuleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('automation.manage');

        return Inertia::render('Admin/Automation/Index', [
            'rules' => AutomationRule::query()
                ->orderBy('trigger')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (AutomationRule $rule) => $rule->toAdminArray())
                ->all(),
            'executions' => AutomationExecution::query()
                ->with(['rule:id,name', 'ticket:id,key'])
                ->when(
                    $request->filled('rule'),
                    fn ($query) => $query->where('automation_rule_id', $request->integer('rule')),
                )
                ->when(
                    $request->filled('status'),
                    fn ($query) => $query->where('status', $request->string('status')),
                )
                ->latest('occurred_at')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (AutomationExecution $execution) => $execution->toAdminArray())
                ->all(),
            'filters' => [
                'rule' => $request->integer('rule') ?: null,
                'status' => $request->string('status')->toString() ?: null,
            ],
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('automation.manage');

        $rule = AutomationRule::query()->create(
            $this->validated($request, null) + ['created_by' => $request->user()->getKey()],
        );

        $this->audit->created($rule, "Created automation rule {$rule->name}");

        return back()->with('success', __('automation.rules.created', ['name' => $rule->name]));
    }

    public function update(Request $request, AutomationRule $rule): RedirectResponse
    {
        $this->authorize('automation.manage');

        $rule->fill($this->validated($request, $rule))->save();

        $this->audit->updated($rule, "Updated automation rule {$rule->name}");

        return back()->with('success', __('automation.rules.updated', ['name' => $rule->name]));
    }

    public function destroy(AutomationRule $rule): RedirectResponse
    {
        $this->authorize('automation.manage');

        $this->audit->deleted($rule, "Deleted automation rule {$rule->name}");
        $rule->delete();

        return back()->with('success', __('automation.rules.deleted'));
    }

    /**
     * Switching a rule off is the thing an administrator reaches for when a
     * rule is misbehaving, so it is one click rather than a form.
     */
    public function toggle(AutomationRule $rule): RedirectResponse
    {
        $this->authorize('automation.manage');

        $rule->forceFill(['is_active' => ! $rule->is_active])->save();

        $this->audit->log(
            $rule,
            $rule->is_active ? 'automation.enabled' : 'automation.disabled',
            ($rule->is_active ? 'Enabled' : 'Disabled')." automation rule {$rule->name}",
        );

        return back()->with('success', __(
            $rule->is_active ? 'automation.rules.enabled' : 'automation.rules.disabled',
            ['name' => $rule->name],
        ));
    }

    /**
     * Would this rule match this ticket?
     *
     * Evaluates the conditions and reports the answer without running a single
     * action. Writing a rule you cannot try out means testing it on real
     * tickets, which is how an automation feature earns its reputation.
     */
    public function preview(Request $request, AutomationRule $rule, AutomationEngine $engine): RedirectResponse
    {
        $this->authorize('automation.manage');

        $data = $request->validate([
            'ticket_key' => ['required', 'string', 'max:32'],
        ]);

        $ticket = Ticket::query()->where('key', mb_strtoupper($data['ticket_key']))->first();

        if (! $ticket) {
            return back()->with('error', __('automation.preview.no_ticket', ['key' => $data['ticket_key']]));
        }

        [$matches, $reason] = $engine->preview($rule, $ticket);

        return back()->with(
            $matches ? 'success' : 'info',
            $matches
                ? __('automation.preview.matches', ['key' => $ticket->key])
                : __('automation.preview.skips', ['key' => $ticket->key, 'reason' => $reason]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'triggers' => AutomationRule::TRIGGERS,
            'fields' => AutomationRule::FIELDS,
            'operators' => AutomationRule::OPERATORS,
            'valueless_operators' => AutomationRule::VALUELESS_OPERATORS,
            'actions' => AutomationRule::ACTIONS,
            'statuses' => TicketStatus::query()->orderBy('position')->get()
                ->map(fn (TicketStatus $status) => $status->toSummaryArray())->all(),
            'status_categories' => [
                TicketStatus::CATEGORY_NEW, TicketStatus::CATEGORY_OPEN, TicketStatus::CATEGORY_PENDING,
                TicketStatus::CATEGORY_RESOLVED, TicketStatus::CATEGORY_CLOSED,
            ],
            'priorities' => Priority::query()->orderBy('level')->get()
                ->map(fn (Priority $priority) => $priority->toSummaryArray())->all(),
            'queues' => Queue::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'labels' => Label::query()->orderBy('name')->get()
                ->map(fn (Label $label) => $label->toSummaryArray())->all(),
            'organizations' => Organization::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->all(),
            'request_types' => RequestType::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->all(),
            'people' => $this->namedPeople(),
            'sources' => Ticket::SOURCES,
        ];
    }

    /**
     * The people the saved rules already name.
     *
     * Not every agent on the desk, which is what this used to render into the
     * page. A rule refers to somebody in two places — an `assign_user` or
     * `add_watcher` action, and an `assignee` condition — and both live inside
     * the rule's JSON, so that is where the ids come from. The picker searches
     * `GET /people` for anybody being added.
     *
     * A condition's value may be a single id or a list, because "is one of"
     * exists; both shapes are read.
     *
     * @return array<int, array<string, mixed>>
     */
    private function namedPeople(): array
    {
        $ids = [];

        foreach (AutomationRule::query()->get(['conditions', 'actions']) as $rule) {
            foreach ((array) ($rule->actions ?? []) as $action) {
                if (isset($action['user_id'])) {
                    $ids[] = $action['user_id'];
                }
            }

            foreach ((array) ($rule->conditions ?? []) as $condition) {
                if (($condition['field'] ?? null) !== 'assignee') {
                    continue;
                }

                foreach (Arr::wrap($condition['value'] ?? null) as $value) {
                    $ids[] = $value;
                }
            }
        }

        return User::summariesFor($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AutomationRule $rule): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('automation_rules', 'slug')->ignore($rule?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'trigger' => ['required', Rule::in(AutomationRule::TRIGGERS)],
            'trigger_config' => ['nullable', 'array'],
            'trigger_config.cron' => ['nullable', 'string', 'max:64'],
            'match_type' => ['required', Rule::in(['all', 'any'])],
            'is_active' => ['boolean'],
            'stop_processing' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:65535'],

            'conditions' => ['array', 'max:20'],
            'conditions.*.field' => ['required', Rule::in(array_keys(AutomationRule::FIELDS))],
            'conditions.*.operator' => ['required', Rule::in(AutomationRule::OPERATORS)],
            'conditions.*.value' => ['nullable'],

            // A rule that does nothing is not a rule.
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(AutomationRule::ACTIONS)],
            'actions.*.user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'actions.*.team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'actions.*.priority_id' => ['nullable', 'integer', Rule::exists('priorities', 'id')],
            'actions.*.queue_id' => ['nullable', 'integer', Rule::exists('queues', 'id')],
            'actions.*.status_id' => ['nullable', 'integer', Rule::exists('ticket_statuses', 'id')],
            'actions.*.label_id' => ['nullable', 'integer', Rule::exists('labels', 'id')],
            'actions.*.body' => ['nullable', 'string', 'max:5000'],
            'actions.*.internal' => ['boolean'],
            // https only: a rule that can post anywhere is a request forgery
            // primitive, and the payload carries ticket content.
            'actions.*.url' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
            'actions.*.secret' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['trigger'] ?? null) === AutomationRule::TRIGGER_SCHEDULED) {
            $this->validateCron($request, $validated['trigger_config']['cron'] ?? null);
        } else {
            // A cron on a rule that is not scheduled would never run and would
            // read as if it might.
            $validated['trigger_config'] = null;
        }

        $validated['conditions'] = array_values($validated['conditions'] ?? []);
        $validated['actions'] = array_values($validated['actions']);

        return $validated;
    }

    /**
     * A schedule that does not parse never runs, so it is refused at the form
     * rather than accepted and silently ignored.
     */
    private function validateCron(Request $request, ?string $expression): void
    {
        $expression = trim((string) $expression);

        if ($expression === '') {
            return;
        }

        try {
            new CronExpression($expression);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'trigger_config.cron' => __('automation.rules.bad_cron'),
            ]);
        }
    }
}
