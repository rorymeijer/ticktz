<?php

declare(strict_types=1);

namespace App\Jobs\Automation;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts a rule's payload to somebody else's server.
 *
 * On its own queue with a backoff, because the one thing that is certain about
 * an external endpoint is that it will be down at some point, and a ticket
 * being created is not a good reason to wait for it.
 *
 * The payload is signed when the rule carries a secret: an HMAC of the exact
 * body, in `X-Ticktz-Signature`, so the receiver can tell a real delivery from
 * anyone who has guessed the URL. Signing the body rather than the fields means
 * the check is one line on their side and cannot drift from what was sent.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * Roughly a minute, then five, then twenty — long enough for a deploy to
     * finish, short enough that a recovered endpoint gets the event while it
     * is still worth having.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 1200];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $url,
        public readonly array $payload,
        public readonly string $secret = '',
    ) {
        $this->onQueue(config('ticktz.queues.webhooks'));
    }

    public function handle(): void
    {
        $body = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Ticktz/'.config('ticktz.version'),
            'X-Ticktz-Event' => (string) ($this->payload['trigger'] ?? 'automation'),
        ];

        if ($this->secret !== '') {
            $headers['X-Ticktz-Signature'] = 'sha256='.hash_hmac('sha256', $body, $this->secret);
        }

        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->withBody($body, 'application/json')
            ->post($this->url);

        if ($response->failed()) {
            Log::warning('Webhook to {url} returned {status}', [
                'url' => $this->url,
                'status' => $response->status(),
            ]);

            // Throwing hands the retry to the queue rather than reimplementing
            // one here.
            $response->throw();
        }
    }
}
