<?php

declare(strict_types=1);

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| Where signing in puts you
|--------------------------------------------------------------------------
|
| The two sides of Ticktz are not a preference, they are what somebody can do.
| `/` had always redirected on that test and signing in had not, so a requester
| who logged in landed on a dashboard built to answer "what should I work on
| next" — with no answer to it and nothing on it they could open.
*/

it('sends an agent to the dashboard', function (): void {
    $agent = makeAgent(['password' => bcrypt('secret-shibboleth')]);

    $this->post('/login', ['email' => $agent->email, 'password' => 'secret-shibboleth'])
        ->assertRedirect('/dashboard');
});

it('sends everybody else to the portal', function (): void {
    $requester = makeRequester(['password' => bcrypt('secret-shibboleth')]);

    $this->post('/login', ['email' => $requester->email, 'password' => 'secret-shibboleth'])
        ->assertRedirect('/portal');
});

it('leaves the dashboard reachable for a requester who asks for it', function (): void {
    // This is where I first overreached. The dashboard has a deliberate
    // requester mode — their own open requests, with the agent cards left out
    // — so redirecting them away from it deleted a working feature. What was
    // asked for is where somebody *lands*, not what they may open.
    $this->actingAs(makeRequester())->get('/dashboard')->assertOk();
});

it('agrees with the root route', function (): void {
    // One helper answers this, so the two cannot drift.
    $this->actingAs(makeRequester())->get('/')->assertRedirect('/portal');
    $this->actingAs(makeAgent())->get('/')->assertRedirect('/dashboard');
});
