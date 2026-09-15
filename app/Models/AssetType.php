<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\AssetTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of thing the desk keeps track of: a laptop, a server, a licence.
 *
 * The type carries the extra attributes that kind of thing has — a laptop has
 * a screen size, a licence has a renewal date — by declaring which
 * {@see CustomField}s it wants, exactly as a {@see RequestType} declares its
 * form. That is deliberate reuse: one field editor, one validation path, one
 * place this can go wrong.
 */
class AssetType extends Model
{
    /** @use HasFactory<AssetTypeFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'name', 'name_translations', 'slug', 'description', 'icon', 'color',
        'tag_prefix', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** @return BelongsToMany<CustomField, $this> */
    public function fields(): BelongsToMany
    {
        return $this->belongsToMany(CustomField::class, 'asset_type_fields')
            ->withPivot(['is_required', 'help_text', 'position'])
            ->orderBy('asset_type_fields.position');
    }

    public function translatedName(?string $locale = null): string
    {
        return $this->name_translations[$locale ?? app()->getLocale()] ?? $this->name;
    }

    /**
     * The form definition an asset of this type is edited with.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formFields(): array
    {
        return $this->fields
            ->filter(fn (CustomField $field) => $field->is_active)
            ->map(fn (CustomField $field) => $field->toFormArray(
                $field->pivot->is_required === null ? null : (bool) $field->pivot->is_required,
                $field->pivot->help_text,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->translatedName(),
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'tag_prefix' => $this->tag_prefix,
            'is_active' => $this->is_active,
            'asset_count' => $this->assets_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return $this->toSummaryArray() + [
            'name_translations' => $this->name_translations ?? [],
            'position' => $this->position,
            'field_ids' => $this->relationLoaded('fields') ? $this->fields->pluck('id')->all() : [],
        ];
    }
}
