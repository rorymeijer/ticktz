<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingsRepository;
use Illuminate\Database\Seeder;

/**
 * Materialises the default settings so an administrator sees real values in
 * the settings screen rather than empty fields.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SettingsRepository::DEFAULTS as $key => $meta) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'group' => $meta['group'],
                    'type' => $meta['type'],
                    'is_public' => $meta['public'],
                    'value' => Setting::encode($meta['value'], $meta['type']),
                ],
            );
        }

        app(SettingsRepository::class)->flush();
    }
}
