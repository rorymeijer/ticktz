<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApprovalRequest;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'subject' => $this->faker->sentence(4),
            'status' => ApprovalRequest::PENDING,
            'current_position' => 0,
        ];
    }
}
