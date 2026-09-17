<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KbArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KbArticle>
 */
class KbArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);
        $body = '<p>'.fake()->paragraph().'</p>';

        return [
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title),
            'excerpt' => fake()->sentence(),
            'body' => $body,
            // The factory writes both halves itself: tests that build articles
            // directly must still be searchable, and going through the service
            // for every fixture would make them about the service.
            'body_text' => strip_tags($body),
            'status' => KbArticle::PUBLISHED,
            'visibility' => KbArticle::PUBLIC,
            'locale' => null,
            'published_at' => now(),
            'position' => 100,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => KbArticle::DRAFT, 'published_at' => null]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => KbArticle::ARCHIVED]);
    }

    public function internal(): static
    {
        return $this->state(fn () => ['visibility' => KbArticle::INTERNAL]);
    }

    public function inLocale(string $locale): static
    {
        return $this->state(fn () => ['locale' => $locale]);
    }

    public function about(string $title, string $body): static
    {
        return $this->state(fn () => [
            'title' => $title,
            'slug' => Str::slug($title),
            'body' => '<p>'.$body.'</p>',
            'body_text' => $body,
        ]);
    }
}
