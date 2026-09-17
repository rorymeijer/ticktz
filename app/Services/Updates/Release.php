<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Support\Carbon;

/**
 * One published release, as the update screen needs it.
 *
 * `notes` is Markdown, straight from the release. It is rendered as text and
 * never as markup: it is the one piece of content in the application that
 * comes from outside the instance, and a release note is not worth teaching
 * the application to run somebody else's HTML for.
 */
final readonly class Release
{
    public function __construct(
        public Version $version,
        public string $name,
        public string $notes,
        public string $url,
        public ?Carbon $publishedAt,
        /** The source archive for this tag, which is what a source upgrade installs. */
        public string $archiveUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  a GitHub release object
     */
    public static function fromGitHub(array $payload): ?self
    {
        $version = Version::parse((string) ($payload['tag_name'] ?? ''));

        if ($version === null) {
            return null;
        }

        return new self(
            version: $version,
            name: (string) ($payload['name'] ?: $payload['tag_name']),
            notes: trim((string) ($payload['body'] ?? '')),
            url: (string) ($payload['html_url'] ?? ''),
            publishedAt: ($payload['published_at'] ?? null) ? Carbon::parse($payload['published_at']) : null,
            archiveUrl: (string) ($payload['tarball_url'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => (string) $this->version,
            'name' => $this->name,
            'notes' => $this->notes,
            'url' => $this->url,
            'published_at' => $this->publishedAt?->toIso8601String(),
            'is_pre_release' => $this->version->isPreRelease(),
        ];
    }
}
