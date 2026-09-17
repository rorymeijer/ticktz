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
            // The suffix is not decoration. `unique()` guarantees the job
            // title is unique, but the column stores its *slug*, and two
            // different titles can slug identically ("Sales Manager" and
            // "Sales-Manager"). That produced a unique-constraint violation
            // roughly once in a hundred full-suite runs — the kind of flake
            // that costs an afternoon in CI months later.
            'name' => Str::slug($name).'-'.Str::lower(Str::random(4)),
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
