<?php

declare(strict_types=1);

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => 'Nienke de Vries',
            'email' => 'nienke@example.org',
            'locale' => 'nl',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $user->refresh();

    expect($user->name)->toBe('Nienke de Vries')
        ->and($user->email)->toBe('nienke@example.org')
        ->and($user->locale)->toBe('nl');
});

test('an unsupported locale is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'klingon',
        ])
        ->assertSessionHasErrors('locale');
});

test('profile requires authentication', function () {
    $this->get('/profile')->assertRedirect('/login');
});
