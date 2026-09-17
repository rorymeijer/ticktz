<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslatableName;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $name
 */
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use Auditable, HasFactory, HasTranslatableName;

    protected $fillable = ['name', 'name_translations', 'slug', 'color', 'description'];

    protected function casts(): array
    {
        return ['name_translations' => 'array'];
    }

    /**
     * @return BelongsToMany<Ticket, $this>
     */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_labels');
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
            'color' => $this->color,
        ];
    }
}
