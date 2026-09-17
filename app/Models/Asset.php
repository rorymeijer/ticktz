<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasRichText;
use App\Services\RichText\RichTextAttribute;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing: a laptop, a server, a phone, a licence.
 *
 * `asset_tag` is the human key — the sticker on the box. It is what somebody
 * reads off the underside of a laptop, what they type into a search box, and
 * what a CSV import matches on, which is why it is unique and indexed rather
 * than merely present.
 *
 * Relations to other assets are stored once and read in both directions; see
 * {@see relatedAssets()}.
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use Auditable, HasCustomFields, HasFactory, HasRichText, SoftDeletes;

    public const IN_STOCK = 'in_stock';

    public const IN_USE = 'in_use';

    public const IN_REPAIR = 'in_repair';

    public const RETIRED = 'retired';

    public const DISPOSED = 'disposed';

    /** @var array<int, string> */
    public const STATUSES = [self::IN_STOCK, self::IN_USE, self::IN_REPAIR, self::RETIRED, self::DISPOSED];

    /**
     * Statuses that mean the thing is still out there somewhere. A retired
     * asset stays in the database — it is in the history of every ticket it
     * was ever attached to — but it is not offered when linking.
     *
     * @var array<int, string>
     */
    public const LIVE_STATUSES = [self::IN_STOCK, self::IN_USE, self::IN_REPAIR];

    protected $fillable = [
        'asset_type_id', 'asset_tag', 'name', 'serial_number', 'manufacturer', 'model',
        'status', 'location', 'assigned_to', 'organization_id', 'team_id',
        'purchased_at', 'warranty_ends_at', 'purchase_cost', 'currency', 'notes', 'created_by',
    ];

    /**
     * @return array<string, RichTextAttribute>
     */
    protected static function richTextAttributes(): array
    {
        return [
            'notes' => new RichTextAttribute(text: 'notes_text'),
        ];
    }

    protected function casts(): array
    {
        return [
            // Dates, not datetimes: nobody knows what time a laptop was bought,
            // and a time component turns "same day" comparisons into a lottery.
            'purchased_at' => 'immutable_date:Y-m-d',
            'warranty_ends_at' => 'immutable_date:Y-m-d',
            'purchase_cost' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'asset_tag';
    }

    /** @return BelongsTo<AssetType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'asset_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsToMany<Ticket, $this> */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'asset_ticket')
            ->withPivot('linked_by')
            ->withTimestamps();
    }

    /**
     * Relations this asset is the subject of.
     *
     * Named `links` rather than the obvious `relations`: Eloquent keeps its
     * own loaded-relationship cache in a protected `$relations` array, and a
     * relationship method of that name is shadowed by it — `$asset->relations`
     * silently hands back that array instead of the query. It fails as "call
     * to a member function filter() on array", a long way from the cause.
     *
     * @return HasMany<AssetRelation, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(AssetRelation::class);
    }

    /** Relations pointing at this asset. @return HasMany<AssetRelation, $this> */
    public function inverseLinks(): HasMany
    {
        return $this->hasMany(AssetRelation::class, 'related_asset_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function warrantyHasExpired(): bool
    {
        return $this->warranty_ends_at !== null && $this->warranty_ends_at->isPast();
    }

    // -----------------------------------------------------------------
    // Search
    // -----------------------------------------------------------------

    /**
     * Free-text search over the things somebody actually remembers about a
     * machine: its tag, its serial, what it is called, where it is.
     *
     * The tag and serial are matched with LIKE on both drivers rather than
     * through the index. A FULLTEXT index tokenises on word boundaries, so
     * "LAP-0042" is stored as two words and a search for "0042" — which is
     * what somebody types when they can only read half the sticker — finds
     * nothing.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($term): void {
            $like = self::like($term);

            $scoped->where('assets.asset_tag', 'like', $like)
                ->orWhere('assets.serial_number', 'like', $like);

            // The FULLTEXT index ranks on MySQL; the LIKE pass below runs
            // beside it rather than instead of it. See the note on
            // TicketFilter::applySearch() — short words, partial words and
            // rows in an uncommitted transaction are all invisible to the
            // index, and a search that cannot find "Latitude" inside
            // "Dell Latitude 5440" is not a search.
            if ($scoped->getConnection()->getDriverName() === 'mysql') {
                $scoped->orWhereFullText(
                    ['name', 'serial_number', 'model', 'manufacturer', 'location', 'notes_text'],
                    $term,
                );
            }

            // notes_text, not notes: the notes column holds markup now, and a
            // LIKE over it would match tag names.
            foreach (['name', 'model', 'manufacturer', 'location', 'notes_text'] as $column) {
                $scoped->orWhere("assets.{$column}", 'like', $like);
            }
        });
    }

    /**
     * What a requester may see: their own equipment, and — when their
     * organisation shares tickets — their colleagues'.
     *
     * An asset with no owner and no organisation is the desk's own stock and
     * is never on the portal.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopeVisibleToRequester(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $scoped) use ($user): void {
            $scoped->where('assets.assigned_to', $user->getKey());

            if ($user->organization_id && $user->organization?->shared_ticket_visibility) {
                $scoped->orWhere('assets.organization_id', $user->organization_id);
            }
        });
    }

    private static function like(string $term): string
    {
        return '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
    }

    // -----------------------------------------------------------------
    // Payloads
    // -----------------------------------------------------------------

    /**
     * A search result or a row in a list. Deliberately without the custom
     * attributes — a list of two hundred assets should not be two hundred
     * extra queries.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'asset_tag' => $this->asset_tag,
            'name' => $this->name,
            'status' => $this->status,
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'location' => $this->location,
            'type' => $this->relationLoaded('type') && $this->type
                ? ['id' => $this->type->id, 'name' => $this->type->translatedName(), 'color' => $this->type->color]
                : null,
            'assignee' => $this->relationLoaded('assignee') ? $this->assignee?->toSummaryArray() : null,
            'organization' => $this->relationLoaded('organization') ? $this->organization?->only('id', 'name') : null,
            'warranty_ends_at' => $this->warranty_ends_at?->toDateString(),
            'warranty_expired' => $this->warrantyHasExpired(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The asset's own page: everything about it, plus what it is connected to.
     *
     * @return array<string, mixed>
     */
    public function toDetailArray(): array
    {
        return $this->toSummaryArray() + [
            'team' => $this->relationLoaded('team') ? $this->team?->only('id', 'name') : null,
            'purchased_at' => $this->purchased_at?->toDateString(),
            'purchase_cost' => $this->purchase_cost,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'fields' => $this->customFieldSummary(),
            'relations' => $this->relatedAssets(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * What a requester may see about their own equipment.
     *
     * Deliberately not `toSummaryArray()` minus a few keys: a payload built by
     * removing things is one that leaks the next field somebody adds. This is
     * built by naming what may be shown — no purchase cost, no supplier, no
     * internal notes, and whose it is only when it is somebody else's.
     *
     * @return array<string, mixed>
     */
    public function toPortalArray(User $reader): array
    {
        return [
            'id' => $this->id,
            'asset_tag' => $this->asset_tag,
            'name' => $this->name,
            'status' => $this->status,
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'location' => $this->location,
            'type' => $this->relationLoaded('type') && $this->type
                ? ['name' => $this->type->translatedName(), 'color' => $this->type->color]
                : null,
            'warranty_ends_at' => $this->warranty_ends_at?->toDateString(),
            'warranty_expired' => $this->warrantyHasExpired(),
            'is_mine' => (int) $this->assigned_to === (int) $reader->getKey(),
            'assignee' => (int) $this->assigned_to === (int) $reader->getKey()
                ? null
                : ($this->relationLoaded('assignee') ? $this->assignee?->only('name') : null),
        ];
    }

    /**
     * Everything this asset is connected to, in both directions.
     *
     * A relation is stored once — "the dock is connected_to the laptop" — so
     * reading it from the laptop's side means finding the row and inverting
     * the label. Storing a row per direction would let the two halves of one
     * relationship disagree with each other, which in a CMDB is the bug that
     * makes people stop trusting the whole thing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function relatedAssets(): array
    {
        /** @var Collection<int, AssetRelation> $outgoing */
        $outgoing = $this->relationLoaded('links')
            ? $this->links
            : $this->links()->with('relatedAsset.type')->get();

        /** @var Collection<int, AssetRelation> $incoming */
        $incoming = $this->relationLoaded('inverseLinks')
            ? $this->inverseLinks
            : $this->inverseLinks()->with('asset.type')->get();

        $rows = $outgoing
            ->filter(fn (AssetRelation $relation) => $relation->relatedAsset !== null)
            ->map(fn (AssetRelation $relation) => [
                'id' => $relation->id,
                'type' => $relation->type,
                'direction' => 'out',
                'note' => $relation->note,
                'asset' => $relation->relatedAsset->toSummaryArray(),
            ])
            ->concat(
                $incoming
                    ->filter(fn (AssetRelation $relation) => $relation->asset !== null)
                    ->map(fn (AssetRelation $relation) => [
                        'id' => $relation->id,
                        // Read from this side, "installed on" becomes "hosts".
                        'type' => AssetRelation::inverseType($relation->type),
                        'direction' => 'in',
                        'note' => $relation->note,
                        'asset' => $relation->asset->toSummaryArray(),
                    ]),
            );

        return $rows->values()->all();
    }
}
