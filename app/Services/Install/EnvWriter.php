<?php

declare(strict_types=1);

namespace App\Services\Install;

use RuntimeException;

/**
 * Edits `.env` in place without losing anything that was already in it.
 *
 * Rewriting the file from a template would be simpler and wrong: a `.env` on a
 * running instance holds comments, ordering and keys this application knows
 * nothing about — a proxy setting, an APM token, whatever the operator's
 * platform injected. So the file is edited line by line: a key that is present
 * gets its value replaced, a key that is absent is appended, and everything
 * else is left exactly as it was.
 *
 * Values are quoted when they need it, which is more often than it looks: a
 * password containing a `#` becomes a comment halfway through, and one
 * containing a space silently truncates. Both produce an instance that cannot
 * reach its own database with an error that blames the credentials rather than
 * the file.
 */
class EnvWriter
{
    public function __construct(private readonly string $path) {}

    public static function forApplication(): self
    {
        return new self(base_path('.env'));
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     *
     * @throws RuntimeException when the file cannot be written
     */
    public function write(array $values): void
    {
        if (! is_file($this->path)) {
            // A fresh clone that skipped `cp .env.example .env`. Starting from
            // the example keeps the comments, which are most of that file's
            // value to whoever reads it next.
            $example = base_path('.env.example');

            if (! is_file($example) || @copy($example, $this->path) === false) {
                throw new RuntimeException("Cannot create {$this->path}.");
            }
        }

        if (! is_writable($this->path)) {
            throw new RuntimeException("{$this->path} is not writable.");
        }

        $lines = preg_split('/\R/', (string) file_get_contents($this->path)) ?: [];
        $remaining = $values;

        foreach ($lines as $index => $line) {
            foreach ($values as $key => $value) {
                // Only an assignment at the start of a line, so a key that
                // happens to appear inside a comment is left alone.
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line) === 1) {
                    $lines[$index] = $key.'='.$this->format($value);
                    unset($remaining[$key]);

                    break;
                }
            }
        }

        if ($remaining !== []) {
            if (end($lines) !== '') {
                $lines[] = '';
            }

            $lines[] = '# Added by the Ticktz installer on '.now()->toDateString();

            foreach ($remaining as $key => $value) {
                $lines[] = $key.'='.$this->format($value);
            }
        }

        $written = @file_put_contents($this->path, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX);

        if ($written === false) {
            throw new RuntimeException("Failed to write {$this->path}.");
        }

        // The file holds the database password and the application key. It has
        // no business being world-readable, and a container that runs as a
        // non-root user still only needs the owner bit.
        @chmod($this->path, 0600);
    }

    /**
     * @return array<string, string>
     */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $values = [];

        foreach (preg_split('/\R/', (string) file_get_contents($this->path)) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $matches) === 1) {
                $values[$matches[1]] = trim($matches[2], "\"' ");
            }
        }

        return $values;
    }

    private function format(string|int|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || $value === '') {
            return '';
        }

        $value = (string) $value;

        // Anything that would change meaning unquoted. `#` starts a comment,
        // whitespace truncates, and the rest are shell-significant in the
        // tools that read this file.
        if (preg_match('/[\s#"\'\\\\$`&|<>()]/', $value) === 1) {
            return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
        }

        return $value;
    }
}
