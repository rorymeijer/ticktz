<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslatableName;
use Database\Factories\PriorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property int $level 1 is the most urgent
 */
class Priority extends Model
{
    /** @use HasFactory<PriorityFactory> */
    use Auditable, HasFactory, HasTranslatableName;

    protected $fillable = ['name', 'name_translations', 'slug', 'level', 'color', 'is_default', 'is_public', 'position'];

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'level' => 'integer',
            'is_default' => 'boolean',
            'is_public' => 'boolean',
            'position' => 'integer',
        ];
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('level')->first();
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
            'level' => $this->level,
            'color' => $this->color,
        ];
    }
}
