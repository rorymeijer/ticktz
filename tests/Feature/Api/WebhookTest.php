<?php

declare(strict_types=1);

use App\Jobs\Api\DeliverWebhookSubscriptionJob;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Tickets\TicketService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\withToken;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * The phase's second acceptance criterion: a configured webhook fires
 * reliably on "ticket created", and the attempt is logged.
 */
it('fires a configured webhook when a ticket is created, and logs the delivery', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $subscription = WebhookSubscription::factory()->create([
        'url' => 'https://example.com/hooks/tickets',
        'events' => ['ticket.created'],
        'secret' => 'shhh',
    ]);

    $agent = makeAgent();

    app(TicketService::class)->create([
        'subject' => 'Coffee machine is down',
        'requester' => $agent,
    ], $agent);

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->event)->toBe('ticket.created')
        ->and($delivery->webhook_subscription_id)->toBe($subscription->getKey())
        ->and($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->payload['data']['ticket']['subject'])->toBe('Coffee machine is down');

    // The counters on the subscription are what the admin list reads.
    expect($subscription->fresh()->last_delivered_at)->not->toBeNull()
        ->and($subscription->fresh()->consecutive_failures)->toBe(0);
});

it('signs the body so a receiver can verify it', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    WebhookSubscription::factory()->create([
        'events' => ['ticket.created'],
        'secret' => 'a-shared-secret',
    ]);

    $agent = makeAgent();
    app(TicketService::class)->create(['subject' => 'Signed', 'requester' => $agent], $agent);

    Http::assertSent(function ($request): bool {
        $signature = $request->header('X-Ticktz-Signature')[0] ?? '';

        // The signature covers the exact bytes sent, so the receiver's check
        // is one line and cannot drift from what was on the wire.
        return $signature === 'sha256='.hash_hmac('sha256', $request->body(), 'a-shared-secret');
    });
});

it('sends no signature header when the subscription has no secret', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    WebhookSubscription::factory()->unsigned()->create(['events' => ['ticket.created']]);

    $agent = makeAgent();
    app(TicketService::class)->create(['subject' => 'Unsigned', 'requester' => $agent], $agent);

    Http::assertSent(fn ($request) => $request->header('X-Ticktz-Signature') === []);
});

it('only delivers to endpoints subscribed to that event', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $wants = WebhookSubscription::factory()->listeningFor(['ticket.created'])->create();
    WebhookSubscription::factory()->listeningFor(['sla.breached'])->create();
    WebhookSubscription::factory()->inactive()->listeningFor(['ticket.created'])->create();

    $agent = makeAgent();
    app(TicketService::class)->create(['subject' => 'Selective', 'requester' => $agent], $agent);

    $deliveries = WebhookDelivery::query()->get();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->first()->webhook_subscription_id)->toBe($wants->getKey());
});

it('records a failure and leaves the retry to the queue', function (): void {
    Http::fake(['*' => Http::response('upstream exploded', 500)]);

    $subscription = WebhookSubscription::factory()->create(['events' => ['ticket.created']]);

    $agent = makeAgent();

    // The ticket is created regardless. On `sync` an unreachable endpoint must
    // not travel back up the listener and take down the thing that raised the
    // event — see DeliverWebhookSubscriptionJob::surrender().
    $ticket = app(TicketService::class)->create(['subject' => 'Will fail', 'requester' => $agent], $agent);

    expect($ticket->exists)->toBeTrue();

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->response_status)->toBe(500)
        ->and($delivery->response_body)->toContain('upstream exploded');

    expect($subscription->fresh()->consecutive_failures)->toBe(1)
        ->and($subscription->fresh()->last_failed_at)->not->toBeNull();
});

it('truncates a huge response body rather than storing all of it', function (): void {
    Http::fake(['*' => Http::response(str_repeat('x', 200_000), 200)]);

    WebhookSubscription::factory()->create(['events' => ['ticket.created']]);

    $agent = makeAgent();
    app(TicketService::class)->create(['subject' => 'Chatty endpoint', 'requester' => $agent], $agent);

    expect(mb_strlen((string) WebhookDelivery::query()->sole()->response_body))
        ->toBe(WebhookDelivery::RESPONSE_LIMIT);
});

