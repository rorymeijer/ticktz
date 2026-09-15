<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PortalCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PortalCategory>
 */
class PortalCategoryFactory extends Factory
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
            'color' => '#4f46e5',
            'is_active' => true,
            'position' => 100,
        ];
    }
}
