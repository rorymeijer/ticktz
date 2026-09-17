<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RequestType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RequestType>
 */
class RequestTypeFactory extends Factory
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
            'visibility' => 'everyone',
            'is_active' => true,
            'position' => 100,
        ];
    }

    public function agentsOnly(): static
    {
        return $this->state(fn () => ['visibility' => 'agents']);
    }

    /**
     * @param  array<int, int>  $organizationIds
     */
    public function forOrganizations(array $organizationIds): static
    {
        return $this->state(fn () => [
            'visibility' => 'organizations',
            'organization_ids' => $organizationIds,
        ]);
    }
}
