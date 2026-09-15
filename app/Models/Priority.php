<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
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
    use Auditable, HasFactory;

    protected $fillable = ['name', 'slug', 'level', 'color', 'is_default', 'is_public', 'position'];

    protected function casts(): array
    {
        return [
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
            'name' => $this->name,
            'slug' => $this->slug,
            'level' => $this->level,
            'color' => $this->color,
        ];
    }
}
