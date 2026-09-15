<?php

declare(strict_types=1);

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    seedServiceDesk();
});

it('renders the token screen with only the scopes the person can use', function (): void {
    $agent = makeAgent();
    $agent->createToken('existing', ['tickets.read']);

    actingAs($agent)->get('/settings/api-tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/ApiTokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'existing')
            // An agent has no `assets.manage`, so the write scope is not on
            // offer: a checkbox that mints a token which then 403s is a bug
            // report waiting to happen.
            ->where('scopes', fn ($scopes) => collect($scopes)->pluck('key')->contains('tickets.read')
                && ! collect($scopes)->pluck('key')->contains('assets.write'))
        );
});

it('never sends a token hash to the browser', function (): void {
    $agent = makeAgent();
    $agent->createToken('existing', ['tickets.read']);

    $response = actingAs($agent)->get('/settings/api-tokens')->assertOk();

    expect($response->getContent())->not->toContain($agent->tokens()->sole()->token);
});

it('renders the webhook admin screen with its delivery log', function (): void {
    $admin = makeAdmin();
    $subscription = WebhookSubscription::factory()->create(['name' => 'Middleware']);

    WebhookDelivery::query()->create([
        'webhook_subscription_id' => $subscription->getKey(),
        'event' => 'ticket.created',
        'payload' => ['event' => 'ticket.created'],
        'status' => WebhookDelivery::STATUS_FAILED,
        'attempts' => 3,
        'response_status' => 502,
        'error' => 'The endpoint answered HTTP 502.',
    ]);

    actingAs($admin)->get('/admin/webhooks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Webhooks/Index')
            ->has('subscriptions.data', 1)
            ->has('deliveries', 1)
            ->where('deliveries.0.response_status', 502)
            ->has('events', count(WebhookSubscription::EVENTS))
        );
});

it('never sends a webhook signing secret to the browser', function (): void {
    $admin = makeAdmin();
    WebhookSubscription::factory()->create(['secret' => 'super-secret-signing-key']);

    $response = actingAs($admin)->get('/admin/webhooks')->assertOk();

    // The payload names what may be shown, so a secret cannot ride along by
    // accident — this pins that.
    expect($response->getContent())->not->toContain('super-secret-signing-key');
});

it('keeps the webhook screen behind its own permission', function (): void {
    actingAs(makeAgent())->get('/admin/webhooks')->assertForbidden();
});

it('sends a test delivery through the real path', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $admin = makeAdmin();
    $subscription = WebhookSubscription::factory()->create();

    actingAs($admin)->post('/admin/webhooks/'.$subscription->getKey().'/test')->assertRedirect();

    // The same delivery row and the same job as a real event: a test that
    // takes a different path only proves the test path works.
    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->payload['data']['test'])->toBeTrue();
});
