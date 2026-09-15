<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Priority;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Tickets\TicketNumberGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workflow = Workflow::query()->where('is_default', true)->first() ?? Workflow::factory()->create();
        $status = TicketStatus::query()->where('slug', 'new')->first() ?? TicketStatus::factory()->create();
        $priority = Priority::default() ?? Priority::factory()->create();

        return [
            'subject' => ucfirst(fake()->sentence(5)),
            'description' => fake()->paragraphs(2, true),
            'status_id' => $status->getKey(),
            'priority_id' => $priority->getKey(),
            'workflow_id' => $workflow->getKey(),
            'requester_id' => User::factory(),
            'source' => 'agent',
            'last_activity_at' => now(),
        ];
    }

    public function configure(): static
    {
        // Keys normally come from TicketService; the factory mints its own so
        // a test can create a ticket without going through the service. It
        // uses the same locked sequence, because factory()->count(3) makes all
        // three models before saving any of them — a max(number)+1 lookup here
        // would hand out the same key three times.
        return $this->afterMaking(function (Ticket $ticket): void {
            if ($ticket->key) {
                return;
            }

            $sequence = app(TicketNumberGenerator::class)->next('TST');

            $ticket->forceFill([
                'prefix' => $sequence['prefix'],
                'number' => $sequence['number'],
                'key' => $sequence['key'],
            ]);
        });
    }

    public function withStatus(string $slug): static
    {
        return $this->state(fn () => [
            'status_id' => TicketStatus::query()->where('slug', $slug)->value('id'),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assignee_id' => $user->getKey()]);
    }

    public function forRequester(User $user): static
    {
        return $this->state(fn () => [
            'requester_id' => $user->getKey(),
            'organization_id' => $user->organization_id,
        ]);
    }
}
