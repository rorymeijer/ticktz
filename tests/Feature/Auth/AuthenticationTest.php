<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

test('the login screen can be rendered', function () {
    seedRbac();

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login')->has('canResetPassword'));
});

test('a local account can sign in with its e-mail address', function () {
    $user = makeAgent();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user->fresh());
});

test('a local account can sign in with its username', function () {
    seedRbac();
    $user = User::factory()->agent()->create(['username' => 'j.jansen']);

    $this->post('/login', ['email' => 'j.jansen', 'password' => 'password'])->assertRedirect();

    $this->assertAuthenticated();
});

test('signing in records the time and address', function () {
    $user = makeAgent();

    expect($user->last_login_at)->toBeNull();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->fresh()->last_login_ip)->not->toBeNull();
});

test('a wrong password is rejected', function () {
    $user = makeAgent();

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a deactivated account cannot sign in even with the right password', function () {
    seedRbac();
    $user = User::factory()->agent()->inactive()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('repeated failures are rate limited', function () {
    $user = makeAgent();
    $limit = (int) config('ticktz.rate_limits.login');

    for ($attempt = 0; $attempt < $limit; $attempt++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();

    expect(session('errors')->first('email'))->toContain('Too many');

    RateLimiter::clear('login');
});

test('a requester lands on the portal and an agent on the dashboard', function () {
    $requester = makeRequester();
    $this->actingAs($requester)->get('/')->assertRedirect('/portal');

    $agent = User::factory()->agent()->create();
    $this->actingAs($agent->fresh())->get('/')->assertRedirect('/dashboard');
});

test('signing out clears the session', function () {
    $user = makeAgent();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});
