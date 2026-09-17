<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to the `settings` table.
 *
 * The whole table is small (tens of rows) and read on nearly every request, so
 * it is cached as one entry and invalidated on write rather than queried per
 * key.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'ticktz:settings';

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    /**
     * Defaults used when a key has never been written. Keeping them here means
     * a fresh install behaves sensibly before the seeder has run.
     *
     * @var array<string, array{value: mixed, type: string, group: string, public: bool}>
     */
    public const DEFAULTS = [
        'portal.allow_self_registration' => ['value' => false, 'type' => 'boolean', 'group' => 'portal', 'public' => true],
        'portal.require_email_verification' => ['value' => false, 'type' => 'boolean', 'group' => 'portal', 'public' => false],
        'portal.title' => ['value' => 'Service desk', 'type' => 'string', 'group' => 'branding', 'public' => true],
        'portal.welcome_message' => ['value' => null, 'type' => 'string', 'group' => 'branding', 'public' => true],
        'portal.primary_color' => ['value' => '#4f46e5', 'type' => 'string', 'group' => 'branding', 'public' => true],
        'portal.logo_path' => ['value' => null, 'type' => 'string', 'group' => 'branding', 'public' => true],
        'portal.support_email' => ['value' => null, 'type' => 'string', 'group' => 'portal', 'public' => true],
        'tickets.default_queue_id' => ['value' => null, 'type' => 'integer', 'group' => 'tickets', 'public' => false],
        'tickets.key_prefix' => ['value' => 'SUP', 'type' => 'string', 'group' => 'tickets', 'public' => false],
        'tickets.reopen_window_days' => ['value' => 14, 'type' => 'integer', 'group' => 'tickets', 'public' => false],
        'tickets.auto_close_resolved_after_days' => ['value' => 7, 'type' => 'integer', 'group' => 'tickets', 'public' => false],
        'email.signature_separator' => ['value' => '-- ', 'type' => 'string', 'group' => 'email', 'public' => false],
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key]['value'];
        }

        return $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key, $default);

        return $value === null ? null : (int) $value;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = Cache::rememberForever(self::CACHE_KEY, static function (): array {
            return Setting::query()
                ->get()
                ->mapWithKeys(static fn (Setting $setting) => [$setting->key => $setting->typedValue()])
                ->all();
        });
    }

    /**
     * Settings safe to expose to the browser.
     *
     * @return array<string, mixed>
     */
    public function public(): array
    {
        $publicKeys = array_keys(array_filter(
            self::DEFAULTS,
            static fn (array $meta) => $meta['public']
        ));

        $stored = Setting::query()->where('is_public', true)->pluck('key')->all();
        $keys = array_unique([...$publicKeys, ...$stored]);

        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function set(string $key, mixed $value): void
    {
        $meta = self::DEFAULTS[$key] ?? ['type' => $this->inferType($value), 'group' => 'general', 'public' => false];

        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => Setting::encode($value, $meta['type']),
                'type' => $meta['type'],
                'group' => $meta['group'],
                'is_public' => $meta['public'],
            ],
        );

        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
