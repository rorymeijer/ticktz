<?php

declare(strict_types=1);

use App\Models\Label;
use App\Models\Ticket;
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

test('an explicit lang parameter wins over the stored preference for that request', function () {
    $user = User::factory()->create(['locale' => 'nl']);

    $this->actingAs($user)->get('/dashboard?lang=en')->assertOk();
    expect(app()->getLocale())->toBe('en');

    // ... but it is not remembered: the profile stays the source of truth.
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

test('statuses, priorities and labels follow the reader language', function () {
    seedServiceDesk();

    app()->setLocale('nl');

    expect(status('open')->translatedName())->toBe('In behandeling')
        ->and(priority('high')->translatedName())->toBe('Hoog')
        ->and(status('open')->toSummaryArray()['name'])->toBe('In behandeling');

    app()->setLocale('en');

    expect(status('open')->translatedName())->toBe('In progress')
        ->and(priority('high')->translatedName())->toBe('High');
});

test('a taxonomy name without a translation falls back to the base name', function () {
    seedServiceDesk();

    $label = Label::factory()->create(['name' => 'Hardware', 'name_translations' => null]);

    app()->setLocale('nl');

    expect($label->translatedName())->toBe('Hardware');
});

test('the portal renders ticket statuses in the requester language', function () {
    seedServiceDesk();

    $requester = User::factory()->requester()->create(['locale' => 'nl']);
    $ticket = Ticket::factory()->forRequester($requester)->create();

    $this->actingAs($requester->fresh())
        ->get("/portal/requests/{$ticket->key}")
        ->assertInertia(fn ($page) => $page->where('request.status.name', 'Nieuw'));
});
