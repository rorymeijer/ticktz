<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Priority;
use App\Models\Ticket;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PriorityController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $priority = Priority::query()->create($this->validatePriority($request, null));
        $this->ensureSingleDefault($priority);

        $this->audit->created($priority, "Created priority {$priority->name}");

        return back()->with('success', __('admin.service_desk.priorities.created', ['name' => $priority->name]));
    }

    public function update(Request $request, Priority $priority): RedirectResponse
    {
        $this->authorize('settings.manage');

        $priority->fill($this->validatePriority($request, $priority))->save();
        $this->ensureSingleDefault($priority);

        $this->audit->updated($priority, "Updated priority {$priority->name}");

        return back()->with('success', __('admin.service_desk.priorities.updated', ['name' => $priority->name]));
    }

    public function destroy(Priority $priority): RedirectResponse
    {
        $this->authorize('settings.manage');

        if (Ticket::query()->where('priority_id', $priority->getKey())->exists()) {
            return back()->with('error', __('admin.service_desk.priorities.in_use'));
        }

        if (Priority::query()->count() <= 1) {
            return back()->with('error', __('admin.service_desk.priorities.last_one'));
        }

        $this->audit->deleted($priority, "Deleted priority {$priority->name}");
        $priority->delete();

        return back()->with('success', __('admin.service_desk.priorities.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePriority(Request $request, ?Priority $priority): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('priorities', 'slug')->ignore($priority?->getKey()),
            ],
            'level' => ['required', 'integer', 'min:1', 'max:9'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_default' => ['boolean'],
            'is_public' => ['boolean'],
        ]);
    }

    private function ensureSingleDefault(Priority $priority): void
    {
        if ($priority->is_default) {
            Priority::query()->whereKeyNot($priority->getKey())->update(['is_default' => false]);
        }
    }
}
