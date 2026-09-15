<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookSubscription>
 */
class WebhookSubscriptionFactory extends Factory
{
    protected $model = WebhookSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'description' => $this->faker->sentence(),
            // example.com is reserved by the IETF and resolves nowhere useful,
            // which is what a test endpoint should do if a fake ever leaks.
            'url' => 'https://example.com/hooks/'.Str::random(8),
            'events' => ['ticket.created'],
            'secret' => Str::random(48),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * @param  array<int, string>  $events
     */
    public function listeningFor(array $events): static
    {
        return $this->state(fn () => ['events' => $events]);
    }

    public function unsigned(): static
    {
        return $this->state(fn () => ['secret' => null]);
    }
}
