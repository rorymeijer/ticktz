<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row of the append-only audit trail. Written exclusively through
 * App\Services\AuditLogger — nothing in the application updates or deletes
 * these rows.
 *
 * @property string $event
 * @property string $auditable_type
 * @property int $auditable_id
 */
class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_type', 'user_id', 'actor_label', 'auditable_type', 'auditable_id',
        'event', 'description', 'old_values', 'new_values',
        'ip_address', 'user_agent', 'context',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<AuditLogEntry>  $query
     */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('auditable_type', $subject->getMorphClass())
            ->where('auditable_id', $subject->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'description' => $this->description,
            'actor_type' => $this->actor_type,
            'actor' => $this->relationLoaded('user') && $this->user
                ? $this->user->toSummaryArray()
                : ['name' => $this->actor_label ?? __('common.labels.system')],
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
