<?php

declare(strict_types=1);

namespace App\Services\RichText;

use App\Models\Concerns\HasRichText;
use App\Models\RichTextImage;
use Illuminate\Database\Eloquent\Model;

/**
 * Binds the images a piece of rich text references to the record that holds it.
 *
 * An image is uploaded while somebody is still typing, so at that moment it
 * belongs to nothing and only its uploader may see it. This is what gives it an
 * owner, and with it the audience the owner has: everyone who may read the
 * ticket sees the screenshot in it, and nobody else does.
 *
 * Run from {@see HasRichText} on every save of a rich
 * text column, which means it runs on edits as well as on creation — an image
 * added in a later edit is bound then, and one removed keeps its row. Removing
 * it would be the wrong call twice over: the same image may be referenced from
 * a second field, and an edit that deletes somebody's screenshot as a side
 * effect is not an edit anybody asked for. The prune command sweeps what is
 * genuinely unreferenced.
 */
class RichTextImageBinder
{
    /**
     * @param  array<int, string|null>  $values  the rich text being saved
     */
    public function bind(Model $owner, array $values, ?bool $internal = null): void
    {
        $uuids = [];

        foreach ($values as $value) {
            $uuids = [...$uuids, ...RichTextImage::referencedIn($value)];
        }

        if ($uuids === []) {
            return;
        }

        $attributes = [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ];

        if ($internal !== null) {
            $attributes['is_internal'] = $internal;
        }

        RichTextImage::query()
            ->whereIn('uuid', array_unique($uuids))
            // Only ever claims what is unclaimed, or what this same record
            // already owns. Without this, quoting somebody else's screenshot
            // into a new ticket would move the image out of the ticket it
            // belongs to and take it away from everyone reading that one.
            ->where(function ($query) use ($owner): void {
                $query->whereNull('owner_type')
                    ->orWhere(function ($same) use ($owner): void {
                        $same->where('owner_type', $owner->getMorphClass())
                            ->where('owner_id', $owner->getKey());
                    });
            })
            ->update($attributes);
    }
}
