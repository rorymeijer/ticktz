<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Priority;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Priority>
 */
class PriorityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'level' => 3,
            'color' => '#2563eb',
            'is_default' => false,
            'is_public' => true,
            'position' => 100,
        ];
    }
}
