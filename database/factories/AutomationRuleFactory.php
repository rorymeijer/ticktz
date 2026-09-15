<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AutomationRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
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
            'trigger' => AutomationRule::TRIGGER_CREATED,
            'trigger_config' => null,
            'match_type' => 'all',
            'conditions' => [],
            'actions' => [],
            'is_active' => true,
            'position' => 100,
            'stop_processing' => false,
        ];
    }

    public function on(string $trigger): static
    {
        return $this->state(fn () => ['trigger' => $trigger]);
    }

    /**
     * Not `when()`: Laravel's Conditionable trait already owns that name on a
     * factory, and overriding it breaks every other `when()` in the suite.
     *
     * @param  array<int, array<string, mixed>>  $conditions
     */
    public function matching(array $conditions, string $match = 'all'): static
    {
        return $this->state(fn () => ['conditions' => $conditions, 'match_type' => $match]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    public function then(array $actions): static
    {
        return $this->state(fn () => ['actions' => $actions]);
    }

    public function stopping(): static
    {
        return $this->state(fn () => ['stop_processing' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function at(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
