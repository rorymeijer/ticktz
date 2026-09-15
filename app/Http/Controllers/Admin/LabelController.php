<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabelController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $label = Label::query()->create($this->validateLabel($request, null));

        $this->audit->created($label, "Created label {$label->name}");

        return back()->with('success', __('admin.service_desk.labels.created', ['name' => $label->name]));
    }

    public function update(Request $request, Label $label): RedirectResponse
    {
        $this->authorize('settings.manage');

        $label->fill($this->validateLabel($request, $label))->save();

        $this->audit->updated($label, "Updated label {$label->name}");

        return back()->with('success', __('admin.service_desk.labels.updated', ['name' => $label->name]));
    }

    public function destroy(Label $label): RedirectResponse
    {
        $this->authorize('settings.manage');

        // Labels are advisory: removing one detaches it rather than refusing.
        $this->audit->deleted($label, "Deleted label {$label->name}");
        $label->delete();

        return back()->with('success', __('admin.service_desk.labels.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateLabel(Request $request, ?Label $label): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('labels', 'slug')->ignore($label?->getKey()),
            ],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
