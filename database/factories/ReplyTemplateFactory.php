<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReplyTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReplyTemplate>
 */
class ReplyTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'body' => '<p>Hi {{ requester.first_name }}, we are looking at {{ ticket.key }}.</p>',
            'team_id' => null,
            'locale' => null,
            'is_internal' => false,
            'is_active' => true,
            'position' => 100,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (): array => ['is_internal' => true]);
    }
}
