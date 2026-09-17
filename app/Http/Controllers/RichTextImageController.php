<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RichTextImage;
use App\Services\RichText\RichTextImageBinder;
use App\Services\RichText\RichTextImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images pasted into a rich text field.
 *
 * Stored outside the web root and streamed through here, so the policy runs
 * before a single byte is sent — the same shape as attachments, and for the
 * same reason. Guessing a URL gets a 403, not a screenshot of somebody's
 * payslip.
 */
class RichTextImageController extends Controller
{
    /**
     * Take an upload and hand back the URL to put in the text.
     *
     * The image belongs to nobody until the text referencing it is saved; see
     * {@see RichTextImageBinder}. Until then only the
     * person who uploaded it may read it back.
     */
    public function store(Request $request, RichTextImageService $images): JsonResponse
    {
        $request->validate([
            'file' => ['required', ...RichTextImageService::rules()],
            // Set when pasting into an internal note, so the image is never
            // readable by the requester even for the moment between the paste
            // and the save.
            'internal' => ['sometimes', 'boolean'],
        ]);

        $image = $images->store(
            $request->file('file'),
            $request->user(),
            $request->boolean('internal'),
        );

        return response()->json([
            'url' => $image->url(),
            'name' => $image->original_name,
        ], 201);
    }

    public function show(Request $request, RichTextImage $image): StreamedResponse
    {
        $this->authorize('view', $image);

        $disk = Storage::disk($image->disk);

        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, $image->original_name, [
            'Content-Type' => $image->mime_type,
            // Inline: the whole point is that it renders in the text. Safe
            // because the upload was verified to be an image by reading it,
            // and nosniff stops a browser second-guessing that.
            'Content-Disposition' => 'inline; filename="'.addslashes($image->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            // A private image behind a policy must not sit in a shared cache.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }
}
