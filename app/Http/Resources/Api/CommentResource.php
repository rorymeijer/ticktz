<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a ticket's conversation.
 *
 * Internal notes are never filtered out *here* — the controller decides which
 * comments a caller may see and only passes those in. Doing it in the resource
 * would mean every new endpoint that renders a comment has to remember to pass
 * the right flag, and the one that forgets leaks the agent's side of the
 * conversation to the customer.
 *
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            // Both shapes, always.
            //
            // `body` is the rich text as stored — an allowlisted subset of
            // HTML, safe to render. `body_text` is the same content flattened,
            // which is what a script grepping for a word, a Slack relay, or
            // anything with no room for markup actually wants. A client
            // written against 1.0.0 reading `body` gets markup where it used
            // to get a sentence, which is the one breaking change here and the
            // reason `body_text` sits next to it rather than replacing it.
            'body' => $this->body,
            'body_text' => $this->body_text,
            'is_internal' => $this->is_internal,
            'source' => $this->source,
            'author' => $this->whenLoaded('author', fn () => $this->author
                ? UserStubResource::make($this->author)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
