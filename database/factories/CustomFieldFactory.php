<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'key' => Str::snake($label),
            'label' => ucfirst($label),
            'type' => 'text',
            'is_required' => false,
            'is_public' => true,
            'entity' => 'ticket',
            'is_active' => true,
            'position' => 100,
        ];
    }

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     */
    public function select(array $options): static
    {
        return $this->state(fn () => ['type' => 'select', 'options' => $options]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_required' => true]);
    }

    public function internal(): static
    {
        return $this->state(fn () => ['is_public' => false]);
    }
}
