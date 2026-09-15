<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BusinessCalendar;
use App\Models\CalendarHoliday;
use App\Models\Priority;
use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

/**
 * A working SLA out of the box: two calendars, one policy, eight goals.
 *
 * The targets are the conventional ones for an internal IT desk and are meant
 * to be edited — what matters is that a fresh instance measures *something*,
 * because an SLA feature that starts empty is an SLA feature nobody turns on.
 *
 * Idempotent, like every other seeder here.
 */
class SlaSeeder extends Seeder
{
    /**
     * Minutes, per priority, per metric. A priority that is not listed falls
     * back to the catch-all goal seeded alongside these.
     *
     * @var array<string, array{first_response: int, resolution: int}>
     */
    private const TARGETS = [
        'urgent' => ['first_response' => 30, 'resolution' => 240],
        'high' => ['first_response' => 60, 'resolution' => 480],
        'normal' => ['first_response' => 240, 'resolution' => 2400],
        'low' => ['first_response' => 480, 'resolution' => 4800],
    ];

    /**
     * Dutch public holidays that fall on the same date every year. The movable
     * feasts (Easter and everything hanging off it) are deliberately absent:
     * they need a row per year, and guessing at them in a seeder would put
     * wrong dates in a calendar people trust.
     *
     * @var array<int, array{name: string, date: string}>
     */
    private const HOLIDAYS = [
        ['name' => 'New Year\'s Day', 'date' => '01-01'],
        ['name' => 'King\'s Day', 'date' => '04-27'],
        ['name' => 'Liberation Day', 'date' => '05-05'],
        ['name' => 'Christmas Day', 'date' => '12-25'],
        ['name' => 'Boxing Day', 'date' => '12-26'],
    ];

    public function run(): void
    {
        $office = BusinessCalendar::query()->updateOrCreate(['slug' => 'office-hours'], [
            'name' => 'Office hours',
            'description' => 'Monday to Friday, 08:30 to 17:30, excluding public holidays.',
            'timezone' => config('app.timezone') === 'UTC' ? 'Europe/Amsterdam' : config('app.timezone'),
            'working_hours' => BusinessCalendar::officeHours('08:30', '17:30'),
            'is_default' => true,
        ]);

        BusinessCalendar::query()->updateOrCreate(['slug' => 'around-the-clock'], [
            'name' => 'Around the clock',
            'description' => 'Every minute counts, including nights and weekends.',
            'timezone' => $office->timezone,
            'working_hours' => BusinessCalendar::alwaysOpen(),
            'is_default' => false,
        ]);

        foreach (self::HOLIDAYS as $holiday) {
            CalendarHoliday::query()->updateOrCreate(
                [
                    'business_calendar_id' => $office->getKey(),
                    'name' => $holiday['name'],
                    // A recurring holiday ignores the year, but the column is a
                    // date, so one has to be stored. 2000 is a leap year, which
                    // keeps 29 February representable.
                    'date' => '2000-'.$holiday['date'],
                ],
                ['is_recurring' => true],
            );
        }

        $policy = SlaPolicy::query()->updateOrCreate(['slug' => 'standard'], [
            'name' => 'Standard service',
            'description' => 'Applies to everything that no other policy claims.',
            'business_calendar_id' => $office->getKey(),
            'is_active' => true,
            'is_default' => true,
            'conditions' => null,
            'position' => 1000,
        ]);

        $priorities = Priority::query()->pluck('id', 'slug');

        foreach (self::TARGETS as $slug => $targets) {
            $priorityId = $priorities[$slug] ?? null;

            if (! $priorityId) {
                continue;
            }

            foreach ($targets as $metric => $minutes) {
                SlaGoal::query()->updateOrCreate(
                    [
                        'sla_policy_id' => $policy->getKey(),
                        'metric' => $metric,
                        'priority_id' => $priorityId,
                        'request_type_id' => null,
                    ],
                    [
                        'target_minutes' => $minutes,
                        'escalations' => $this->escalationsFor($slug, $metric),
                        'is_active' => true,
                    ],
                );
            }
        }

        // The catch-all, for a ticket whose priority has no goal of its own.
        foreach (['first_response' => 240, 'resolution' => 2400] as $metric => $minutes) {
            SlaGoal::query()->updateOrCreate(
                [
                    'sla_policy_id' => $policy->getKey(),
                    'metric' => $metric,
                    'priority_id' => null,
                    'request_type_id' => null,
                ],
                ['target_minutes' => $minutes, 'is_active' => true],
            );
        }
    }

    /**
     * Only the two urgent tiers escalate out of the box. Warning on every
     * normal-priority ticket at 75% would train agents to ignore the warning,
     * which is worse than not having one.
     *
     * @return array<int, array{at: int, actions: array<int, array<string, mixed>>}>|null
     */
    private function escalationsFor(string $priority, string $metric): ?array
    {
        if (! in_array($priority, ['urgent', 'high'], true)) {
            return null;
        }

        return [
            ['at' => 75, 'actions' => [['type' => 'notify', 'to' => 'assignee']]],
            ['at' => 100, 'actions' => [['type' => 'notify', 'to' => 'team_leads']]],
        ];
    }
}
