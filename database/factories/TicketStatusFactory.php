<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TicketStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketStatus>
 */
class TicketStatusFactory extends Factory
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
            'category' => 'open',
            'color' => '#64748b',
            'pauses_sla' => false,
            'is_public' => true,
            'is_system' => false,
            'position' => 100,
        ];
    }

    public function category(string $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }
}
