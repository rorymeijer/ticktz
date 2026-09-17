<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RichText\RichTextImageBinder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * An image pasted into a rich text field.
 *
 * Its life has two halves. It is uploaded while somebody is still typing, so
 * for a while it belongs to nothing and only its uploader may see it. When the
 * text that references it is saved, {@see RichTextImageBinder}
 * binds it to that record, and from then on it is readable by whoever may read
 * the record — which is the whole point of not putting these on a public disk.
 *
 * An image nobody ever claims is swept up later; see the prune command.
 */
class RichTextImage extends Model
{
    protected $fillable = [
        'uuid', 'disk', 'path', 'original_name', 'mime_type', 'size', 'checksum',
        'user_id', 'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_internal' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The path this image is served from, and the only `src` a sanitised field
     * is allowed to carry.
     *
     * Relative on purpose: an instance reachable at more than one hostname —
     * one inside the office, one out — must not bake whichever was in use when
     * the image was pasted into the stored text.
     */
    public function url(): string
    {
        return '/rich-text/images/'.$this->uuid;
    }

    /**
     * Pull the image identifiers out of a piece of rich text.
     *
     * Reads only our own URL shape, so an `<img>` the sanitiser has already
     * refused cannot make this return something. Matching on the UUID rather
     * than parsing the document keeps it cheap enough to run on every save.
     *
     * @return array<int, string>
     */
    public static function referencedIn(?string $html): array
    {
        if (blank($html)) {
            return [];
        }

        preg_match_all(
            '#/rich-text/images/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i',
            $html,
            $matches,
        );

        return array_values(array_unique(array_map(mb_strtolower(...), $matches[1] ?? [])));
    }

    /**
     * True when `$value` is a URL this application serves an image from.
     *
     * Relative only. `https://tracker.test/rich-text/images/<uuid>` has our
     * path and somebody else's host, and checking the path alone would have
     * waved it straight through — which is the whole tracking pixel, wearing
     * our own URL shape. A scheme or a host of any kind is a refusal, and that
     * includes the protocol-relative `//host/…` form.
     */
    public static function isOwnUrl(string $value): bool
    {
        $value = trim($value);
        $parts = parse_url($value);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return false;
        }

        $path = $parts['path'] ?? null;

        if (! is_string($path) || $path !== $value) {
            // Anything left over — a query string, a fragment — means this is
            // not the URL url() produces, and there is no reason for it to be
            // anything else.
            return false;
        }

        return preg_match(
            '#^/rich-text/images/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#i',
            $path,
        ) === 1;
    }

    public static function newUuid(): string
    {
        return Str::uuid()->toString();
    }
}
