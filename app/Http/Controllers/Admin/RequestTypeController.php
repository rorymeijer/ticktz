<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflow;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\PortalCategory;
use App\Models\Priority;
use App\Models\Queue;
use App\Models\RequestType;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\Workflow;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Request types and the portal categories that group them.
 */
class RequestTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('settings.manage');

        return Inertia::render('Admin/RequestTypes/Index', [
            'categories' => PortalCategory::query()
                ->orderBy('position')
                ->get()
                ->map(fn (PortalCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'name_translations' => $category->name_translations ?? [],
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'icon' => $category->icon,
                    'color' => $category->color,
                    'is_active' => $category->is_active,
                    'position' => $category->position,
                ])->all(),
            'requestTypes' => RequestType::query()
                ->with('category:id,name')
                ->withCount('fields')
                ->orderBy('position')
                ->get()
                ->map(fn (RequestType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'slug' => $type->slug,
                    'description' => $type->description,
                    'category' => $type->category?->only('id', 'name'),
                    'visibility' => $type->visibility,
                    'is_active' => $type->is_active,
                    'fields_count' => $type->fields_count,
                    'ticket_count' => Ticket::query()->where('request_type_id', $type->id)->count(),
                ])->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('settings.manage');

        return Inertia::render('Admin/RequestTypes/Form', ['requestType' => null] + $this->options());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $this->validateRequestType($request, null);

        $requestType = DB::transaction(function () use ($data): RequestType {
            $type = RequestType::query()->create($this->attributes($data));
            $this->syncFields($type, $data);

            return $type;
        });

        $this->audit->created($requestType, "Created request type {$requestType->name}");

        return redirect()->route('admin.request-types.index')
            ->with('success', __('admin.request_types.created', ['name' => $requestType->name]));
    }

    public function edit(RequestType $requestType): Response
    {
        $this->authorize('settings.manage');

        $requestType->load('fields');

        return Inertia::render('Admin/RequestTypes/Form', [
            'requestType' => [
                'id' => $requestType->id,
                'portal_category_id' => $requestType->portal_category_id,
                'name' => $requestType->name,
                'name_translations' => $requestType->name_translations ?? [],
                'slug' => $requestType->slug,
                'description' => $requestType->description,
                'description_translations' => $requestType->description_translations ?? [],
                'instructions' => $requestType->instructions,
                'instructions_translations' => $requestType->instructions_translations ?? [],
                'icon' => $requestType->icon,
                'queue_id' => $requestType->queue_id,
                'team_id' => $requestType->team_id,
                'workflow_id' => $requestType->workflow_id,
                'priority_id' => $requestType->priority_id,
                'allow_priority_choice' => $requestType->allow_priority_choice,
                'subject_template' => $requestType->subject_template,
                'visibility' => $requestType->visibility,
                'organization_ids' => $requestType->organization_ids ?? [],
                'is_active' => $requestType->is_active,
                'position' => $requestType->position,
                'fields' => $requestType->fields->map(fn (CustomField $field) => [
                    'custom_field_id' => $field->id,
                    'is_required' => $field->pivot->is_required,
                    'help_text' => $field->pivot->help_text,
                ])->all(),
            ],
        ] + $this->options());
    }

    public function update(Request $request, RequestType $requestType): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $this->validateRequestType($request, $requestType);

        DB::transaction(function () use ($requestType, $data): void {
            $requestType->fill($this->attributes($data))->save();
            $this->syncFields($requestType, $data);
        });

        $this->audit->updated($requestType, "Updated request type {$requestType->name}");

        return redirect()->route('admin.request-types.index')
            ->with('success', __('admin.request_types.updated', ['name' => $requestType->name]));
    }

    public function destroy(RequestType $requestType): RedirectResponse
    {
        $this->authorize('settings.manage');

        if (Ticket::query()->where('request_type_id', $requestType->getKey())->exists()) {
            return back()->with('error', __('admin.request_types.in_use'));
        }

        $this->audit->deleted($requestType, "Deleted request type {$requestType->name}");
        $requestType->delete();

        return back()->with('success', __('admin.request_types.deleted'));
    }

    // -----------------------------------------------------------------
    // Portal categories
    // -----------------------------------------------------------------

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $category = PortalCategory::query()->create($this->validateCategory($request, null));

        $this->audit->created($category, "Created portal category {$category->name}");

        return back()->with('success', __('admin.request_types.categories.created', ['name' => $category->name]));
    }

    public function updateCategory(Request $request, PortalCategory $category): RedirectResponse
    {
        $this->authorize('settings.manage');

        $category->fill($this->validateCategory($request, $category))->save();

        $this->audit->updated($category, "Updated portal category {$category->name}");

        return back()->with('success', __('admin.request_types.categories.updated', ['name' => $category->name]));
    }

    public function destroyCategory(PortalCategory $category): RedirectResponse
    {
        $this->authorize('settings.manage');

        // Request types survive: they simply become uncategorised and keep
        // being reachable on the portal.
        $this->audit->deleted($category, "Deleted portal category {$category->name}");
        $category->delete();

        return back()->with('success', __('admin.request_types.categories.deleted'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = $data;
        unset($attributes['fields']);

        if ($attributes['visibility'] !== 'organizations') {
            $attributes['organization_ids'] = null;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncFields(RequestType $requestType, array $data): void
    {
        $position = 0;

        $requestType->fields()->sync(
            collect($data['fields'] ?? [])
                ->mapWithKeys(function (array $field) use (&$position): array {
                    $position += 10;

                    return [
                        (int) $field['custom_field_id'] => [
                            'is_required' => $field['is_required'] ?? null,
                            'help_text' => $field['help_text'] ?? null,
                            'position' => $position,
                        ],
                    ];
                })
                ->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'categories' => PortalCategory::query()->orderBy('position')->get(['id', 'name'])->all(),
            'queues' => Queue::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'workflows' => Workflow::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'approvalWorkflows' => ApprovalWorkflow::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->all(),
            'priorities' => Priority::query()->orderBy('level')->get()
                ->map(fn (Priority $priority) => $priority->toSummaryArray())->all(),
            'organizations' => Organization::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'customFields' => CustomField::query()
                ->where('entity', 'ticket')
                ->where('is_active', true)
                ->orderBy('position')
                ->get()
                ->map(fn (CustomField $field) => [
                    'id' => $field->id,
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type,
                    'is_required' => $field->is_required,
                ])->all(),
            'locales' => array_keys(config('ticktz.locales')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRequestType(Request $request, ?RequestType $requestType): array
    {
        $validated = $request->validate([
            'portal_category_id' => ['nullable', 'integer', Rule::exists('portal_categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'name_translations' => ['array'],
            'name_translations.*' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('request_types', 'slug')->ignore($requestType?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'description_translations' => ['array'],
            'description_translations.*' => ['nullable', 'string', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'instructions_translations' => ['array'],
            'instructions_translations.*' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:40'],
            'queue_id' => ['nullable', 'integer', Rule::exists('queues', 'id')],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'workflow_id' => ['nullable', 'integer', Rule::exists('workflows', 'id')],
            'approval_workflow_id' => ['nullable', 'integer', Rule::exists('approval_workflows', 'id')],
            'priority_id' => ['nullable', 'integer', Rule::exists('priorities', 'id')],
            'allow_priority_choice' => ['boolean'],
            'subject_template' => ['nullable', 'string', 'max:255'],
            'visibility' => ['required', Rule::in(['everyone', 'organizations', 'agents'])],
            'organization_ids' => ['array'],
            'organization_ids.*' => ['integer', Rule::exists('organizations', 'id')],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:1000'],
            'fields' => ['array'],
            'fields.*.custom_field_id' => ['required', 'integer', Rule::exists('custom_fields', 'id')],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.help_text' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['name_translations', 'description_translations', 'instructions_translations'] as $key) {
            $validated[$key] = array_filter($validated[$key] ?? []);
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCategory(Request $request, ?PortalCategory $category): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_translations' => ['array'],
            'name_translations.*' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('portal_categories', 'slug')->ignore($category?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:40'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:1000'],
        ]);

        $validated['name_translations'] = array_filter($validated['name_translations'] ?? []);

        return $validated;
    }
}
