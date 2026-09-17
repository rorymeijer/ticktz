<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\AssetResource;
use App\Models\Asset;
use App\Models\AssetType;
use App\Services\Assets\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The CMDB over the API.
 *
 * This is the endpoint most instances will actually automate: an inventory
 * agent or an MDM has the truth about the hardware, and typing it into a
 * second system by hand is how a CMDB stops being trusted.
 *
 * `asset_tag` is therefore the identifier callers use, and `PUT` on a tag that
 * does not exist creates it. That makes a sync script idempotent without it
 * having to track our ids: it can run twice, or run after a restore, and the
 * result is the same.
 */
class AssetController extends ApiController
{
    public function __construct(private readonly AssetService $assets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Asset::query()->with(['type', 'assignee', 'organization']);

        if ($search = $request->string('search')->toString()) {
            $query->search($search);
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($type = $request->string('type')->toString()) {
            $query->whereHas('type', fn ($types) => $types->where('slug', $type));
        }

        if ($assignee = $request->integer('assigned_to')) {
            $query->where('assigned_to', $assignee);
        }

        return AssetResource::collection(
            $query->orderBy('asset_tag')->paginate($this->perPage($request))
        );
    }

    public function show(Asset $asset): AssetResource
    {
        $asset->load(['type', 'assignee', 'organization']);

        return AssetResource::make($asset);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $asset = $this->assets->create($data, $request->user());
        $asset->load(['type', 'assignee', 'organization']);

        return AssetResource::make($asset)->response()->setStatusCode(201);
    }

    /**
     * Create or update by tag — the shape a sync script needs.
     *
     * The 200/201 distinction is kept because it is the only way the caller
     * can report "added 3, updated 112" without diffing anything itself.
     */
    public function upsert(Request $request, string $tag): JsonResponse
    {
        $existing = Asset::query()->where('asset_tag', $tag)->first();

        $data = $this->validated($request, $existing?->getKey());
        $data['asset_tag'] = $tag;

        if ($existing === null) {
            $asset = $this->assets->create($data, $request->user());
            $status = 201;
        } else {
            $asset = $this->assets->update($existing, $data, $request->user());
            $status = 200;
        }

        $asset->load(['type', 'assignee', 'organization']);

        return AssetResource::make($asset)->response()->setStatusCode($status);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'asset_tag' => [
                'sometimes', 'string', 'max:64',
                Rule::unique('assets', 'asset_tag')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'name' => [$ignoreId === null ? 'required' : 'sometimes', 'string', 'max:255'],
            'asset_type_id' => [$ignoreId === null ? 'required' : 'sometimes', 'integer', 'exists:asset_types,id'],
            'status' => ['sometimes', Rule::in(Asset::STATUSES)],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65000'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'organization_id' => ['sometimes', 'nullable', 'integer', 'exists:organizations,id'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'purchased_at' => ['sometimes', 'nullable', 'date'],
            'warranty_ends_at' => ['sometimes', 'nullable', 'date'],
            'purchase_cost' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ]);
    }

    /**
     * The asset types, so a sync script can map its own vocabulary onto ours
     * without an administrator having to read ids out of the database.
     */
    public function types(): JsonResponse
    {
        $types = AssetType::query()->orderBy('position')->get()
            ->map(fn (AssetType $type) => [
                'id' => $type->getKey(),
                'slug' => $type->slug,
                'name' => $type->translatedName(),
            ])->all();

        return new JsonResponse(['data' => $types]);
    }
}
