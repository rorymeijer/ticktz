<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PortalCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grouping of request types on the portal, e.g. "Workplace", "Access".
 *
 * @property array<string, string>|null $name_translations
 */
class PortalCategory extends Model
{
    /** @use HasFactory<PortalCategoryFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'name', 'name_translations', 'slug', 'description', 'description_translations',
        'icon', 'color', 'is_active', 'position',
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

    /**
     * @return HasMany<RequestType, $this>
     */
    public function requestTypes(): HasMany
    {
        return $this->hasMany(RequestType::class)->orderBy('position');
    }

    public function translatedName(?string $locale = null): string
    {
        return $this->name_translations[$locale ?? app()->getLocale()] ?? $this->name;
    }

    public function translatedDescription(?string $locale = null): ?string
    {
        return $this->description_translations[$locale ?? app()->getLocale()] ?? $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPortalArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->translatedName(),
            'slug' => $this->slug,
            'description' => $this->translatedDescription(),
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }
}
