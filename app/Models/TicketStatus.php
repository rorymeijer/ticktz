<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslatableName;
use Database\Factories\TicketStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A status a ticket can be in.
 *
 * `category` is what the engine reasons about — an SLA clock, the "open
 * tickets" count and the portal all ask about the category, never the name, so
 * an organisation can call its statuses whatever it likes.
 *
 * @property string $name
 * @property string $slug
 * @property string $category
 * @property bool $pauses_sla
 */
class TicketStatus extends Model
{
    /** @use HasFactory<TicketStatusFactory> */
    use Auditable, HasFactory, HasTranslatableName;

    public const CATEGORY_NEW = 'new';

    public const CATEGORY_OPEN = 'open';

    public const CATEGORY_PENDING = 'pending';

    public const CATEGORY_RESOLVED = 'resolved';

    public const CATEGORY_CLOSED = 'closed';

    /**
     * Categories that mean "still on somebody's plate".
     */
    public const OPEN_CATEGORIES = [self::CATEGORY_NEW, self::CATEGORY_OPEN, self::CATEGORY_PENDING];

    protected $fillable = [
        'name', 'name_translations', 'slug', 'category', 'color', 'description',
        'pauses_sla', 'is_public', 'is_system', 'position',
    ];

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'pauses_sla' => 'boolean',
            'is_public' => 'boolean',
            'is_system' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Workflow, $this>
     */
    public function workflows(): BelongsToMany
    {
        return $this->belongsToMany(Workflow::class, 'workflow_statuses')
            ->withPivot(['is_initial', 'position']);
    }

    public function isOpen(): bool
    {
        return in_array($this->category, self::OPEN_CATEGORIES, true);
    }

    public function isResolved(): bool
    {
        return in_array($this->category, [self::CATEGORY_RESOLVED, self::CATEGORY_CLOSED], true);
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
            'category' => $this->category,
            'color' => $this->color,
            'is_open' => $this->isOpen(),
        ];
    }
}
