<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a ticket or to one of its comments.
 *
 * Files are never served from the public disk: every download goes through a
 * controller that runs the ticket policy first.
 *
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'ticket_id', 'comment_id', 'user_id', 'disk', 'path',
        'original_name', 'mime_type', 'size', 'checksum', 'is_internal',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer', 'is_internal' => 'boolean'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function delete(): ?bool
    {
        Storage::disk($this->disk)->delete($this->path);

        return parent::delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'is_image' => $this->isImage(),
            'is_internal' => $this->is_internal,
            'url' => route('attachments.show', $this),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
