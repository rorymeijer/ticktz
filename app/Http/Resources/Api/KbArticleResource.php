<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\KbArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A knowledge base article.
 *
 * The body is sent only from the single-article endpoint. A list of fifty
 * articles with fifty full bodies is a megabyte of response for a page that
 * shows titles — and callers that want the text ask for one article at a time
 * anyway.
 *
 * @mixin KbArticle
 */
class KbArticleResource extends JsonResource
{
    public function __construct($resource, private readonly bool $withBody = false)
    {
        parent::__construct($resource);
    }

    public static function full(KbArticle $article): self
    {
        return new self($article, withBody: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'locale' => $this->locale,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->translatedName(),
                'slug' => $this->category->slug,
            ] : null),
            'body' => $this->when($this->withBody, fn () => $this->body),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
