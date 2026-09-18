<?php

declare(strict_types=1);

use App\Models\AutomationRule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| No administration screen carries the staff directory any more
|--------------------------------------------------------------------------
|
| Every one of these pages used to render every active agent into itself so a
| dropdown or a checkbox list could hold them. That is a directory on a form,
| and it stops being usable at exactly the size where teams, automation and
| approval workflows start to matter.
|
| What each page ships now is only the people it already refers to — so their
| names render — and the picker searches `GET /people` for the rest. These
| tests pin both halves: the named person is there, and a stranger is not.
*/

function propsOf(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

it('gives a team form its own members and nobody else', function (): void {
    $admin = makeAdmin();
    /** @var User $member */
    $member = makeAgent();
    /** @var User $stranger */
    $stranger = makeAgent();

    $team = Team::factory()->create();
    $team->members()->attach($member);

    $people = collect(propsOf($this->actingAs($admin)->get("/admin/teams/{$team->id}/edit")->assertOk())['people'])
        ->pluck('id');

    expect($people)->toContain($member->getKey())
        ->and($people)->not->toContain($stranger->getKey());
});

it('gives a new team form nobody at all', function (): void {
    $admin = makeAdmin();
    makeAgent();

    expect(propsOf($this->actingAs($admin)->get('/admin/teams/create')->assertOk())['people'])->toBe([]);
});

it('gives a user form the manager that user already has', function (): void {
    $admin = makeAdmin();
    /** @var User $manager */
    $manager = makeAgent();
    /** @var User $stranger */
    $stranger = makeAgent();

    $subject = makeAgent(['manager_id' => $manager->getKey()]);

    $managers = collect(propsOf($this->actingAs($admin)->get("/admin/users/{$subject->id}/edit")->assertOk())['managers'])
        ->pluck('id');

    expect($managers)->toContain($manager->getKey())
        ->and($managers)->not->toContain($stranger->getKey());
});

it('gives the automation screen the people its rules name', function (): void {
    // A rule refers to somebody in two places, and both live inside its JSON:
    // an action that assigns, and a condition that tests who holds the ticket.
    $admin = makeAdmin();
    /** @var User $assigned */
    $assigned = makeAgent();
    /** @var User $tested */
    $tested = makeAgent();
    /** @var User $stranger */
    $stranger = makeAgent();

    AutomationRule::factory()
        ->then([['type' => 'assign_user', 'user_id' => $assigned->getKey()]])
        ->create();

    AutomationRule::factory()->create([
        'conditions' => [['field' => 'assignee', 'operator' => 'is', 'value' => $tested->getKey()]],
    ]);

    $people = collect(propsOf($this->actingAs($admin)->get('/admin/automation')->assertOk())['options']['people'])
        ->pluck('id');

    expect($people)->toContain($assigned->getKey())
        ->and($people)->toContain($tested->getKey())
        ->and($people)->not->toContain($stranger->getKey());
});

it('reads a condition that names several people at once', function (): void {
    // "is one of" stores a list where "is" stores a single id. Reading only
    // the second shape leaves the other names blank on the screen.
    $admin = makeAdmin();
    /** @var User $first */
    $first = makeAgent();
    /** @var User $second */
    $second = makeAgent();

    AutomationRule::factory()->create([
        'conditions' => [[
            'field' => 'assignee',
            'operator' => 'is_one_of',
            'value' => [$first->getKey(), $second->getKey()],
        ]],
    ]);

    $people = collect(propsOf($this->actingAs($admin)->get('/admin/automation')->assertOk())['options']['people'])
        ->pluck('id');

    expect($people)->toContain($first->getKey())
        ->and($people)->toContain($second->getKey());
});
