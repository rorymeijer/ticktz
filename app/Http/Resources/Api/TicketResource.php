<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as the public API presents it.
 *
 * The shape is built by naming every field, never by taking the model and
 * removing what should not be there. A column added to `tickets` next year
 * must not appear in this payload because somebody forgot to exclude it —
 * that is how internal notes and e-mail addresses leak out of APIs.
 *
 * Relations are rendered only when they were eager-loaded. That keeps the
 * resource honest about N+1: a list endpoint that forgets to load `assignee`
 * returns null rather than quietly issuing a query per row.
 *
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'subject' => $this->subject,
            'description' => $this->description,
            'source' => $this->source,
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status->id,
                'name' => $this->status->translatedName(),
                'slug' => $this->status->slug,
                'category' => $this->status->category,
                'is_open' => $this->status->isOpen(),
            ]),
            'priority' => $this->whenLoaded('priority', fn () => [
                'id' => $this->priority->id,
                'name' => $this->priority->translatedName(),
                'slug' => $this->priority->slug,
                'level' => $this->priority->level,
            ]),
            'queue' => $this->whenLoaded('queue', fn () => $this->queue ? [
                'id' => $this->queue->id,
                'name' => $this->queue->name,
                'slug' => $this->queue->slug,
            ] : null),
            'requester' => $this->whenLoaded('requester', fn () => UserStubResource::make($this->requester)),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee
                ? UserStubResource::make($this->assignee)
                : null),
            'team' => $this->whenLoaded('team', fn () => $this->team ? [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ] : null),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ] : null),
            'labels' => $this->whenLoaded('labels', fn () => $this->labels
                ->map(fn ($label) => ['id' => $label->id, 'name' => $label->name])
                ->all()),
            'approval_state' => $this->approval_state,
            'sla' => [
                'due_at' => $this->sla_due_at?->toIso8601String(),
                'breached' => (bool) $this->sla_breached,
            ],
            'first_response_at' => $this->first_response_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'reopen_count' => $this->reopen_count,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
