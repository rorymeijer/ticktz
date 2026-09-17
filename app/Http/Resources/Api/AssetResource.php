<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A thing the desk supports.
 *
 * The purchase cost is in minor units (cents) with its currency alongside,
 * because that is how it is stored: a float would round, and an integer
 * without a currency is a number nobody can safely add up.
 *
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_tag' => $this->asset_tag,
            'name' => $this->name,
            'status' => $this->status,
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'location' => $this->location,
            'notes' => $this->notes,
            'type' => $this->whenLoaded('type', fn () => $this->type ? [
                'id' => $this->type->id,
                'slug' => $this->type->slug,
                'name' => $this->type->translatedName(),
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee
                ? UserStubResource::make($this->assignee)
                : null),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ] : null),
            'purchased_at' => $this->purchased_at?->toDateString(),
            'warranty_ends_at' => $this->warranty_ends_at?->toDateString(),
            'purchase_cost' => $this->purchase_cost,
            'currency' => $this->currency,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
