<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslatableName;
use Database\Factories\KbCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shelf in the knowledge base.
 *
 * One level of nesting is allowed and no more: a help centre with a tree four
 * deep is a filing cabinet, and nobody opens a filing cabinet.
 *
 * @property array<string, string>|null $name_translations
 */
class KbCategory extends Model
{
    /** @use HasFactory<KbCategoryFactory> */
    use Auditable, HasFactory, HasTranslatableName;

    public const PUBLIC = 'public';

    public const INTERNAL = 'internal';

    protected $fillable = [
        'name', 'name_translations', 'slug', 'description', 'description_translations',
        'icon', 'parent_id', 'visibility', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'description_translations' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<KbCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'parent_id');
    }

    /** @return HasMany<KbCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(KbCategory::class, 'parent_id')->orderBy('position');
    }

    /** @return HasMany<KbArticle, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class)->orderBy('position');
    }

    public function isInternal(): bool
    {
        return $this->visibility === self::INTERNAL;
    }

    /**
     * Categories a requester may see: active, public, and nothing more.
     *
     * @param  Builder<KbCategory>  $query
     * @return Builder<KbCategory>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('visibility', self::PUBLIC)
            ->orderBy('position')
            ->orderBy('name');
    }

    public function translatedDescription(?string $locale = null): ?string
    {
        return $this->description_translations[$locale ?? app()->getLocale()] ?? $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(?string $locale = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->translatedName($locale),
            'slug' => $this->slug,
            'description' => $this->translatedDescription($locale),
            'icon' => $this->icon,
            'visibility' => $this->visibility,
            'parent_id' => $this->parent_id,
            'article_count' => $this->articles_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return $this->toSummaryArray() + [
            'name_translations' => $this->name_translations ?? [],
            'description_translations' => $this->description_translations ?? [],
            'is_active' => $this->is_active,
            'position' => $this->position,
        ];
    }
}
