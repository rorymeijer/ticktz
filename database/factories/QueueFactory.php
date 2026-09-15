<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Queue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Queue>
 */
class QueueFactory extends Factory
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
            'filters' => ['status_category' => ['new', 'open']],
            'sort_by' => 'updated_at',
            'sort_direction' => 'desc',
            'columns' => Queue::DEFAULT_COLUMNS,
            'is_shared' => true,
            'is_active' => true,
            'position' => 100,
        ];
    }
}
