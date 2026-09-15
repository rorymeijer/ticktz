<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statuses, priorities and labels share one admin screen: they are the small
 * vocabulary a service desk tunes once, and splitting them over three pages
 * would mean three round trips to set up an instance.
 */
class TicketStatusController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('settings.manage');

        return Inertia::render('Admin/ServiceDesk/Index', [
            'statuses' => TicketStatus::query()
                ->withCount('workflows')
                ->orderBy('position')
                ->get()
                ->map(fn (TicketStatus $status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'name_translations' => $status->name_translations ?? [],
                    'slug' => $status->slug,
                    'category' => $status->category,
                    'color' => $status->color,
                    'description' => $status->description,
                    'pauses_sla' => $status->pauses_sla,
                    'is_public' => $status->is_public,
                    'is_system' => $status->is_system,
                    'position' => $status->position,
                    'ticket_count' => Ticket::query()->where('status_id', $status->id)->count(),
                ])->all(),
            'priorities' => Priority::query()->orderBy('level')->get()->map(fn (Priority $priority) => [
                'id' => $priority->id,
                'name' => $priority->name,
                'name_translations' => $priority->name_translations ?? [],
                'slug' => $priority->slug,
                'level' => $priority->level,
                'color' => $priority->color,
                'is_default' => $priority->is_default,
                'is_public' => $priority->is_public,
                'ticket_count' => Ticket::query()->where('priority_id', $priority->id)->count(),
            ])->all(),
            'labels' => Label::query()->withCount('tickets')->orderBy('name')->get()->map(fn (Label $label) => [
                'id' => $label->id,
                'name' => $label->name,
                'name_translations' => $label->name_translations ?? [],
                'slug' => $label->slug,
                'color' => $label->color,
                'description' => $label->description,
                'ticket_count' => $label->tickets_count,
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $status = TicketStatus::query()->create($this->validateStatus($request, null));

        $this->audit->created($status, "Created ticket status {$status->name}");

        return back()->with('success', __('admin.service_desk.statuses.created', ['name' => $status->name]));
    }

    public function update(Request $request, TicketStatus $status): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $this->validateStatus($request, $status);

        // A system status keeps its slug and category: the engine reasons
        // about both, and renaming them mid-flight would strand tickets.
        if ($status->is_system) {
            unset($data['slug'], $data['category']);
        }

        $status->fill($data)->save();

        $this->audit->updated($status, "Updated ticket status {$status->name}");

        return back()->with('success', __('admin.service_desk.statuses.updated', ['name' => $status->name]));
    }

    public function destroy(TicketStatus $status): RedirectResponse
    {
        $this->authorize('settings.manage');

        if ($status->is_system) {
            return back()->with('error', __('admin.service_desk.statuses.system_protected'));
        }

        if (Ticket::query()->where('status_id', $status->getKey())->exists()) {
            return back()->with('error', __('admin.service_desk.statuses.in_use'));
        }

        $this->audit->deleted($status, "Deleted ticket status {$status->name}");
        $status->delete();

        return back()->with('success', __('admin.service_desk.statuses.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStatus(Request $request, ?TicketStatus $status): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_translations' => ['array'],
            'name_translations.*' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('ticket_statuses', 'slug')->ignore($status?->getKey()),
            ],
            'category' => ['required', Rule::in(['new', 'open', 'pending', 'resolved', 'closed'])],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'pauses_sla' => ['boolean'],
            'is_public' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:1000'],
        ]);

        $validated['name_translations'] = array_filter($validated['name_translations'] ?? []);

        return $validated;
    }
}
