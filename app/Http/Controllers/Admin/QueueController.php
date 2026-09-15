<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Queue;
use App\Models\Team;
use App\Models\TicketStatus;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('create', Queue::class);

        return Inertia::render('Admin/Queues/Index', [
            'queues' => Queue::query()
                ->with('team:id,name')
                ->withCount('tickets')
                ->orderBy('position')
                ->get()
                ->map(fn (Queue $queue) => $queue->toSummaryArray() + [
                    'is_active' => $queue->is_active,
                    'is_shared' => $queue->is_shared,
                    'position' => $queue->position,
                    'tickets_count' => $queue->tickets_count,
                ])->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Queue::class);

        return Inertia::render('Admin/Queues/Form', ['queue' => null] + $this->options());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Queue::class);

        $queue = Queue::query()->create($this->validateQueue($request, null));

        $this->audit->created($queue, "Created queue {$queue->name}");

        return redirect()->route('admin.queues.index')
            ->with('success', __('admin.queues.created', ['name' => $queue->name]));
    }

    public function edit(Queue $queue): Response
    {
        $this->authorize('update', $queue);

        return Inertia::render('Admin/Queues/Form', [
            'queue' => $queue->toSummaryArray() + [
                'team_id' => $queue->team_id,
                'is_active' => $queue->is_active,
                'is_shared' => $queue->is_shared,
                'position' => $queue->position,
            ],
        ] + $this->options());
    }

    public function update(Request $request, Queue $queue): RedirectResponse
    {
        $this->authorize('update', $queue);

        $queue->fill($this->validateQueue($request, $queue))->save();

        $this->audit->updated($queue, "Updated queue {$queue->name}");

        return redirect()->route('admin.queues.index')
            ->with('success', __('admin.queues.updated', ['name' => $queue->name]));
    }

    public function destroy(Queue $queue): RedirectResponse
    {
        $this->authorize('delete', $queue);

        // Tickets reference a queue but are not owned by it, so deleting one
        // simply detaches; nothing is lost.
        $this->audit->deleted($queue, "Deleted queue {$queue->name}");
        $queue->delete();

        return back()->with('success', __('admin.queues.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'statuses' => TicketStatus::query()->orderBy('position')->get()
                ->map(fn (TicketStatus $status) => $status->toSummaryArray())->all(),
            'priorities' => Priority::query()->orderBy('level')->get()
                ->map(fn (Priority $priority) => $priority->toSummaryArray())->all(),
            'labels' => Label::query()->orderBy('name')->get()
                ->map(fn (Label $label) => $label->toSummaryArray())->all(),
            'availableColumns' => Queue::AVAILABLE_COLUMNS,
            'sortableColumns' => Queue::SORTABLE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateQueue(Request $request, ?Queue $queue): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('queues', 'slug')->ignore($queue?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'filters' => ['array'],
            'filters.status_category' => ['array'],
            'filters.status_category.*' => [Rule::in(['new', 'open', 'pending', 'resolved', 'closed'])],
            'filters.status' => ['array'],
            'filters.status.*' => ['integer', Rule::exists('ticket_statuses', 'id')],
            'filters.priority' => ['array'],
            'filters.priority.*' => ['integer', Rule::exists('priorities', 'id')],
            'filters.assignee' => ['array'],
            'filters.assignee.*' => ['string', 'max:20'],
            'filters.team' => ['array'],
            'filters.team.*' => ['string', 'max:20'],
            'filters.label' => ['array'],
            'filters.label.*' => ['integer', Rule::exists('labels', 'id')],
            'filters.unanswered' => ['boolean'],
            'sort_by' => ['required', Rule::in(Queue::SORTABLE)],
            'sort_direction' => ['required', Rule::in(['asc', 'desc'])],
            'columns' => ['array', 'min:1'],
            'columns.*' => [Rule::in(Queue::AVAILABLE_COLUMNS)],
            'is_shared' => ['boolean'],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:1000'],
        ]);

        // Drop empty filter keys so a queue definition stays readable and the
        // filter service does not have to defend against blank arrays.
        $validated['filters'] = array_filter(
            $validated['filters'] ?? [],
            static fn ($value) => $value !== null && $value !== '' && $value !== [] && $value !== false,
        );

        return $validated;
    }
}
