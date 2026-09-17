<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetType;
use App\Models\CustomField;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuring the kinds of thing the desk keeps track of.
 *
 * The attributes a type carries are {@see CustomField}s scoped to the `asset`
 * entity, chosen here — the same machinery a request type uses for its form.
 * That is why there is no field editor on this screen: fields are defined once
 * in Custom fields and used in both places.
 */
class AssetTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('assets.manage');

        return Inertia::render('Admin/AssetTypes/Index', [
            'types' => AssetType::query()
                ->with('fields:id,key,label')
                ->withCount('assets')
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (AssetType $type) => $type->toAdminArray() + [
                    'fields' => $type->fields->map(fn (CustomField $field) => [
                        'id' => $field->id,
                        'key' => $field->key,
                        'label' => $field->translatedLabel(),
                    ])->all(),
                ])
                ->all(),
            'fields' => CustomField::query()
                ->where('entity', 'asset')
                ->where('is_active', true)
                ->orderBy('position')
                ->get()
                ->map(fn (CustomField $field) => [
                    'id' => $field->id,
                    'key' => $field->key,
                    'label' => $field->translatedLabel(),
                    'type' => $field->type,
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('assets.manage');

        $data = $this->validated($request, null);

        $type = AssetType::query()->create($this->attributes($data));
        $this->syncFields($type, $data['field_ids'] ?? []);

        $this->audit->created($type, "Created asset type {$type->name}");

        return back()->with('success', __('assets.admin.created', ['name' => $type->name]));
    }

    public function update(Request $request, AssetType $type): RedirectResponse
    {
        $this->authorize('assets.manage');

        $data = $this->validated($request, $type);

        $type->fill($this->attributes($data))->save();
        $this->syncFields($type, $data['field_ids'] ?? []);

        $this->audit->updated($type, "Updated asset type {$type->name}");

        return back()->with('success', __('assets.admin.updated', ['name' => $type->name]));
    }

    public function destroy(AssetType $type): RedirectResponse
    {
        $this->authorize('assets.manage');

        // Refused rather than cascading. The foreign key would take every
        // asset of this type with it, and "I deleted a type and lost four
        // hundred laptops" is not a mistake to let somebody make in one click.
        if ($type->assets()->withTrashed()->exists()) {
            return back()->with('error', __('assets.admin.in_use'));
        }

        $this->audit->deleted($type, "Deleted asset type {$type->name}");
        $type->delete();

        return back()->with('success', __('assets.admin.deleted'));
    }

    /**
     * @param  array<int, int>  $fieldIds
     */
    private function syncFields(AssetType $type, array $fieldIds): void
    {
        $position = 0;

        $type->fields()->sync(
            collect($fieldIds)
                ->mapWithKeys(function (int $id) use (&$position): array {
                    $position += 10;

                    return [$id => ['position' => $position]];
                })
                ->all(),
        );
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
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? '#64748b',
            'tag_prefix' => isset($data['tag_prefix']) ? mb_strtoupper($data['tag_prefix']) : null,
            'is_active' => $data['is_active'] ?? true,
            'position' => $data['position'] ?? 100,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AssetType $type): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_translations' => ['array'],
            'name_translations.*' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('asset_types', 'slug')->ignore($type?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:9'],
            // The prefix ends up in every tag this type generates, so it is
            // letters and digits only — a slash in a tag is a broken URL.
            'tag_prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:65535'],
            'field_ids' => ['array'],
            'field_ids.*' => ['integer', Rule::exists('custom_fields', 'id')],
        ]);
    }
}
