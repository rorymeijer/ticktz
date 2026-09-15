<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BusinessCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessCalendar>
 */
class BusinessCalendarFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'timezone' => 'Europe/Amsterdam',
            'working_hours' => BusinessCalendar::officeHours(),
            'is_default' => false,
        ];
    }

    /**
     * Nights and weekends included, which makes a target in working minutes
     * the same as one in wall-clock minutes — useful when a test is about
     * something other than the calendar.
     */
    public function alwaysOpen(): static
    {
        return $this->state(fn () => ['working_hours' => BusinessCalendar::alwaysOpen()]);
    }

    public function officeHours(string $start = '09:00', string $end = '17:00'): static
    {
        return $this->state(fn () => ['working_hours' => BusinessCalendar::officeHours($start, $end)]);
    }

    public function utc(): static
    {
        return $this->state(fn () => ['timezone' => 'UTC']);
    }
}
