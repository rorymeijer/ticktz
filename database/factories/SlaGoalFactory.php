<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SlaGoal;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaGoal>
 */
class SlaGoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sla_policy_id' => SlaPolicy::factory(),
            'metric' => SlaGoal::METRIC_FIRST_RESPONSE,
            'priority_id' => null,
            'request_type_id' => null,
            'target_minutes' => 60,
            'escalations' => null,
            'is_active' => true,
        ];
    }

    public function metric(string $metric, int $minutes): static
    {
        return $this->state(fn () => ['metric' => $metric, 'target_minutes' => $minutes]);
    }

    public function resolution(int $minutes = 480): static
    {
        return $this->metric(SlaGoal::METRIC_RESOLUTION, $minutes);
    }

    /**
     * @param  array<int, array{at: int, actions: array<int, array<string, mixed>>}>  $steps
     */
    public function escalating(array $steps): static
    {
        return $this->state(fn () => ['escalations' => $steps]);
    }
}
