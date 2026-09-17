<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Assets\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The asset register as an agent uses it.
 *
 * Lives in the agent console rather than under administration because an agent
 * looks something up here several times a day — "whose laptop is LAP-0042, and
 * what else has it broken" — while an administrator configures asset *types*
 * once a quarter.
 */
class AssetController extends Controller
{
    public function __construct(private readonly AssetService $assets) {}

    public function index(Request $request): Response
    {
        $this->authorize('assets.view');

        /** @var User $user */
        $user = $request->user();
        $search = $request->string('q')->toString();

        return Inertia::render('Agent/Assets/Index', [
            'assets' => Asset::query()
                ->with(['type', 'assignee', 'organization'])
                ->search($search)
                ->when($request->filled('type'), fn ($query) => $query->whereHas(
                    'type',
                    fn ($type) => $type->where('slug', $request->string('type')),
                ))
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
                ->when(
                    $request->filled('organization'),
                    fn ($query) => $query->where('organization_id', $request->integer('organization')),
                )
                ->when(
                    $request->boolean('warranty_expired'),
                    fn ($query) => $query->whereNotNull('warranty_ends_at')->whereDate('warranty_ends_at', '<', now()),
                )
                ->orderBy('asset_tag')
                ->paginate(config('ticktz.per_page.tickets'))
                ->withQueryString()
                ->through(fn (Asset $asset) => $asset->toSummaryArray()),
            'filters' => [
                'q' => $search ?: null,
                'type' => $request->string('type')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
                'organization' => $request->integer('organization') ?: null,
                'warranty_expired' => $request->boolean('warranty_expired') ?: null,
            ],
            'options' => $this->options(),
            'can' => ['manage' => $user->can('assets.manage')],
        ]);
    }

    public function show(Request $request, Asset $asset): Response
    {
        $this->authorize('assets.view');

        /** @var User $user */
        $user = $request->user();

        $asset->load([
            'type.fields', 'assignee', 'organization', 'team',
            'customFieldValues.field',
            'links.relatedAsset.type', 'inverseLinks.asset.type',
            'tickets.status', 'tickets.requester',
        ]);

        return Inertia::render('Agent/Assets/Show', [
            'asset' => $asset->toDetailArray(),
            'tickets' => $asset->tickets
                ->sortByDesc('created_at')
                ->map(fn ($ticket) => [
                    'key' => $ticket->key,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status?->toSummaryArray(),
                    'requester' => $ticket->requester?->toSummaryArray(),
                    'created_at' => $ticket->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'options' => $this->options() + ['relation_types' => AssetRelation::TYPES],
            'can' => ['manage' => $user->can('assets.manage')],
        ]);
    }

    /**
     * Assets an agent might attach to a ticket.
     *
     * Seeded from the requester's own equipment when no term is given, which
     * is the difference between a search box an agent has to think about and
     * one that has already had a go: a ticket about a broken laptop is almost
     * always about *their* laptop.
     */
    public function suggest(Request $request): JsonResponse
    {
        $this->authorize('assets.view');

        $term = trim($request->string('q')->toString());
        $requesterId = $request->integer('requester');

        $assets = Asset::query()
            ->with(['type', 'assignee'])
            ->whereIn('status', Asset::LIVE_STATUSES)
            ->when($term !== '', fn ($query) => $query->search($term))
            ->when(
                $term === '' && $requesterId,
                fn ($query) => $query->where('assigned_to', $requesterId),
            )
            ->when($term === '' && ! $requesterId, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('asset_tag')
            ->limit(8)
            ->get()
            ->map(fn (Asset $asset) => $asset->toSummaryArray())
            ->all();

        return response()->json(['assets' => $assets]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('assets.manage');

        /** @var User $user */
        $user = $request->user();

        $data = $this->validated($request, null);

        $asset = $this->assets->create($data, $user);

        return redirect()
            ->route('agent.assets.show', $asset)
            ->with('success', __('assets.flash.created', ['tag' => $asset->asset_tag]));
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('assets.manage');

        /** @var User $user */
        $user = $request->user();

        $this->assets->update($asset, $this->validated($request, $asset), $user);

        return back()->with('success', __('assets.flash.updated'));
    }

    public function destroy(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('assets.manage');

        /** @var User $user */
        $user = $request->user();

        $this->assets->delete($asset, $user);

        return redirect()
            ->route('agent.assets.index')
            ->with('success', __('assets.flash.deleted'));
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function relate(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('assets.manage');

        $data = $request->validate([
            'related_asset_id' => ['required', 'integer', Rule::exists('assets', 'id')],
            'type' => ['required', Rule::in(AssetRelation::TYPES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $related = Asset::query()->findOrFail($data['related_asset_id']);

        try {
            $this->assets->relate($asset, $related, $data['type'], $data['note'] ?? null, $user);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('assets.flash.related'));
    }

    public function unrelate(Request $request, Asset $asset, AssetRelation $relation): RedirectResponse
    {
        $this->authorize('assets.manage');

        // A relation reached from the wrong asset is a relation somebody
        // guessed the id of.
        abort_if(
            $relation->asset_id !== $asset->getKey() && $relation->related_asset_id !== $asset->getKey(),
            404,
        );

        /** @var User $user */
        $user = $request->user();

        $this->assets->unrelate($relation, $user);

        return back()->with('success', __('assets.flash.unrelated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'types' => AssetType::query()->where('is_active', true)->orderBy('position')->orderBy('name')
                ->get()->map(fn (AssetType $type) => $type->toSummaryArray())->all(),
            'statuses' => Asset::STATUSES,
            'organizations' => Organization::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->all(),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Asset $asset): array
    {
        return $request->validate([
            'asset_type_id' => ['required', 'integer', Rule::exists('asset_types', 'id')],
            'asset_tag' => [
                'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.]+$/',
                Rule::unique('assets', 'asset_tag')->ignore($asset?->getKey())->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:128'],
            'manufacturer' => ['nullable', 'string', 'max:128'],
            'model' => ['nullable', 'string', 'max:128'],
            'status' => ['required', Rule::in(Asset::STATUSES)],
            'location' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'purchased_at' => ['nullable', 'date'],
            'warranty_ends_at' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'fields' => ['array'],
        ]);
    }
}
