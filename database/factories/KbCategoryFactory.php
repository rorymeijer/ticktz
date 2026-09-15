<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KbCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KbCategory>
 */
class KbCategoryFactory extends Factory
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
            'visibility' => KbCategory::PUBLIC,
            'is_active' => true,
            'position' => 100,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn () => ['visibility' => KbCategory::INTERNAL]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
