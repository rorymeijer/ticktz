<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => Str::slug($name),
            'display_name' => $name,
            'description' => fake()->sentence(),
            'scope' => 'agent',
            'is_super' => false,
            'is_system' => false,
            'is_default' => false,
            'position' => 100,
        ];
    }

    public function withPermissions(string ...$permissions): static
    {
        return $this->afterCreating(fn ($role) => $role->syncPermissionNames($permissions));
    }
}
