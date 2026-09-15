<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores uploads outside the public root and records what was stored.
 *
 * Nothing here trusts the client-supplied filename or MIME type: the stored
 * path is generated, and the extension is re-derived from the file itself
 * before the allow-list is checked.
 */
class AttachmentService
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, Attachment>
     */
    public function storeMany(Ticket $ticket, array $files, ?User $uploader = null, ?Comment $comment = null): array
    {
        return array_map(
            fn (UploadedFile $file) => $this->store($ticket, $file, $uploader, $comment),
            array_values($files),
        );
    }

    public function store(Ticket $ticket, UploadedFile $file, ?User $uploader = null, ?Comment $comment = null): Attachment
    {
        $disk = (string) config('ticktz.attachments.disk', 'local');
        $extension = $this->safeExtension($file);

        // Path is generated, never derived from user input: a filename like
        // "../../.env" must not be able to influence where the bytes land.
        $directory = sprintf('attachments/%s/%s', $ticket->getKey(), now()->format('Y/m'));
        $name = Str::uuid()->toString().($extension ? ".{$extension}" : '');

        $path = $file->storeAs($directory, $name, ['disk' => $disk]);

        return Attachment::query()->create([
            'ticket_id' => $ticket->getKey(),
            'comment_id' => $comment?->getKey(),
            'user_id' => $uploader?->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->safeName($file->getClientOriginalName()),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
            'checksum' => hash_file('sha256', Storage::disk($disk)->path($path)) ?: null,
            'is_internal' => $comment?->is_internal ?? false,
        ]);
    }

    /**
     * The validation rules an upload has to pass. Kept here so the portal, the
     * agent console and the API all enforce the same limits.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return [
            'file',
            'max:'.(int) config('ticktz.attachments.max_kilobytes'),
            'mimes:'.implode(',', config('ticktz.attachments.allowed_extensions')),
        ];
    }

    private function safeExtension(UploadedFile $file): string
    {
        // guessExtension() reads the file's own magic bytes; the client's
        // extension is only a fallback for types PHP cannot sniff.
        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : '';
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;

        return mb_substr(trim($name) ?: 'attachment', 0, 255);
    }
}
