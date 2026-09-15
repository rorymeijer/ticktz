<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\Workflow;
use App\Models\WorkflowTransition;
use App\Services\AuditLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workflow editing.
 *
 * A workflow is stored as "which statuses participate" plus "which moves are
 * legal". The editor presents that as a matrix, which is the only way a
 * six-status process stays comprehensible.
 */
class WorkflowController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('settings.manage');

        return Inertia::render('Admin/Workflows/Index', [
            'workflows' => Workflow::query()
                ->withCount(['statuses', 'transitions'])
                ->orderBy('name')
                ->get()
                ->map(fn (Workflow $workflow) => [
                    'id' => $workflow->id,
                    'name' => $workflow->name,
                    'slug' => $workflow->slug,
                    'description' => $workflow->description,
                    'is_default' => $workflow->is_default,
                    'is_active' => $workflow->is_active,
                    'statuses_count' => $workflow->statuses_count,
                    'transitions_count' => $workflow->transitions_count,
                    'ticket_count' => Ticket::query()->where('workflow_id', $workflow->id)->count(),
                ])->all(),
        ]);
    }

    public function edit(Workflow $workflow): Response
    {
        $this->authorize('settings.manage');

        $workflow->load(['statuses', 'transitions']);

        return Inertia::render('Admin/Workflows/Form', [
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'slug' => $workflow->slug,
                'description' => $workflow->description,
                'is_default' => $workflow->is_default,
                'is_active' => $workflow->is_active,
                'status_ids' => $workflow->statuses->pluck('id')->all(),
                'initial_status_id' => $workflow->initialStatus()?->getKey(),
                'transitions' => $workflow->transitions->map(fn (WorkflowTransition $transition) => [
                    'from_status_id' => $transition->from_status_id,
                    'to_status_id' => $transition->to_status_id,
                    'requires_comment' => $transition->requires_comment,
                    'requires_assignee' => $transition->requires_assignee,
                    'required_permission' => $transition->required_permission,
                ])->all(),
            ],
            'statuses' => TicketStatus::query()->orderBy('position')->get()
                ->map(fn (TicketStatus $status) => $status->toSummaryArray())->all(),
            'permissions' => PermissionCatalog::GROUPS['tickets'],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('settings.manage');

        return Inertia::render('Admin/Workflows/Form', [
            'workflow' => null,
            'statuses' => TicketStatus::query()->orderBy('position')->get()
                ->map(fn (TicketStatus $status) => $status->toSummaryArray())->all(),
            'permissions' => PermissionCatalog::GROUPS['tickets'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $this->validateWorkflow($request, null);

        $workflow = DB::transaction(function () use ($data): Workflow {
            $workflow = Workflow::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncDefinition($workflow, $data);

            return $workflow;
        });

        $this->audit->created($workflow, "Created workflow {$workflow->name}");

        return redirect()->route('admin.workflows.index')
            ->with('success', __('admin.workflows.created', ['name' => $workflow->name]));
    }

    public function update(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $this->validateWorkflow($request, $workflow);

        DB::transaction(function () use ($workflow, $data): void {
            $workflow->fill([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            $this->syncDefinition($workflow, $data);
        });

        $this->audit->updated($workflow, "Updated workflow {$workflow->name}");

        return redirect()->route('admin.workflows.index')
            ->with('success', __('admin.workflows.updated', ['name' => $workflow->name]));
    }

    public function destroy(Workflow $workflow): RedirectResponse
    {
        $this->authorize('settings.manage');

        if (Ticket::query()->where('workflow_id', $workflow->getKey())->exists()) {
            return back()->with('error', __('admin.workflows.in_use'));
        }

        $this->audit->deleted($workflow, "Deleted workflow {$workflow->name}");
        $workflow->delete();

        return back()->with('success', __('admin.workflows.deleted'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncDefinition(Workflow $workflow, array $data): void
    {
        $statusIds = array_values(array_unique($data['status_ids']));
        $initial = (int) ($data['initial_status_id'] ?? $statusIds[0]);

        $positions = TicketStatus::query()
            ->whereIn('id', $statusIds)
            ->pluck('position', 'id');

        $workflow->statuses()->sync(
            collect($statusIds)->mapWithKeys(fn (int $id) => [
                $id => ['is_initial' => $id === $initial, 'position' => $positions[$id] ?? 0],
            ])->all()
        );

        $workflow->transitions()->delete();

        $position = 0;

        foreach ($data['transitions'] ?? [] as $transition) {
            // A transition to or from a status that is not part of the
            // workflow would be unreachable; drop it rather than store it.
            if (! in_array((int) $transition['to_status_id'], $statusIds, true)) {
                continue;
            }

            $from = $transition['from_status_id'] ?? null;

            if ($from !== null && ! in_array((int) $from, $statusIds, true)) {
                continue;
            }

            $position += 10;

            $workflow->transitions()->create([
                'from_status_id' => $from ?: null,
                'to_status_id' => (int) $transition['to_status_id'],
                'requires_comment' => (bool) ($transition['requires_comment'] ?? false),
                'requires_assignee' => (bool) ($transition['requires_assignee'] ?? false),
                'required_permission' => $transition['required_permission'] ?? null,
                'position' => $position,
            ]);
        }

        if ($workflow->is_default) {
            Workflow::query()->whereKeyNot($workflow->getKey())->update(['is_default' => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWorkflow(Request $request, ?Workflow $workflow): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('workflows', 'slug')->ignore($workflow?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'status_ids' => ['required', 'array', 'min:1'],
            'status_ids.*' => ['integer', Rule::exists('ticket_statuses', 'id')],
            'initial_status_id' => ['required', 'integer', Rule::exists('ticket_statuses', 'id')],
            'transitions' => ['array'],
            'transitions.*.from_status_id' => ['nullable', 'integer', Rule::exists('ticket_statuses', 'id')],
            'transitions.*.to_status_id' => ['required', 'integer', Rule::exists('ticket_statuses', 'id')],
            'transitions.*.requires_comment' => ['boolean'],
            'transitions.*.requires_assignee' => ['boolean'],
            'transitions.*.required_permission' => ['nullable', 'string', Rule::in(PermissionCatalog::all())],
        ]);
    }
}
