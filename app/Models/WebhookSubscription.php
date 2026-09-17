<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\WebhookSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody else's endpoint, and the events it asked for.
 *
 * This is configuration, not history: editing it never rewrites what was
 * already delivered. The failure counters live here rather than being
 * aggregated out of the delivery log, so the admin list is one query instead
 * of one per row.
 *
 * @property string $name
 * @property string $url
 * @property array<int, string> $events
 * @property string|null $secret
 * @property bool $is_active
 */
class WebhookSubscription extends Model
{
    /** @use HasFactory<WebhookSubscriptionFactory> */
    use Auditable, HasFactory;

    /**
     * Every event an endpoint may subscribe to.
     *
     * Kept as a closed list in code for the same reason permissions are: a
     * subscription to `ticket.créated` should fail when it is saved, not be
     * silently quiet forever.
     */
    public const EVENTS = [
        'ticket.created',
        'ticket.updated',
        'ticket.assigned',
        'ticket.transitioned',
        'ticket.commented',
        'sla.breached',
        'approval.completed',
    ];

    /**
     * How many consecutive failures before the subscription switches itself
     * off. An endpoint that has been gone all week is not coming back inside
     * this job's backoff, and every attempt against it is a queue slot another
     * subscription could have used.
     */
    public const FAILURE_LIMIT = 20;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'description', 'url', 'events', 'secret', 'is_active', 'created_by',
    ];

    /**
     * The secret is a shared one — we reproduce the signature on every
     * delivery, so it cannot be hashed — which makes it exactly the thing that
     * must not ride along in a payload sent to the browser.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_delivered_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function listensFor(string $event): bool
    {
        return in_array($event, $this->events, true);
    }

    /**
     * @param  Builder<WebhookSubscription>  $query
     */
    public function scopeListeningFor(Builder $query, string $event): void
    {
        $query->where('is_active', true)->whereJsonContains('events', $event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->url,
            'events' => $this->events,
            'is_active' => $this->is_active,
            // Whether it is signed, never the secret itself.
            'signed' => filled($this->secret),
            'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),
            'last_failed_at' => $this->last_failed_at?->toIso8601String(),
            'consecutive_failures' => $this->consecutive_failures,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
