<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuring who has to say yes.
 *
 * Steps are replaced wholesale on save rather than diffed. They are an ordered
 * list an administrator edits as a list, and a diff would have to guess
 * whether a moved row is a move or a delete-and-add. Replacing is safe here
 * for one specific reason: running approvals copy everything they need into
 * their own decision rows, so rewriting the definition cannot disturb one that
 * is already in flight.
 */
class ApprovalWorkflowController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('approvals.manage');

        return Inertia::render('Admin/Approvals/Index', [
            'workflows' => ApprovalWorkflow::query()
                ->with('steps')
                ->withCount('requestTypes')
                ->orderBy('name')
                ->get()
                ->map(fn (ApprovalWorkflow $workflow) => $workflow->toAdminArray())
                ->all(),
            'options' => [
                'modes' => ApprovalStep::MODES,
                'approver_types' => ApprovalStep::APPROVER_TYPES,
                'teams' => Team::query()->orderBy('name')->get(['id', 'name'])->all(),
                'roles' => Role::query()->orderBy('name')->get(['id', 'name'])->all(),
                'users' => User::query()->active()->agents()->orderBy('name')
                    ->get(['id', 'name', 'email'])->all(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('approvals.manage');

        $data = $this->validated($request, null);

        $workflow = DB::transaction(function () use ($data): ApprovalWorkflow {
            $workflow = ApprovalWorkflow::query()->create($this->attributes($data));

            $this->replaceSteps($workflow, $data['steps'] ?? []);

            return $workflow;
        });

        $this->audit->created($workflow, "Created approval workflow {$workflow->name}");

        return back()->with('success', __('approvals.admin.created', ['name' => $workflow->name]));
    }

    public function update(Request $request, ApprovalWorkflow $workflow): RedirectResponse
    {
        $this->authorize('approvals.manage');

        $data = $this->validated($request, $workflow);

        DB::transaction(function () use ($workflow, $data): void {
            $workflow->fill($this->attributes($data))->save();

            $this->replaceSteps($workflow, $data['steps'] ?? []);
        });

        $this->audit->updated($workflow, "Updated approval workflow {$workflow->name}");

        return back()->with('success', __('approvals.admin.updated', ['name' => $workflow->name]));
    }

    public function destroy(ApprovalWorkflow $workflow): RedirectResponse
    {
        $this->authorize('approvals.manage');

        // Request types pointing at it are released rather than blocked from
        // deleting it — the foreign key is `nullOnDelete`, and a request type
        // that stops requiring approval is a visible change on its own screen.
        // Approvals already running keep their own copies and carry on.
        $this->audit->deleted($workflow, "Deleted approval workflow {$workflow->name}");
        $workflow->delete();

        return back()->with('success', __('approvals.admin.deleted'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function replaceSteps(ApprovalWorkflow $workflow, array $steps): void
    {
        $workflow->steps()->delete();

        // Sorted by key before anything else, and this is not defensive
        // padding. `$request->validate()` assembles its result by walking the
        // *rules*, so a `steps.*` array comes back ordered by which optional
        // keys each entry happened to fill in — a step with a name is built
        // before one without. Trusting that order reverses an administrator's
        // sequential approval, which then asks the second approver first and
        // looks like it is working.
        ksort($steps, SORT_NUMERIC);

        foreach (array_values($steps) as $position => $step) {
            $workflow->steps()->create([
                'name' => $step['name'] ?? null,
                'mode' => $step['mode'] ?? ApprovalStep::ANY,
                'approver_type' => $step['approver_type'] ?? 'users',
                // Only meaningful for the id-based types; storing it for
                // `manager` would leave a stale list somebody later reads as
                // configuration.
                'approver_ids' => in_array($step['approver_type'] ?? 'users', ['users', 'team', 'role'], true)
                    ? array_values(array_map('intval', $step['approver_ids'] ?? []))
                    : null,
                'approver_field' => ($step['approver_type'] ?? null) === 'field'
                    ? ($step['approver_field'] ?? null)
                    : null,
                'due_hours' => $step['due_hours'] ?? null,
                'position' => $position,
            ]);
        }

        $workflow->unsetRelation('steps');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'name_translations' => $data['name_translations'] ?? [],
            'slug' => $data['slug'] ?: Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ApprovalWorkflow $workflow): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_translations' => ['array'],
            'name_translations.*' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('approval_workflows', 'slug')->ignore($workflow?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],

            'steps' => ['array', 'max:10'],
            'steps.*.name' => ['nullable', 'string', 'max:255'],
            'steps.*.mode' => ['required', Rule::in(ApprovalStep::MODES)],
            'steps.*.approver_type' => ['required', Rule::in(ApprovalStep::APPROVER_TYPES)],
            'steps.*.approver_ids' => ['array', 'max:50'],
            'steps.*.approver_ids.*' => ['integer'],
            'steps.*.approver_field' => ['nullable', 'string', 'max:100'],
            'steps.*.due_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);
    }
}
