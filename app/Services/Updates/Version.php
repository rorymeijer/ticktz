<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Stringable;

/**
 * A semantic version, and the questions worth asking about two of them.
 *
 * Its own type rather than `version_compare()` at every call site, because the
 * question an upgrade screen asks is not "is this string larger" but "is this
 * newer, and does getting there cross a line a person should know about". A
 * patch and a major are different events, and the difference is the whole
 * reason the screen exists.
 */
final readonly class Version implements Stringable
{
    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        /** A pre-release suffix: the `beta.1` of `1.1.0-beta.1`. */
        public ?string $pre = null,
    ) {}

    /**
     * Parse `v1.2.3`, `1.2.3` or `1.2.3-beta.1`. Anything else is not a
     * version, and saying so is better than guessing at one.
     */
    public static function parse(?string $value): ?self
    {
        $value = trim((string) $value);
        $value = ltrim($value, 'vV');

        // Build metadata (`+abc`) is explicitly not part of precedence in
        // semver, so it is dropped rather than compared.
        $value = explode('+', $value, 2)[0];

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$/', $value, $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3], $m[4] ?? null);
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    /**
     * -1, 0 or 1, ordered the way semver says: a pre-release is *older* than
     * the release it leads to, so 1.1.0-beta.1 comes before 1.1.0.
     */
    public function compare(self $other): int
    {
        $ordering = [$this->major <=> $other->major, $this->minor <=> $other->minor, $this->patch <=> $other->patch];

        foreach ($ordering as $result) {
            if ($result !== 0) {
                return $result;
            }
        }

        return match (true) {
            $this->pre === $other->pre => 0,
            $this->pre === null => 1,
            $other->pre === null => -1,
            default => strnatcmp($this->pre, $other->pre),
        };
    }

    public function isPreRelease(): bool
    {
        return $this->pre !== null;
    }

    /**
     * How big a step this is from `$from`.
     *
     * What the screen leads with. Within a major, an upgrade never needs a
     * manual step — that is the promise the changelog makes — and across one it
     * sometimes does, so a major is the case where somebody has to read
     * something before pressing anything.
     */
    public function stepFrom(self $from): string
    {
        return match (true) {
            $this->major !== $from->major => 'major',
            $this->minor !== $from->minor => 'minor',
            default => 'patch',
        };
    }

    public function __toString(): string
    {
        return sprintf('%d.%d.%d%s', $this->major, $this->minor, $this->patch, $this->pre ? '-'.$this->pre : '');
    }
}
