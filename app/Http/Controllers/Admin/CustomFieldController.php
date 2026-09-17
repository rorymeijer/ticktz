<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('settings.manage');

        return Inertia::render('Admin/CustomFields/Index', [
            'fields' => CustomField::query()
                ->withCount('values')
                ->orderBy('entity')
                ->orderBy('position')
                ->get()
                ->map(fn (CustomField $field) => [
                    'id' => $field->id,
                    'key' => $field->key,
                    'label' => $field->label,
                    'label_translations' => $field->label_translations ?? [],
                    'type' => $field->type,
                    'options' => $field->options ?? [],
                    'placeholder' => $field->placeholder,
                    'help_text' => $field->help_text,
                    'default_value' => $field->default_value,
                    'is_required' => $field->is_required,
                    'is_public' => $field->is_public,
                    'validation' => $field->validation ?? [],
                    'entity' => $field->entity,
                    'is_active' => $field->is_active,
                    'position' => $field->position,
                    'values_count' => $field->values_count,
                ])->all(),
            'types' => CustomField::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $field = CustomField::query()->create($this->validateField($request, null));

        $this->audit->created($field, "Created custom field {$field->key}");

        return back()->with('success', __('admin.custom_fields.created', ['name' => $field->label]));
    }

    public function update(Request $request, CustomField $customField): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $this->validateField($request, $customField);

        // Changing a field's type would reinterpret every value already stored
        // under it, so the type is fixed once answers exist.
        if ($customField->values()->exists()) {
            unset($data['type'], $data['key']);
        }

        $customField->fill($data)->save();

        $this->audit->updated($customField, "Updated custom field {$customField->key}");

        return back()->with('success', __('admin.custom_fields.updated', ['name' => $customField->label]));
    }

    public function destroy(CustomField $customField): RedirectResponse
    {
        $this->authorize('settings.manage');

        if ($customField->values()->exists()) {
            return back()->with('error', __('admin.custom_fields.in_use'));
        }

        $this->audit->deleted($customField, "Deleted custom field {$customField->key}");
        $customField->delete();

        return back()->with('success', __('admin.custom_fields.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateField(Request $request, ?CustomField $field): array
    {
        $validated = $request->validate([
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('custom_fields', 'key')->ignore($field?->getKey()),
            ],
            'label' => ['required', 'string', 'max:255'],
            'label_translations' => ['array'],
            'label_translations.*' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(CustomField::TYPES)],
            'options' => ['array'],
            'options.*.value' => ['required', 'string', 'max:255'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'default_value' => ['nullable', 'string', 'max:500'],
            'is_required' => ['boolean'],
            'is_public' => ['boolean'],
            'validation' => ['array'],
            'validation.min' => ['nullable', 'numeric'],
            'validation.max' => ['nullable', 'numeric'],
            'validation.regex' => ['nullable', 'string', 'max:255'],
            'entity' => ['required', Rule::in(['ticket', 'asset'])],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:1000'],
        ], [
            'key.regex' => __('admin.custom_fields.key_format'),
        ]);

        // Options only mean something for select-style fields; storing them for
        // a text field would show up in the API as noise.
        if (! in_array($validated['type'], CustomField::OPTION_TYPES, true)) {
            $validated['options'] = null;
        }

        $validated['label_translations'] = array_filter($validated['label_translations'] ?? []);
        $validated['validation'] = array_filter(
            $validated['validation'] ?? [],
            static fn ($value) => $value !== null && $value !== '',
        );

        return $validated;
    }
}
