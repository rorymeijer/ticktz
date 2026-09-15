<?php

declare(strict_types=1);

use App\Models\AuditLogEntry;
use App\Models\BusinessCalendar;
use App\Models\CalendarHoliday;
use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use Database\Seeders\SlaSeeder;

beforeEach(function (): void {
    seedServiceDesk();
    $this->admin = makeAdmin();
});

/**
 * @return array<string, mixed>
 */
function policyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Gold service',
        'slug' => 'gold-service',
        'business_calendar_id' => BusinessCalendar::factory()->create()->getKey(),
        'is_active' => true,
        'is_default' => false,
        'position' => 10,
        'conditions' => [],
    ], $overrides);
}

it('lets an administrator open the service level page', function (): void {
    $this->actingAs($this->admin)->get('/admin/sla')->assertOk();
});

it('refuses every service level endpoint without the permission', function (string $method, string $uri): void {
    $this->actingAs(makeAgent())->call($method, $uri)->assertForbidden();
})->with([
    ['get', '/admin/sla'],
    ['post', '/admin/sla/policies'],
    ['post', '/admin/sla/calendars'],
]);

it('creates a policy and audits it', function (): void {
    $this->actingAs($this->admin)->post('/admin/sla/policies', policyPayload())->assertRedirect();

    $policy = SlaPolicy::query()->sole();

    expect($policy->slug)->toBe('gold-service')
        ->and(AuditLogEntry::query()
            ->where('auditable_type', $policy->getMorphClass())
            ->where('event', 'created')
            ->exists())->toBeTrue();
});

/**
 * Two defaults means the engine picks one arbitrarily, which is the worst
 * possible outcome: a target that depends on row order.
 */
it('demotes the previous default when another is promoted', function (): void {
    $first = SlaPolicy::factory()->default()->create();

    $this->actingAs($this->admin)
        ->post('/admin/sla/policies', policyPayload(['is_default' => true]))
        ->assertRedirect();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(SlaPolicy::query()->where('is_default', true)->count())->toBe(1);
});

it('stores only the conditions the engine can evaluate', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/sla/policies', policyPayload([
            'conditions' => [
                'team_ids' => [],
                'invented_ids' => [1, 2, 3],
            ],
        ]))
        ->assertRedirect();

    expect(SlaPolicy::query()->sole()->conditions)->toBe([]);
});

it('refuses to delete the last policy standing', function (): void {
    $policy = SlaPolicy::factory()->default()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/sla/policies/{$policy->id}")
        ->assertStatus(422);

    expect(SlaPolicy::query()->count())->toBe(1);
});

it('deletes a policy when another remains', function (): void {
    SlaPolicy::factory()->default()->create();
    $policy = SlaPolicy::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/sla/policies/{$policy->id}")
        ->assertRedirect();

    expect(SlaPolicy::query()->count())->toBe(1);
});

// -----------------------------------------------------------------
// Goals
// -----------------------------------------------------------------

it('adds a goal to a policy', function (): void {
    $policy = SlaPolicy::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/sla/policies/{$policy->id}/goals", [
            'metric' => 'first_response',
            'target_minutes' => 120,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($policy->goals()->sole()->target_minutes)->toBe(120);
});

it('replaces rather than duplicates a goal for the same metric and scope', function (): void {
    $policy = SlaPolicy::factory()->create();

    foreach ([120, 60] as $minutes) {
        $this->actingAs($this->admin)->post("/admin/sla/policies/{$policy->id}/goals", [
            'metric' => 'first_response',
            'target_minutes' => $minutes,
        ]);
    }

    expect($policy->goals()->count())->toBe(1)
        ->and($policy->goals()->sole()->target_minutes)->toBe(60);
});

it('rejects a metric the engine does not measure', function (): void {
    $policy = SlaPolicy::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/sla/policies/{$policy->id}/goals", [
            'metric' => 'customer_happiness',
            'target_minutes' => 60,
        ])
        ->assertSessionHasErrors('metric');
});

it('rejects an escalation action the engine cannot perform', function (): void {
    $policy = SlaPolicy::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/sla/policies/{$policy->id}/goals", [
            'metric' => 'first_response',
            'target_minutes' => 60,
            'escalations' => [
                ['at' => 75, 'actions' => [['type' => 'delete_the_ticket']]],
            ],
        ])
        ->assertSessionHasErrors('escalations.0.actions.0.type');
});

it('stores a well-formed escalation', function (): void {
    $policy = SlaPolicy::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/sla/policies/{$policy->id}/goals", [
            'metric' => 'resolution',
            'target_minutes' => 480,
            'escalations' => [
                ['at' => 75, 'actions' => [['type' => 'notify', 'to' => 'assignee']]],
                ['at' => 100, 'actions' => [['type' => 'raise_priority']]],
            ],
        ])
        ->assertRedirect();

    expect($policy->goals()->sole()->escalationSteps())->toHaveCount(2);
});

