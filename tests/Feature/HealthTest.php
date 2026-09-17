<?php

declare(strict_types=1);

test('the health endpoint reports every dependency', function () {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.ok', true)
        ->assertJsonPath('checks.cache.ok', true)
        ->assertJsonPath('checks.queue.ok', true)
        ->assertJsonStructure(['status', 'application', 'version', 'time', 'checks']);
});

test('the health endpoint does not require authentication', function () {
    $this->get('/health')->assertOk();
});
