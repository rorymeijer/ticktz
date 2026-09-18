<?php

declare(strict_types=1);

use App\Models\Queue;
use App\Models\RequestType;

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| A saved view is not somewhere a ticket lives
|--------------------------------------------------------------------------
|
| A queue in Ticktz is two things wearing one name. Most are a place, and a
| ticket carries the id of the one it belongs to. Some are a saved view over
| everything — the seeded "Unassigned" and "Assigned to me" — and they mean
| something different per viewer and per moment.
|
| Nothing used to say so, and the request type form offered all of them as a
| destination. Point a request type at "Unassigned" and every ticket filed
| through it carries that queue for the rest of its life, so the ticket reads
| "Unassigned" on its own page while assigned to somebody. Which is exactly
| what it did.
*/

it('knows which of its queues are views', function (): void {
    $unassigned = Queue::query()->where('slug', 'unassigned')->sole();
    $mine = Queue::query()->where('slug', 'my-tickets')->sole();
    $place = Queue::factory()->create(['filters' => ['status_category' => ['new', 'open']]]);

    expect($unassigned->isView())->toBeTrue()
        ->and($mine->isView())->toBeTrue()
        // Filtered by status is still a perfectly good home: it says which
        // tickets to show, not who is looking or whether anybody has it.
        ->and($place->isView())->toBeFalse();
});

it('does not offer a view as somewhere to file tickets', function (): void {
    $admin = makeAdmin();
    $place = Queue::factory()->create(['name' => 'Hardware', 'filters' => []]);

    $offered = collect(
        $this->actingAs($admin)->get('/admin/request-types/create')->assertOk()
            ->viewData('page')['props']['queues']
    )->pluck('name');

    expect($offered)->toContain('Hardware')
        ->and($offered)->not->toContain('Unassigned')
        ->and($offered)->not->toContain('Assigned to me');
});

it('refuses one submitted anyway', function (): void {
    // Not merely left out of the dropdown: an id is an id, and the form is not
    // the only way this arrives.
    $admin = makeAdmin();
    $view = Queue::query()->where('slug', 'unassigned')->sole();
    $type = RequestType::factory()->create();

    $this->actingAs($admin)
        ->put("/admin/request-types/{$type->id}", [
            'name' => $type->name,
            'slug' => $type->slug,
            'visibility' => 'everyone',
            'queue_id' => $view->getKey(),
        ])
        ->assertSessionHasErrors('queue_id');

    expect($type->fresh()->queue_id)->not->toBe($view->getKey());
});

it('still accepts a real queue', function (): void {
    $admin = makeAdmin();
    $place = Queue::factory()->create(['filters' => []]);
    $type = RequestType::factory()->create();

    $this->actingAs($admin)
        ->put("/admin/request-types/{$type->id}", [
            'name' => $type->name,
            'slug' => $type->slug,
            'visibility' => 'everyone',
            'queue_id' => $place->getKey(),
        ])
        ->assertSessionHasNoErrors();

    expect($type->fresh()->queue_id)->toBe($place->getKey());
});
