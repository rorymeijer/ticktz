<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Queue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved view of the desk.
 *
 * The filter definition is included because it is the useful part: an
 * integration that wants "everything this queue would show me" can read the
 * filters and pass them straight back to `GET /tickets`.
 *
 * @mixin Queue
 */
class QueueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'filters' => $this->filters ?? [],
            'sort_by' => $this->sort_by,
            'sort_direction' => $this->sort_direction,
            'team' => $this->whenLoaded('team', fn () => $this->team ? [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ] : null),
            'open_tickets' => $this->when(
                $this->open_tickets_count !== null,
                fn () => (int) $this->open_tickets_count,
            ),
        ];
    }
}
