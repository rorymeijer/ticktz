<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Collects the PHP translation files for a locale into one flat dictionary
 * that is shared with the React front-end through Inertia.
 *
 * Keys keep their Laravel shape (`group.nested.key`) so the same string can be
 * used from PHP (`__('tickets.title')`) and from TypeScript (`t('tickets.title')`).
 */
final class UiTranslations
{
    /**
     * Translation groups exposed to the browser. Anything not listed here
     * stays server-side (validation messages are resolved before they are
     * sent, so they never need shipping).
     */
    private const GROUPS = [
        'common',
        'nav',
        'auth',
        'profile',
        'dashboard',
        'tickets',
        'portal',
        'admin',
        'sla',
        'automation',
        'kb',
        'assets',
        'approvals',
        'reports',
        'api',
        'install',
        'editor',
        'validation_ui',
    ];

    /**
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        $key = "ticktz:translations:{$locale}:".config('ticktz.version');

        $resolve = static function () use ($locale): array {
            $flat = [];

            foreach (self::GROUPS as $group) {
                $path = lang_path("{$locale}/{$group}.php");

                if (! File::exists($path)) {
                    continue;
                }

                $messages = require $path;

                if (is_array($messages)) {
                    $flat += self::flatten($messages, $group);
                }
            }

            return $flat;
        };

        // Caching translations is pointless (and harmful) while a developer is
        // editing language files.
        if (app()->isLocal() || app()->runningUnitTests()) {
            return $resolve();
        }

        return Cache::remember($key, now()->addDay(), $resolve);
    }

    /**
     * @param  array<array-key, mixed>  $messages
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $composed = "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += self::flatten($value, $composed);

                continue;
            }

            $flat[$composed] = (string) $value;
        }

        return $flat;
    }
}
