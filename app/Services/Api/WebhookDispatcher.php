<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Jobs\Api\DeliverWebhookSubscriptionJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;

/**
 * Fans one event out to every endpoint that asked for it.
 *
 * The delivery row is written here, before the job is queued, rather than
 * inside the job. That ordering is the whole point: an event that was accepted
 * but whose queue never ran it still leaves a `pending` row, so "we never got
 * it" has an answer. Writing the row inside the job would mean the events that
 * vanish are exactly the ones with no trace.
 *
 * One job per subscription, not one job per event. A slow endpoint must not
 * hold up a fast one, and a retry against a dead host must not re-deliver to
 * the three that already succeeded.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload  an envelope from WebhookPayload
     */
    public function dispatch(string $event, array $payload): void
    {
        $subscriptions = WebhookSubscription::query()
            ->listeningFor($event)
            ->get();

        foreach ($subscriptions as $subscription) {
            $delivery = WebhookDelivery::query()->create([
                'webhook_subscription_id' => $subscription->getKey(),
                'event' => $event,
                'payload' => $payload,
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            DeliverWebhookSubscriptionJob::dispatch($delivery->getKey());
        }
    }
}
