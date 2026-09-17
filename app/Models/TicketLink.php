<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A relationship between two tickets. Stored once, in one direction; the
 * opposite side is derived when rendering, which is why `inverseType()` exists.
 *
 * @property string $type
 */
class TicketLink extends Model
{
    public const TYPES = ['relates', 'duplicates', 'blocks', 'causes', 'parent'];

    protected $fillable = ['ticket_id', 'related_ticket_id', 'type', 'created_by'];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function relatedTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'related_ticket_id');
    }

    /**
     * How the relationship reads from the other ticket's point of view.
     */
    public static function inverseType(string $type): string
    {
        return match ($type) {
            'duplicates' => 'duplicated_by',
            'blocks' => 'blocked_by',
            'causes' => 'caused_by',
            'parent' => 'child',
            default => 'relates',
        };
    }
}
