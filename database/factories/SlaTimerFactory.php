<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SlaGoal;
use App\Models\SlaTimer;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaTimer>
 */
class SlaTimerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'metric' => SlaGoal::METRIC_FIRST_RESPONSE,
            'target_minutes' => 60,
            'started_at' => now(),
            'due_at' => now()->addHour(),
            'paused_minutes' => 0,
            'status' => SlaTimer::STATUS_RUNNING,
        ];
    }

    public function overdue(int $minutesAgo = 30): static
    {
        return $this->state(fn () => [
            'started_at' => now()->subMinutes($minutesAgo + 60),
            'due_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
