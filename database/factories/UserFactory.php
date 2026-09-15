<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'username' => Str::slug($name, '.').fake()->unique()->numberBetween(1, 9999),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'locale' => 'en',
            'directory' => 'local',
            'is_active' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function fromDirectory(string $dn = 'cn=test,dc=example,dc=org'): static
    {
        return $this->state(fn () => [
            'directory' => 'ldap',
            'ldap_dn' => $dn,
            'ldap_guid' => (string) Str::uuid(),
            'password' => null,
        ]);
    }

    public function inOrganization(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'organization_id' => ($organization ?? Organization::factory()->create())->getKey(),
        ]);
    }

    /**
     * Attach a role by slug once the user exists. The role must already be
     * seeded — tests call `seed()` or the RoleSeeder first.
     */
    public function withRole(string $role): static
    {
        return $this->afterCreating(function ($user) use ($role): void {
            $model = Role::query()->where('name', $role)->first();

            if ($model) {
                $user->roles()->syncWithoutDetaching([$model->getKey()]);
            }
        });
    }

    public function admin(): static
    {
        return $this->withRole(Role::ADMIN);
    }

    public function agent(): static
    {
        return $this->withRole(Role::AGENT);
    }

    public function requester(): static
    {
        return $this->withRole(Role::REQUESTER);
    }
}
