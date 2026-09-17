<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A message on a ticket: a public reply the requester sees, or an internal
 * note only agents see.
 *
 * The `is_internal` flag is enforced in three places — the query scope below,
 * the policy, and the notification pipeline — because a leaked internal note
 * is the single worst bug a service desk can have.
 *
 * @property bool $is_internal
 * @property string $body
 * @property string $source
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = ['ticket_id', 'user_id', 'body', 'is_internal', 'source', 'email_message_id'];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Only the comments the given user is allowed to read.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeReadableBy(Builder $query, User $user): void
    {
        if (! $user->hasPermission('tickets.view')) {
            $query->where('is_internal', false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->id,
            'type' => 'comment',
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            'source' => $this->source,
            'author' => $this->author?->toSummaryArray() ?? ['name' => __('tickets.timeline.system')],
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => $this->relationLoaded('attachments')
                ? $this->attachments->map(fn (Attachment $attachment) => $attachment->toSummaryArray())->all()
                : [],
        ];
    }
}
