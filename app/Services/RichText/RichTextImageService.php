<?php

declare(strict_types=1);

namespace App\Services\RichText;

use App\Models\RichTextImage;
use App\Models\User;
use App\Services\Tickets\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores an image somebody pasted into an editor.
 *
 * Mirrors {@see AttachmentService}, and for the same
 * reasons: the stored path is generated rather than derived from the filename,
 * the extension is re-read from the file's own bytes, and nothing lands on a
 * public disk. What is different is only where it goes and who may read it
 * back.
 */
class RichTextImageService
{
    public function store(UploadedFile $file, ?User $uploader = null, bool $internal = false): RichTextImage
    {
        $disk = (string) config('ticktz.rich_text.images.disk', config('ticktz.attachments.disk', 'local'));
        $uuid = RichTextImage::newUuid();
        $extension = $this->safeExtension($file);

        // Foldered by month so a busy desk does not end up with one directory
        // holding a hundred thousand files, which some filesystems handle
        // badly and every `ls` handles badly.
        $path = $file->storeAs(
            'rich-text/'.now()->format('Y/m'),
            $uuid.($extension ? ".{$extension}" : ''),
            ['disk' => $disk],
        );

        return RichTextImage::query()->create([
            'uuid' => $uuid,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->safeName($file->getClientOriginalName()),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
            'checksum' => hash_file('sha256', Storage::disk($disk)->path($path)) ?: null,
            'user_id' => $uploader?->getKey(),
            'is_internal' => $internal,
        ]);
    }

    /**
     * What an upload has to pass.
     *
     * `image` rather than `mimes:png,jpg,…`: it checks that the file really is
     * an image by reading it, where a MIME list checks a claim. Both are here
     * because the list is what an operator configures and the check is what
     * makes the list true.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return [
            'image',
            'mimes:'.implode(',', (array) config('ticktz.rich_text.images.extensions')),
            'max:'.(int) config('ticktz.rich_text.images.max_kilobytes'),
        ];
    }

    private function safeExtension(UploadedFile $file): string
    {
        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : '';
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;

        return mb_substr(trim($name) ?: 'image', 0, 255);
    }
}
