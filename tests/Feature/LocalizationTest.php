<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\UiTranslations;

test('every supported locale ships the same translation keys', function () {
    $locales = array_keys(config('ticktz.locales'));
    $reference = UiTranslations::for(array_shift($locales));

    expect($reference)->not->toBeEmpty();

    foreach ($locales as $locale) {
        $missing = array_diff_key($reference, UiTranslations::for($locale));
        $extra = array_diff_key(UiTranslations::for($locale), $reference);

        expect($missing)->toBe([], "Locale [{$locale}] is missing keys: ".implode(', ', array_keys($missing)));
        expect($extra)->toBe([], "Locale [{$locale}] has unknown keys: ".implode(', ', array_keys($extra)));
    }
});

test('the ui language follows the signed-in user preference', function () {
    $user = User::factory()->create(['locale' => 'nl']);

    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(app()->getLocale())->toBe('nl');
});

test('a guest can switch language with the lang query parameter', function () {
    $this->get('/?lang=nl')->assertOk();

    expect(app()->getLocale())->toBe('nl');
});

test('an unsupported language falls back to the default', function () {
    $this->get('/?lang=klingon')->assertOk();

    expect(app()->getLocale())->toBe(config('app.locale'));
});