it('switches a subscription off after a long run of failures', function (): void {
    Http::fake(['*' => Http::response('gone', 500)]);

    $subscription = WebhookSubscription::factory()->create([
        'events' => ['ticket.created'],
        'consecutive_failures' => WebhookSubscription::FAILURE_LIMIT - 1,
    ]);

    $delivery = WebhookDelivery::query()->create([
        'webhook_subscription_id' => $subscription->getKey(),
        'event' => 'ticket.created',
        'payload' => ['event' => 'ticket.created'],
        'status' => WebhookDelivery::STATUS_PENDING,
    ]);

    (new DeliverWebhookSubscriptionJob($delivery->getKey()))->handle();

    expect($subscription->fresh()->is_active)->toBeFalse()
        ->and($subscription->fresh()->consecutive_failures)->toBe(WebhookSubscription::FAILURE_LIMIT);
});

it('never puts an internal note body on the wire', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    WebhookSubscription::factory()->listeningFor(['ticket.commented'])->create();

    $agent = makeAgent();
    $ticket = Ticket::factory()->create(['requester_id' => $agent->getKey()]);

    app(TicketService::class)->comment($ticket, 'Escalate to networking, customer is furious', $agent, internal: true);

    $delivery = WebhookDelivery::query()->sole();

    // A webhook receiver has no policy to run and no user to check, so the
    // agent's side of the conversation never leaves the building.
    expect($delivery->payload['data']['comment']['is_internal'])->toBeTrue()
        ->and($delivery->payload['data']['comment']['body'])->toBeNull();

    Http::assertSent(fn ($request) => ! str_contains($request->body(), 'furious'));
});

it('writes a pending delivery row before the job runs, so a lost job still leaves a trace', function (): void {
    Bus::fake();

    WebhookSubscription::factory()->listeningFor(['ticket.created'])->create();

    $agent = makeAgent();
    app(TicketService::class)->create(['subject' => 'Traceable', 'requester' => $agent], $agent);

    expect(WebhookDelivery::query()->sole()->status)->toBe(WebhookDelivery::STATUS_PENDING);

    Bus::assertDispatched(DeliverWebhookSubscriptionJob::class);
});

it('manages subscriptions over the API and shows the secret exactly once', function (): void {
    $admin = makeAdmin();
    $token = $admin->createToken('integrator', ['webhooks.manage'])->plainTextToken;

    $created = withToken($token)->postJson('/api/v1/webhooks', [
        'name' => 'Middleware platform',
        'url' => 'https://example.com/hooks/ticktz',
        'events' => ['ticket.created', 'sla.breached'],
    ])->assertCreated();

    $secret = $created->json('data.secret');
    expect($secret)->toBeString()->and(mb_strlen($secret))->toBe(48);

    $id = $created->json('data.id');

    // Never again, from anywhere.
    $listed = withToken($token)->getJson('/api/v1/webhooks')->assertOk();
    expect($listed->json('data.0'))->not->toHaveKey('secret')
        ->and($listed->json('data.0.signed'))->toBeTrue();

    withToken($token)->patchJson('/api/v1/webhooks/'.$id, ['is_active' => false])->assertOk();
    expect(WebhookSubscription::query()->find($id)->is_active)->toBeFalse();
});

it('refuses an unknown event and a plaintext URL', function (): void {
    $admin = makeAdmin();
    $token = $admin->createToken('integrator', ['webhooks.manage'])->plainTextToken;

    withToken($token)->postJson('/api/v1/webhooks', [
        'name' => 'Typo',
        'url' => 'https://example.com/hooks',
        'events' => ['ticket.créated'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // http is refused outside local development: a signed payload is still a
    // requester's e-mail address crossing the network in the clear.
    config()->set('app.env', 'production');
    app()->detectEnvironment(fn () => 'production');

    withToken($token)->postJson('/api/v1/webhooks', [
        'name' => 'Plaintext',
        'url' => 'http://example.com/hooks',
        'events' => ['ticket.created'],
    ])->assertStatus(422);

    expect(WebhookSubscription::query()->count())->toBe(0);
});

it('refuses webhook management without the scope', function (): void {
    $admin = makeAdmin();
    $token = $admin->createToken('read only', ['tickets.read'])->plainTextToken;

    withToken($token)->getJson('/api/v1/webhooks')
        ->assertStatus(403)
        ->assertJsonPath('error.required_scope', 'webhooks.manage');
});
