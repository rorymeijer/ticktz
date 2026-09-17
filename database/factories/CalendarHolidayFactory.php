<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BusinessCalendar;
use App\Models\CalendarHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarHoliday>
 */
class CalendarHolidayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_calendar_id' => BusinessCalendar::factory(),
            'name' => fake()->unique()->words(2, true),
            'date' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'is_recurring' => false,
        ];
    }

    public function recurring(): static
    {
        return $this->state(fn () => ['is_recurring' => true]);
    }
}
