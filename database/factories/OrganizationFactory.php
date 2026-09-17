<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'email_domains' => [Str::slug($name).'.example'],
            'contact_email' => fake()->companyEmail(),
            'is_active' => true,
            'shared_ticket_visibility' => false,
        ];
    }

    public function withSharedVisibility(): static
    {
        return $this->state(fn () => ['shared_ticket_visibility' => true]);
    }
}
