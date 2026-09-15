<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event, on its way to one endpoint, and what came back.
 *
 * A row is created before the request is made rather than after it, so a
 * delivery that never returns still leaves a trace. It is the log that turns
 * "their integration says it never got the ticket" into a query.
 *
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status
 */
class WebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    /**
     * A misconfigured endpoint answering with a 200KB HTML error page must not
     * be able to fill the disk one delivery at a time — and the first two
     * kilobytes of a response say everything the person debugging needs.
     */
    public const RESPONSE_LIMIT = 2000;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'webhook_subscription_id', 'event', 'payload', 'status', 'attempts',
        'response_status', 'response_body', 'error', 'duration_ms', 'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    /**
     * @param  Builder<WebhookDelivery>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', self::STATUS_FAILED);
    }

    public static function truncateBody(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        return mb_substr($body, 0, self::RESPONSE_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->getKey(),
            'event' => $this->event,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'subscription' => $this->relationLoaded('subscription') && $this->subscription
                ? ['id' => $this->subscription->getKey(), 'name' => $this->subscription->name]
                : null,
        ];
    }
}
