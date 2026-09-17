<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks GitHub whether there is a newer release, when it is allowed to.
 *
 * **Off by default, and it stays off until somebody turns it on.** This is the
 * only outbound request Ticktz makes that nobody configured: no mailbox, no
 * webhook, no directory — just the application talking to a third party. The
 * brief this was built to says no telemetry and no third-party trackers, and a
 * version check that phones home on its own would be both, however useful.
 *
 * What GitHub learns when it is on is the instance's IP address and the fact
 * that something asked for a public release list. Nothing about the desk, its
 * size, its version or its people is sent, and there is no token: this is the
 * same unauthenticated request a browser makes.
 *
 * Cached for hours rather than minutes. An update that arrived an hour ago is
 * not urgent, and an unauthenticated caller gets sixty requests an hour from
 * one address — a desk with four administrators refreshing a screen should not
 * be able to spend them.
 */
class ReleaseChecker
{
    private const CACHE_KEY = 'ticktz:updates:latest';

    public function enabled(): bool
    {
        return (bool) config('ticktz.updates.enabled');
    }

    /**
     * The newest release worth offering, or null when there is none, the check
     * is switched off, or GitHub could not be reached.
     */
    public function latest(bool $refresh = false): ?Release
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $payload = Cache::remember(
            self::CACHE_KEY,
            now()->addHours((int) config('ticktz.updates.cache_hours', 6)),
            fn () => $this->fetch(),
        );

        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return Release::fromGitHub($payload);
    }

    public function current(): ?Version
    {
        return Version::parse((string) config('ticktz.version'));
    }

    /**
     * Whether `$release` is something this instance could move to.
     */
    public function isUpgrade(Release $release): bool
    {
        $current = $this->current();

        return $current !== null && $release->version->isNewerThan($current);
    }

    /**
     * The files attached to a release.
     *
     * Read from the cached payload rather than fetched again: the release the
     * screen offered and the release the worker installs have to be the same
     * one, and a second call could return a different answer if somebody
     * published in between.
     *
     * @return array<int, array<string, mixed>>
     */
    public function assetsFor(Release $release): array
    {
        $payload = Cache::get(self::CACHE_KEY);

        if (! is_array($payload)) {
            return [];
        }

        $version = Version::parse((string) ($payload['tag_name'] ?? ''));

        if ($version === null || $version->compare($release->version) !== 0) {
            return [];
        }

        return array_values(array_filter(
            (array) ($payload['assets'] ?? []),
            static fn ($asset): bool => is_array($asset),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(): ?array
    {
        $repository = trim((string) config('ticktz.updates.repository'), '/ ');

        if ($repository === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('ticktz.updates.timeout', 8))
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    // GitHub refuses a request with no user agent. It says
                    // nothing about this instance beyond the product name.
                    'User-Agent' => 'Ticktz',
                ])
                ->get("https://api.github.com/repos/{$repository}/releases", ['per_page' => 20]);

            if (! $response->successful()) {
                return null;
            }

            return $this->newest($response->json() ?: []);
        } catch (Throwable $e) {
            // An instance with no outbound access is a normal instance, not a
            // broken one. The screen says the check could not be made.
            Log::info('Update check failed: {message}', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The newest release in the list.
     *
     * Not simply `/releases/latest`: that endpoint hides pre-releases, which
     * is right by default and wrong for an operator who opted into them, and
     * it orders by publication date where what matters is the version. A
     * release republished after a fix would otherwise outrank a newer one.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<string, mixed>|null
     */
    private function newest(array $payloads): ?array
    {
        $allowPre = (bool) config('ticktz.updates.pre_releases');
        $best = null;
        $bestVersion = null;

        foreach ($payloads as $payload) {
            if (($payload['draft'] ?? false) === true) {
                continue;
            }

            if (($payload['prerelease'] ?? false) === true && ! $allowPre) {
                continue;
            }

            $version = Version::parse((string) ($payload['tag_name'] ?? ''));

            if ($version === null) {
                continue;
            }

            if ($bestVersion === null || $version->isNewerThan($bestVersion)) {
                $best = $payload;
                $bestVersion = $version;
            }
        }

        return $best;
    }
}
