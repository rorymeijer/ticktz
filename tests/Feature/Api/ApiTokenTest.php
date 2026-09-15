<?php

declare(strict_types=1);

use App\Models\Ticket;
use App\Models\User;
use App\Support\ApiScopes;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

beforeEach(function (): void {
    seedServiceDesk();
});

/**
 * The property the whole scope design rests on: a token is the intersection of
 * what it was issued for and what its owner may do. Both halves are tested
 * here, because only ever testing the first one is how an API ends up with
 * tokens that outlive the role that justified them.
 */
it('refuses a token that was not issued the scope', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('read only', ['tickets.read'])->plainTextToken;

    withToken($token)
        ->postJson('/api/v1/tickets', ['subject' => 'Printer on fire'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'missing_scope')
        ->assertJsonPath('error.required_scope', 'tickets.write');

    expect(Ticket::query()->count())->toBe(0);
});

it('refuses a scope its owner has no permission for', function (): void {
    // The token asks for everything; the account behind it is a requester who
    // may file a ticket in the portal and nothing else.
    $requester = makeRequester();
    $token = $requester->createToken('too much', ApiScopes::all())->plainTextToken;

    withToken($token)
        ->getJson('/api/v1/assets')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('narrows an existing token when its owner loses the role', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('desk bot', ['tickets.read'])->plainTextToken;

    withToken($token)->getJson('/api/v1/tickets')->assertOk();

    // No token was touched here — only the account behind it.
    $agent->roles()->detach();

    // Two requests in one test share the container, and with it the auth
    // guard's resolved user. A real second request resolves the token from
    // scratch; this is how the test gets the same starting point.
    app('auth')->forgetGuards();

    withToken($token)
        ->getJson('/api/v1/tickets')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('refuses a token belonging to a deactivated account', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('desk bot', ['tickets.read'])->plainTextToken;

    $agent->forceFill(['is_active' => false])->save();

    withToken($token)
        ->getJson('/api/v1/tickets')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'account_inactive');
});

it('refuses an expired token', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('yesterday', ['tickets.read'], now()->subDay())->plainTextToken;

    withToken($token)
        ->getJson('/api/v1/tickets')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('refuses a request with no token at all', function (): void {
    getJson('/api/v1/tickets')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('answers /me for a token with no scopes, so a caller can find out why it is stuck', function (): void {
    $agent = makeAgent();
    $token = $agent->createToken('scopeless', [])->plainTextToken;

    withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $agent->email)
        ->assertJsonPath('data.token.scopes', []);
});

it('returns JSON even when the caller sends no Accept header', function (): void {
    // An integration that forgets the header must not receive an HTML error
    // page it cannot parse.
    $this->get('/api/v1/tickets')
        ->assertStatus(401)
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('lets a person mint a token through the UI and shows the plaintext exactly once', function (): void {
    $agent = makeAgent();

    $response = $this->actingAs($agent)->post('/settings/api-tokens', [
        'name' => 'Monitoring',
        'scopes' => ['tickets.read'],
    ]);

    $response->assertRedirect();
    $plain = session('token')['plain'] ?? null;

    // Sanctum's format is `<id>|<prefix><secret>`. The prefix is what makes a
    // leaked token recognisable to a secret scanner.
    expect($plain)->toBeString()
        ->and($plain)->toMatch('/^\d+\|ticktz_[A-Za-z0-9]+$/');

    // Only the hash is stored. The plaintext exists in that one response and
    // nowhere else, which is the whole reason it cannot be shown again.
    [$id, $secret] = explode('|', (string) $plain, 2);
    $stored = $agent->tokens()->sole();

    expect($stored->getKey())->toBe((int) $id)
        ->and($stored->token)->not->toBe($secret)
        ->and($stored->token)->toBe(hash('sha256', $secret))
        ->and($stored->abilities)->toBe(['tickets.read']);
});

it('refuses to mint a scope the person cannot exercise', function (): void {
    // May use the API, may read the knowledge base — and nothing to do with
    // assets. Asking for `assets.write` is refused at issue time rather than
    // producing a token that 403s on first use.
    $user = makeUserWithPermissions('api.tokens.manage', 'kb.view');

    $this->actingAs($user)
        ->post('/settings/api-tokens', ['name' => 'Nope', 'scopes' => ['assets.write']])
        ->assertSessionHasErrors('scopes.0');

    expect(User::query()->find($user->getKey())->tokens()->count())->toBe(0);
});

it('keeps the token screen behind its own permission', function (): void {
    // A requester has no business minting API credentials, and the screen is
    // not merely hidden from them.
    $this->actingAs(makeRequester())->get('/settings/api-tokens')->assertForbidden();
});
