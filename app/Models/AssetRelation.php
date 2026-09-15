<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A relationship between two assets, stored once in one direction.
 *
 * The opposite side is derived when rendering — see {@see inverseType()} — so
 * the two halves of "the dock is connected to the laptop" can never disagree
 * with each other. In a CMDB that disagreement is the bug that makes people
 * stop trusting the whole thing.
 *
 * @property string $type
 */
class AssetRelation extends Model
{
    /** @var array<int, string> */
    public const TYPES = ['connected_to', 'installed_on', 'part_of', 'depends_on', 'backs_up'];

    protected $fillable = ['asset_id', 'related_asset_id', 'type', 'note', 'created_by'];

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function relatedAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'related_asset_id');
    }

    /**
     * How the relationship reads from the other asset's point of view.
     *
     * `connected_to` is its own inverse — a cable has no direction — which is
     * why it is the default and why the list is shorter than it looks.
     */
    public static function inverseType(string $type): string
    {
        return match ($type) {
            'installed_on' => 'hosts',
            'part_of' => 'contains',
            'depends_on' => 'required_by',
            'backs_up' => 'backed_up_by',
            default => 'connected_to',
        };
    }
}
