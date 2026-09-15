<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\KbArticle;

use function Pest\Laravel\withToken;

beforeEach(function (): void {
    seedServiceDesk();
});

it('lists queues with their open counts', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('bot', ['queues.read'])->plainTextToken;

    $response = withToken($token)->getJson('/api/v1/queues')->assertOk();

    expect($response->json('data'))->not->toBeEmpty()
        ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'slug', 'filters', 'open_tickets']);
});

it('reads and upserts assets by tag', function (): void {
    $admin = makeAdmin();
    $type = AssetType::factory()->create(['slug' => 'laptop']);

    $token = $admin->createToken('inventory sync', ['assets.read', 'assets.write'])->plainTextToken;

    // First run: the tag is unknown, so it is created.
    withToken($token)->putJson('/api/v1/assets/tag/LAP-0001', [
        'name' => 'ThinkPad X1',
        'asset_type_id' => $type->getKey(),
        'serial_number' => 'SN-123',
    ])->assertCreated()->assertJsonPath('data.asset_tag', 'LAP-0001');

    // Second run with the same body: updated, not duplicated. That is what
    // makes a sync script safe to run twice.
    withToken($token)->putJson('/api/v1/assets/tag/LAP-0001', [
        'name' => 'ThinkPad X1 Carbon',
        'asset_type_id' => $type->getKey(),
        'serial_number' => 'SN-123',
    ])->assertOk()->assertJsonPath('data.name', 'ThinkPad X1 Carbon');

    expect(Asset::query()->count())->toBe(1);
});

it('refuses to write assets with a read-only scope', function (): void {
    $admin = makeAdmin();
    $type = AssetType::factory()->create();
    $token = $admin->createToken('read only', ['assets.read'])->plainTextToken;

    withToken($token)->getJson('/api/v1/assets')->assertOk();

    withToken($token)->postJson('/api/v1/assets', [
        'name' => 'Nope',
        'asset_type_id' => $type->getKey(),
    ])->assertStatus(403)->assertJsonPath('error.required_scope', 'assets.write');
});

it('keeps internal knowledge base articles away from a requester token', function (): void {
    $requester = makeRequester();

    KbArticle::factory()->create([
        'title' => 'How to reset your password',
        'slug' => 'reset-password',
        'status' => KbArticle::PUBLISHED,
        'visibility' => KbArticle::PUBLIC,
        'locale' => null,
        'published_at' => now(),
    ]);

    $internal = KbArticle::factory()->create([
        'title' => 'Escalation runbook',
        'slug' => 'escalation-runbook',
        'status' => KbArticle::PUBLISHED,
        'visibility' => KbArticle::INTERNAL,
        'locale' => null,
        'published_at' => now(),
    ]);

    $token = $requester->createToken('intranet page', ['kb.read'])->plainTextToken;

    $response = withToken($token)->getJson('/api/v1/kb/articles')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.slug'))->toBe('reset-password');

    // Not readable by slug either — the list filter is not the only guard.
    withToken($token)->getJson('/api/v1/kb/articles/'.$internal->slug)->assertStatus(404);
});

it('sends the article body only from the single-article endpoint', function (): void {
    $agent = makeAgent();

    KbArticle::factory()->create([
        'slug' => 'vpn-setup',
        'body' => '<p>Install the client, then sign in.</p>',
        'status' => KbArticle::PUBLISHED,
        'visibility' => KbArticle::PUBLIC,
        'locale' => null,
        'published_at' => now(),
    ]);

    $token = $agent->createToken('bot', ['kb.read'])->plainTextToken;

    // A list of fifty articles must not carry fifty full bodies.
    $list = withToken($token)->getJson('/api/v1/kb/articles')->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('body');

    withToken($token)->getJson('/api/v1/kb/articles/vpn-setup')
        ->assertOk()
        ->assertJsonPath('data.body', '<p>Install the client, then sign in.</p>');
});

it('gives each token its own rate limit bucket', function (): void {
    $agent = makeAgent();

    $noisy = $agent->createToken('noisy', ['tickets.read']);
    $noisy->accessToken->forceFill(['rate_limit' => 2])->save();

    $quiet = $agent->createToken('quiet', ['tickets.read']);

    // Two requests are all the noisy token gets.
    withToken($noisy->plainTextToken)->getJson('/api/v1/tickets')->assertOk();
    app('auth')->forgetGuards();
    withToken($noisy->plainTextToken)->getJson('/api/v1/tickets')->assertOk();
    app('auth')->forgetGuards();
    withToken($noisy->plainTextToken)->getJson('/api/v1/tickets')->assertStatus(429);

    // The other token belongs to the same person and is unaffected: one
    // integration polling in a loop must not lock the others out.
    app('auth')->forgetGuards();
    withToken($quiet->plainTextToken)->getJson('/api/v1/tickets')->assertOk();
});