// -----------------------------------------------------------------
// Calendars and holidays
// -----------------------------------------------------------------

it('creates a calendar with working hours', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/sla/calendars', [
            'name' => 'Extended hours',
            'slug' => 'extended-hours',
            'timezone' => 'Europe/Amsterdam',
            'is_default' => false,
            'working_hours' => [
                'mon' => [['08:00', '12:00'], ['13:00', '20:00']],
                'sat' => [],
            ],
        ])
        ->assertRedirect();

    $calendar = BusinessCalendar::query()->where('slug', 'extended-hours')->sole();

    expect($calendar->working_hours['mon'])->toHaveCount(2)
        ->and($calendar->working_hours['sat'])->toBe([]);
});

it('accepts 24:00 as the end of a round-the-clock day', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/sla/calendars', [
            'name' => 'Always',
            'slug' => 'always',
            'timezone' => 'UTC',
            'working_hours' => ['mon' => [['00:00', '24:00']]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('rejects a time that is not a time', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/sla/calendars', [
            'name' => 'Broken',
            'slug' => 'broken',
            'timezone' => 'UTC',
            'working_hours' => ['mon' => [['9am', '5pm']]],
        ])
        ->assertSessionHasErrors('working_hours.mon.0.0');
});

it('rejects a timezone that does not exist', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/sla/calendars', [
            'name' => 'Nowhere',
            'slug' => 'nowhere',
            'timezone' => 'Middle/Earth',
            'working_hours' => ['mon' => [['09:00', '17:00']]],
        ])
        ->assertSessionHasErrors('timezone');
});

/**
 * A policy whose calendar has gone cannot work out any due date at all.
 */
it('refuses to delete a calendar a policy depends on', function (): void {
    $calendar = BusinessCalendar::factory()->create();
    SlaPolicy::factory()->on($calendar)->create();

    $this->actingAs($this->admin)
        ->delete("/admin/sla/calendars/{$calendar->id}")
        ->assertStatus(422);

    expect(BusinessCalendar::query()->whereKey($calendar->getKey())->exists())->toBeTrue();
});

it('deletes an unused calendar', function (): void {
    $calendar = BusinessCalendar::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/sla/calendars/{$calendar->id}")
        ->assertRedirect();

    expect(BusinessCalendar::query()->whereKey($calendar->getKey())->exists())->toBeFalse();
});

it('adds a holiday and removes it again', function (): void {
    $calendar = BusinessCalendar::factory()->create();

    $this->actingAs($this->admin)
        ->post("/admin/sla/calendars/{$calendar->id}/holidays", [
            'name' => 'Christmas Day',
            'date' => '2025-12-25',
            'is_recurring' => true,
        ])
        ->assertRedirect();

    $holiday = CalendarHoliday::query()->sole();

    expect($holiday->is_recurring)->toBeTrue();

    $this->actingAs($this->admin)->delete("/admin/sla/holidays/{$holiday->id}")->assertRedirect();

    expect(CalendarHoliday::query()->count())->toBe(0);
});

it('deletes a calendar holidays along with it', function (): void {
    $calendar = BusinessCalendar::factory()->create();
    CalendarHoliday::factory()->for($calendar, 'calendar')->create();

    $this->actingAs($this->admin)->delete("/admin/sla/calendars/{$calendar->id}")->assertRedirect();

    expect(CalendarHoliday::query()->count())->toBe(0);
});

// -----------------------------------------------------------------
// The shipped defaults
// -----------------------------------------------------------------

/**
 * An SLA feature that starts empty is an SLA feature nobody turns on.
 */
it('ships a working policy out of the box', function (): void {
    app(SlaSeeder::class)->run();

    $policy = SlaPolicy::query()->where('slug', 'standard')->sole();

    expect($policy->is_default)->toBeTrue()
        ->and($policy->calendar)->not->toBeNull()
        ->and($policy->goals()->where('metric', 'first_response')->exists())->toBeTrue()
        ->and($policy->goals()->where('metric', 'resolution')->exists())->toBeTrue()
        // A catch-all for a priority with no goal of its own.
        ->and($policy->goals()->whereNull('priority_id')->count())->toBe(2);
});

it('seeds the same result twice', function (): void {
    app(SlaSeeder::class)->run();
    $before = SlaGoal::query()->count();

    app(SlaSeeder::class)->run();

    expect(SlaGoal::query()->count())->toBe($before)
        ->and(SlaPolicy::query()->count())->toBe(1)
        ->and(BusinessCalendar::query()->count())->toBe(2);
});

it('seeds holidays that repeat every year', function (): void {
    app(SlaSeeder::class)->run();

    $christmas = CalendarHoliday::query()->where('name', 'Christmas Day')->sole();

    expect($christmas->is_recurring)->toBeTrue()
        ->and($christmas->date->format('m-d'))->toBe('12-25');
});
