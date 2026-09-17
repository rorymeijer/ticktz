<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The assets a ticket is about.
 *
 * A trait rather than a method on `Ticket` because Phase 11's API needs the
 * same relation from the other side, and because keeping it here makes it
 * obvious that the pivot carries who linked it — an asset attached to a ticket
 * is a claim somebody made, and the trail should say who.
 */
trait HasAssets
{
    /** @return BelongsToMany<Asset, $this> */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_ticket')
            ->withPivot('linked_by')
            ->withTimestamps();
    }
}
