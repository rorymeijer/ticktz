<?php

declare(strict_types=1);

use App\Models\Priority;
use App\Models\Queue;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Models\Workflow;

beforeEach(function () {
    seedServiceDesk();
});

test('configuring the service desk requires settings.manage', function () {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)->get('/admin/service-desk')->assertForbidden();
    $this->actingAs($agent)->post('/admin/statuses', [])->assertForbidden();
});

test('an administrator adds a status', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/admin/statuses', [
            'name' => 'Waiting for change window',
            'slug' => 'waiting-change-window',
            'category' => 'pending',
            'color' => '#a855f7',
            'pauses_sla' => true,
            'is_public' => true,
            'position' => 45,
        ])
        ->assertRedirect();

    $status = TicketStatus::query()->where('slug', 'waiting-change-window')->sole();

    expect($status->pauses_sla)->toBeTrue()
        ->and($status->category)->toBe('pending')
        ->and($status->is_system)->toBeFalse();
});

test('a system status keeps its slug and category', function () {
    $admin = User::factory()->admin()->create();
    $status = status('resolved');

    $this->actingAs($admin)
        ->put("/admin/statuses/{$status->id}", [
            'name' => 'Afgehandeld',
            'slug' => 'something-else',
            'category' => 'open',
            'color' => '#059669',
            'is_public' => true,
            'position' => 50,
        ])
        ->assertRedirect();

    $status->refresh();

    expect($status->name)->toBe('Afgehandeld')
        ->and($status->slug)->toBe('resolved')
        ->and($status->category)->toBe('resolved');
});

test('a status still in use cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $status = TicketStatus::factory()->create();
    Ticket::factory()->create(['status_id' => $status->id]);

    $this->actingAs($admin)
        ->delete("/admin/statuses/{$status->id}")
        ->assertSessionHas('error');

    expect(TicketStatus::query()->whereKey($status->id)->exists())->toBeTrue();
});

test('only one priority can be the default', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/priorities', [
        'name' => 'Critical',
        'slug' => 'critical',
        'level' => 1,
        'color' => '#b91c1c',
        'is_default' => true,
        'is_public' => false,
    ])->assertRedirect();

    expect(Priority::query()->where('is_default', true)->count())->toBe(1)
        ->and(Priority::query()->where('is_default', true)->sole()->slug)->toBe('critical');
});

test('the last priority cannot be removed', function () {
    $admin = User::factory()->admin()->create();
    Priority::query()->whereKeyNot(priority('normal')->id)->delete();

    $this->actingAs($admin)
        ->delete('/admin/priorities/'.priority('normal')->id)
        ->assertSessionHas('error');

    expect(Priority::query()->count())->toBe(1);
});

test('a workflow is stored as statuses plus a transition matrix', function () {
    $admin = User::factory()->admin()->create();

    $new = status('new');
    $open = status('open');
    $resolved = status('resolved');

    $this->actingAs($admin)
        ->post('/admin/workflows', [
            'name' => 'Simple',
            'slug' => 'simple',
            'is_default' => false,
            'is_active' => true,
            'status_ids' => [$new->id, $open->id, $resolved->id],
            'initial_status_id' => $new->id,
            'transitions' => [
                ['from_status_id' => $new->id, 'to_status_id' => $open->id, 'requires_comment' => false, 'requires_assignee' => false, 'required_permission' => null],
                ['from_status_id' => $open->id, 'to_status_id' => $resolved->id, 'requires_comment' => true, 'requires_assignee' => false, 'required_permission' => null],
            ],
        ])
        ->assertRedirect('/admin/workflows');

    $workflow = Workflow::query()->where('slug', 'simple')->sole();

    expect($workflow->statuses)->toHaveCount(3)
        ->and($workflow->initialStatus()->slug)->toBe('new')
        ->and($workflow->transitions)->toHaveCount(2)
        ->and($workflow->transitions->firstWhere('to_status_id', $resolved->id)->requires_comment)->toBeTrue();
});

test('a transition to a status outside the workflow is dropped', function () {
    $admin = User::factory()->admin()->create();
    $new = status('new');
    $open = status('open');
    $closed = status('closed');

    $this->actingAs($admin)->post('/admin/workflows', [
        'name' => 'Partial',
        'slug' => 'partial',
        'status_ids' => [$new->id, $open->id],
        'initial_status_id' => $new->id,
        'transitions' => [
            ['from_status_id' => $new->id, 'to_status_id' => $open->id],
            ['from_status_id' => $new->id, 'to_status_id' => $closed->id],
        ],
    ])->assertRedirect();

    expect(Workflow::query()->where('slug', 'partial')->sole()->transitions)->toHaveCount(1);
});

test('a workflow in use cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $workflow = Workflow::query()->where('slug', 'default')->sole();
    Ticket::factory()->create(['workflow_id' => $workflow->id]);

    $this->actingAs($admin)->delete("/admin/workflows/{$workflow->id}")->assertSessionHas('error');

    expect(Workflow::query()->whereKey($workflow->id)->exists())->toBeTrue();
});

test('an administrator creates a queue with a filter', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/admin/queues', [
            'name' => 'Urgent and unassigned',
            'slug' => 'urgent-unassigned',
            'filters' => [
                'status_category' => ['new', 'open'],
                'priority' => [priority('urgent')->id],
                'assignee' => ['unassigned'],
                'unanswered' => false,
            ],
            'sort_by' => 'created_at',
            'sort_direction' => 'asc',
            'columns' => ['key', 'subject', 'status'],
            'is_shared' => true,
            'is_active' => true,
            'position' => 5,
        ])
        ->assertRedirect('/admin/queues');

    $queue = Queue::query()->where('slug', 'urgent-unassigned')->sole();

    // Empty and false filter values are dropped so a queue definition stays
    // readable and the filter service never sees a blank clause.
    expect($queue->filters)->toHaveKeys(['status_category', 'priority', 'assignee'])
        ->and($queue->filters)->not->toHaveKey('unanswered')
        ->and($queue->visibleColumns())->toBe(['key', 'subject', 'status']);

    // And it actually filters.
    $urgent = Ticket::factory()->create(['priority_id' => priority('urgent')->id]);
    Ticket::factory()->create(['priority_id' => priority('low')->id]);

    $this->actingAs($admin)
        ->get('/agent/tickets?queue_slug=urgent-unassigned')
        ->assertInertia(fn ($page) => $page->has('tickets.data', 1)
            ->where('tickets.data.0.key', $urgent->key));
});
