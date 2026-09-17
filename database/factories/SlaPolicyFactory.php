<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BusinessCalendar;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SlaPolicy>
 */
class SlaPolicyFactory extends Factory
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
            'business_calendar_id' => BusinessCalendar::factory(),
            'is_active' => true,
            'is_default' => false,
            'conditions' => null,
            'position' => 100,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true, 'position' => 1000]);
    }

    public function on(BusinessCalendar $calendar): static
    {
        return $this->state(fn () => ['business_calendar_id' => $calendar->getKey()]);
    }

    /**
     * @param  array<string, array<int, int>>  $conditions
     */
    public function claiming(array $conditions, int $position = 10): static
    {
        return $this->state(fn () => ['conditions' => $conditions, 'position' => $position]);
    }
}
