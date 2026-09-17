<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\AssetType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every write an asset can undergo.
 *
 * Nothing writes to `assets` or `asset_relations` outside this class, which is
 * what makes three things true everywhere rather than at each call site: a tag
 * is unique and generated when nobody supplied one, a relationship is stored
 * once in one direction, and every change is audited against the asset so the
 * question "who moved this laptop to the third floor" has an answer.
 */
class AssetService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Asset
    {
        $type = $attributes['type'] ?? AssetType::query()->findOrFail($attributes['asset_type_id']);

        $asset = DB::transaction(function () use ($attributes, $type, $actor): Asset {
            $asset = Asset::query()->create($this->columns($attributes) + [
                'asset_type_id' => $type->getKey(),
                'asset_tag' => $this->resolveTag($attributes['asset_tag'] ?? null, $type),
                'created_by' => $actor?->getKey(),
            ]);

            if (! empty($attributes['fields'])) {
                $asset->setCustomFields($attributes['fields']);
            }

            return $asset;
        });

        $this->auditAs($actor)->created($asset, "Created asset {$asset->asset_tag}: {$asset->name}");

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Asset $asset, array $attributes, ?User $actor = null): Asset
    {
        $original = $asset->getOriginal();

        $asset->fill($this->columns($attributes));

        // A tag is the sticker on the box, so it can be corrected — but never
        // onto one another asset already carries.
        if (isset($attributes['asset_tag']) && $attributes['asset_tag'] !== $asset->asset_tag) {
            $asset->asset_tag = $this->uniqueTag((string) $attributes['asset_tag'], $asset->getKey());
        }

        $changed = $asset->isDirty();

        if ($changed) {
            $asset->save();
        }

        if (array_key_exists('fields', $attributes)) {
            $asset->setCustomFields($attributes['fields'] ?? []);
        }

        if ($changed) {
            $this->auditAs($actor)->log(
                $asset,
                'asset.updated',
                "Updated asset {$asset->asset_tag}",
                Arr::only($original, array_keys($asset->getChanges())),
                $asset->getChanges(),
            );
        }

        return $asset;
    }

    public function delete(Asset $asset, ?User $actor = null): void
    {
        // Soft-deleted. An asset a ticket was raised about is part of that
        // ticket's history, and erasing it leaves the link dangling.
        $this->auditAs($actor)->deleted($asset, "Removed asset {$asset->asset_tag}");
        $asset->delete();
    }

    /**
     * Hand an asset to somebody, or take it back.
     *
     * Assigning stamps `in_use` on an asset that was sitting in stock, because
     * an asset that is with somebody and still reads "in stock" is how a CMDB
     * starts lying. It never overrides `in_repair` or `retired` — those are
     * statements somebody made deliberately.
     */
    public function assign(Asset $asset, ?User $user, ?User $actor = null): Asset
    {
        $previous = $asset->assigned_to;

        $asset->assigned_to = $user?->getKey();

        if ($user && $asset->status === Asset::IN_STOCK) {
            $asset->status = Asset::IN_USE;
        }

        if (! $user && $asset->status === Asset::IN_USE) {
            $asset->status = Asset::IN_STOCK;
        }

        if (! $asset->isDirty()) {
            return $asset;
        }

        $asset->save();

        $this->auditAs($actor)->log(
            $asset,
            'asset.assigned',
            $user
                ? "Assigned {$asset->asset_tag} to {$user->name}"
                : "Returned {$asset->asset_tag} to stock",
            ['assigned_to' => $previous],
            ['assigned_to' => $asset->assigned_to],
        );

        return $asset;
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    /**
     * Connect two assets.
     *
     * Stored once, in one direction. The reverse is derived when rendering, so
     * the two halves of a relationship can never disagree.
     */
    public function relate(
        Asset $asset,
        Asset $related,
        string $type,
        ?string $note = null,
        ?User $actor = null,
    ): AssetRelation {
        if ($asset->is($related)) {
            throw new RuntimeException(__('assets.errors.self_relation'));
        }

        if (! in_array($type, AssetRelation::TYPES, true)) {
            throw new RuntimeException(__('assets.errors.unknown_relation'));
        }

        // The same pair in the other direction is the same relationship. Left
        // unchecked, a laptop ends up both installed on and hosting the same
        // dock, which is nonsense the UI would faithfully render.
        $mirror = AssetRelation::query()
            ->where('asset_id', $related->getKey())
            ->where('related_asset_id', $asset->getKey())
            ->where('type', $type)
            ->first();

        if ($mirror) {
            return $mirror;
        }

        $relation = AssetRelation::query()->firstOrCreate(
            [
                'asset_id' => $asset->getKey(),
                'related_asset_id' => $related->getKey(),
                'type' => $type,
            ],
            ['note' => $note, 'created_by' => $actor?->getKey()],
        );

        if ($relation->wasRecentlyCreated) {
            $this->auditAs($actor)->log(
                $asset,
                'asset.related',
                "Linked {$asset->asset_tag} to {$related->asset_tag}",
                null,
                ['related_asset_id' => $related->getKey(), 'type' => $type],
            );
        }

        return $relation;
    }

    public function unrelate(AssetRelation $relation, ?User $actor = null): void
    {
        $relation->loadMissing(['asset', 'relatedAsset']);

        $this->auditAs($actor)->log(
            $relation->asset ?? $relation->relatedAsset,
            'asset.unrelated',
            'Removed a link between two assets',
            ['related_asset_id' => $relation->related_asset_id, 'type' => $relation->type],
            null,
        );

        $relation->delete();
    }

    // -----------------------------------------------------------------
    // Tickets
    // -----------------------------------------------------------------

    /**
     * Attach an asset to the ticket it is about.
     *
     * Idempotent: attaching twice is a double click, not an error worth
     * showing anybody. The trail is written against the **ticket**, because
     * that is where somebody will be reading it.
     */
    public function linkToTicket(Asset $asset, Ticket $ticket, ?User $actor = null): void
    {
        if ($asset->tickets()->whereKey($ticket->getKey())->exists()) {
            return;
        }

        $asset->tickets()->attach($ticket->getKey(), ['linked_by' => $actor?->getKey()]);

        $this->auditAs($actor)->log(
            $ticket,
            'asset.linked',
            "Linked asset {$asset->asset_tag} to {$ticket->key}",
            null,
            ['asset_id' => $asset->getKey()],
        );
    }

    public function unlinkFromTicket(Asset $asset, Ticket $ticket, ?User $actor = null): void
    {
        $asset->tickets()->detach($ticket->getKey());

        $this->auditAs($actor)->log(
            $ticket,
            'asset.unlinked',
            "Unlinked asset {$asset->asset_tag} from {$ticket->key}",
            ['asset_id' => $asset->getKey()],
            null,
        );
    }

    // -----------------------------------------------------------------
    // Tags
    // -----------------------------------------------------------------

    /**
     * The tag an asset will carry: the one somebody typed, or the next in the
     * type's own sequence.
     */
    public function resolveTag(?string $given, AssetType $type): string
    {
        $given = mb_strtoupper(trim((string) $given));

        return $given !== '' ? $this->uniqueTag($given) : $this->nextTag($type);
    }

    /**
     * `LAP-0042` — the type's prefix and the next free number in it.
     *
     * Derived from the highest existing tag rather than from a sequence table.
     * Assets arrive by CSV import carrying tags somebody else allocated, so a
     * counter would drift out of step with reality on the first import; asking
     * the data is the only answer that stays true.
     */
    public function nextTag(AssetType $type): string
    {
        $prefix = mb_strtoupper(trim((string) ($type->tag_prefix ?: 'AST')));

        return DB::transaction(function () use ($prefix): string {
            $highest = Asset::query()
                ->withTrashed()
                ->where('asset_tag', 'like', $prefix.'-%')
                ->lockForUpdate()
                ->pluck('asset_tag')
                ->map(fn (string $tag) => (int) mb_substr($tag, mb_strlen($prefix) + 1))
                ->max() ?? 0;

            return sprintf('%s-%04d', $prefix, $highest + 1);
        });
    }

    /**
     * A tag nobody else is using. Two laptops called LAP-0042 is the one thing
     * a tag must never be.
     */
    private function uniqueTag(string $tag, ?int $ignore = null): string
    {
        $base = mb_strtoupper(trim($tag));
        $candidate = $base;
        $suffix = 2;

        while (Asset::query()
            ->withTrashed()
            ->where('asset_tag', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore))
            ->exists()
        ) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function columns(array $attributes): array
    {
        return Arr::only($attributes, [
            'asset_type_id', 'name', 'serial_number', 'manufacturer', 'model', 'status',
            'location', 'assigned_to', 'organization_id', 'team_id',
            'purchased_at', 'warranty_ends_at', 'purchase_cost', 'currency', 'notes',
        ]);
    }

    private function auditAs(?User $actor): AuditLogger
    {
        return $actor ? $this->audit->actingAs($actor) : $this->audit;
    }
}
