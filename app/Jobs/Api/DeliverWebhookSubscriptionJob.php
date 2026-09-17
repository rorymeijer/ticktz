<?php

declare(strict_types=1);

namespace App\Jobs\Api;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One delivery, with the retries and the log entry.
 *
 * The job carries a **delivery id**, not the models. A serialised model is a
 * snapshot of a row that may have been edited or deleted by the time the queue
 * gets to it, and re-reading is one query against a primary key.
 *
 * Signing covers the exact bytes sent, not the fields, so the receiver's check
 * is one line and cannot drift from what was actually on the wire:
 *
 *     hash_hmac('sha256', $rawBody, $secret) === substr($header, 7)
 *
 * The timestamp header is signed along with nothing — it is there so a
 * receiver that wants replay protection can reject old deliveries — but the
 * event id in the body is what makes a receiver idempotent, and every retry of
 * one delivery reuses it.
 */
class DeliverWebhookSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * A minute, then five, then twenty: long enough for a deploy to finish,
     * short enough that a recovered endpoint still gets the event while it is
     * worth having.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 1200];

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue(config('ticktz.queues.webhooks'));
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('subscription')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            // Deleted, or already through. Either way there is nothing to send
            // and re-sending would be worse than doing nothing.
            return;
        }

        $subscription = $delivery->subscription;

        if ($subscription === null || ! $subscription->is_active) {
            $this->markFailed($delivery, __('api.webhooks.inactive'));

            return;
        }

        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Ticktz/'.config('ticktz.version'),
            'X-Ticktz-Event' => $delivery->event,
            'X-Ticktz-Delivery' => (string) $delivery->getKey(),
            'X-Ticktz-Timestamp' => (string) now()->getTimestamp(),
        ];

        if (filled($subscription->secret)) {
            $headers['X-Ticktz-Signature'] = 'sha256='.hash_hmac('sha256', $body, $subscription->secret);
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(15)
                ->connectTimeout(10)
                ->withBody($body, 'application/json')
                ->post($subscription->url);
        } catch (Throwable $exception) {
            // A refused connection or a DNS failure never becomes a response,
            // so it is recorded here before the retry decision.
            $this->markFailed($delivery, $exception->getMessage(), $this->elapsed($startedAt));
            $this->penalise($subscription);

            $this->surrender($exception);

            return;
        }

        $durationMs = $this->elapsed($startedAt);

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_DELIVERED,
                'response_status' => $response->status(),
                'response_body' => WebhookDelivery::truncateBody($response->body()),
                'error' => null,
                'duration_ms' => $durationMs,
                'delivered_at' => now(),
            ])->save();

            $subscription->forceFill([
                'last_delivered_at' => now(),
                'consecutive_failures' => 0,
            ])->save();

            return;
        }

        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_FAILED,
            'response_status' => $response->status(),
            'response_body' => WebhookDelivery::truncateBody($response->body()),
            'error' => __('api.webhooks.http_error', ['status' => $response->status()]),
            'duration_ms' => $durationMs,
        ])->save();

        $this->penalise($subscription);

        Log::warning('Webhook {subscription} to {url} returned {status}', [
            'subscription' => $subscription->getKey(),
            'url' => $subscription->url,
            'status' => $response->status(),
        ]);

        // Hands the retry to the queue. A 4xx is retried too: an endpoint
        // answering 401 during a credential rotation is the common case, and
        // three attempts over twenty minutes costs nothing.
        $this->surrender($response->toException());
    }

    /**
     * Fail the job — but only where failing means "retry later".
     *
     * On a real queue this throw is the retry signal, which is the whole point
     * of the backoff. On `sync` there is no queue to catch it: the exception
     * would travel back up through the listener into whatever raised the
     * event, so an unreachable endpoint would stop an agent from creating a
     * ticket. A ticket that cannot be filed because somebody else's server is
     * down is a far worse failure than a webhook nobody received — and the
     * delivery row already records what happened either way.
     *
     * `sync` is the demo instance, the seeder and the developer without a
     * worker running. It is exactly where this must not cascade.
     */
    private function surrender(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        if (config('queue.default') !== 'sync') {
            throw $exception;
        }

        report($exception);
    }

    /**
     * The queue calls this once the last attempt has failed. The delivery row
     * is already marked failed by then — this exists so the failure is in the
     * log as well, where an operator watching the worker will see it.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Webhook delivery {id} gave up: {message}', [
            'id' => $this->deliveryId,
            'message' => $exception?->getMessage() ?? 'unknown',
        ]);
    }

    private function markFailed(WebhookDelivery $delivery, string $error, ?int $durationMs = null): void
    {
        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => mb_substr($error, 0, 250),
            'duration_ms' => $durationMs,
        ])->save();
    }

    /**
     * Count the failure, and switch the subscription off once an endpoint has
     * been gone long enough that it is not coming back inside a backoff. It
     * stays in the list, marked inactive, so somebody can see what happened
     * and turn it on again — deleting it would lose the reason.
     */
    private function penalise(WebhookSubscription $subscription): void
    {
        $failures = $subscription->consecutive_failures + 1;

        $subscription->forceFill([
            'consecutive_failures' => $failures,
            'last_failed_at' => now(),
            'is_active' => $failures < WebhookSubscription::FAILURE_LIMIT,
        ])->save();
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
