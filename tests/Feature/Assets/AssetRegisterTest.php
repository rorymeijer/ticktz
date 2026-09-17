<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\AssetType;
use App\Models\Organization;
use App\Models\Ticket;
use App\Services\Assets\AssetService;

beforeEach(function (): void {
    seedServiceDesk();
});

/*
|--------------------------------------------------------------------------
| The acceptance criterion
|--------------------------------------------------------------------------
|
| From the brief: "een asset is aan een ticket gekoppeld en vindbaar; relaties
| tussen assets zijn te bekijken."
|
*/

it('links an asset to a ticket and finds it again', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'tickets.view.all', 'tickets.update', 'assets.view');
    $type = AssetType::factory()->named('Laptop', 'LAP')->create();
    $asset = Asset::factory()->for($type, 'type')->create([
        'asset_tag' => 'LAP-0042',
        'name' => 'Dell Latitude 5440',
        'serial_number' => 'SN7781234',
    ]);
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)
        ->post("/agent/tickets/{$ticket->key}/assets", ['asset_id' => $asset->id])
        ->assertRedirect();

    expect($ticket->fresh()->assets()->count())->toBe(1);

    // Findable: by tag, by serial, and by what it is called.
    foreach (['LAP-0042', 'SN7781234', 'Latitude'] as $term) {
        $response = $this->actingAs($agent)->get('/agent/assets?q='.urlencode($term))->assertOk();

        expect(collect($response->viewData('page')['props']['assets']['data'])->pluck('asset_tag'))
            ->toContain('LAP-0042');
    }

    // And it shows on the ticket it was attached to.
    $response = $this->actingAs($agent)->get("/agent/tickets/{$ticket->key}")->assertOk();

    expect(collect($response->viewData('page')['props']['assets'])->pluck('asset_tag'))
        ->toContain('LAP-0042');
});

it('shows the relations between two assets from both sides', function (): void {
    $agent = makeUserWithPermissions('assets.view', 'assets.manage');
    $laptop = Asset::factory()->create(['asset_tag' => 'LAP-0001', 'name' => 'Laptop']);
    $dock = Asset::factory()->create(['asset_tag' => 'DCK-0001', 'name' => 'Dock']);

    $this->actingAs($agent)
        ->post("/agent/assets/{$dock->asset_tag}/relations", [
            'related_asset_id' => $laptop->id,
            'type' => 'connected_to',
        ])
        ->assertRedirect();

    // From the dock: it is connected to the laptop.
    $response = $this->actingAs($agent)->get("/agent/assets/{$dock->asset_tag}")->assertOk();
    $relations = $response->viewData('page')['props']['asset']['relations'];

    expect($relations)->toHaveCount(1)
        ->and($relations[0]['asset']['asset_tag'])->toBe('LAP-0001')
        ->and($relations[0]['direction'])->toBe('out');

    // And from the laptop, reading the same single row the other way.
    $response = $this->actingAs($agent)->get("/agent/assets/{$laptop->asset_tag}")->assertOk();
    $relations = $response->viewData('page')['props']['asset']['relations'];

    expect($relations)->toHaveCount(1)
        ->and($relations[0]['asset']['asset_tag'])->toBe('DCK-0001')
        ->and($relations[0]['direction'])->toBe('in');
});

// -----------------------------------------------------------------
// Relations
// -----------------------------------------------------------------

it('inverts a directional relation when read from the other side', function (): void {
    $server = Asset::factory()->create();
    $app = Asset::factory()->create();

    app(AssetService::class)->relate($app, $server, 'installed_on');

    $app->load('links.relatedAsset.type', 'inverseLinks.asset.type');
    $server->load('links.relatedAsset.type', 'inverseLinks.asset.type');

    expect($app->relatedAssets()[0]['type'])->toBe('installed_on')
        // "installed on" read from the server's side is "hosts".
        ->and($server->relatedAssets()[0]['type'])->toBe('hosts');
});

it('refuses to relate an asset to itself', function (): void {
    $asset = Asset::factory()->create();

    expect(fn () => app(AssetService::class)->relate($asset, $asset, 'connected_to'))
        ->toThrow(RuntimeException::class);
});

/**
 * The same pair in the other direction is the same relationship. Left
 * unchecked, a laptop ends up both installed on and hosting the same dock.
 */
it('does not store the mirror of a relation that already exists', function (): void {
    $first = Asset::factory()->create();
    $second = Asset::factory()->create();

    app(AssetService::class)->relate($first, $second, 'connected_to');
    app(AssetService::class)->relate($second, $first, 'connected_to');

    expect(AssetRelation::query()->count())->toBe(1);
});

it('does not store the same relation twice', function (): void {
    $first = Asset::factory()->create();
    $second = Asset::factory()->create();

    app(AssetService::class)->relate($first, $second, 'depends_on');
    app(AssetService::class)->relate($first, $second, 'depends_on');

    expect(AssetRelation::query()->count())->toBe(1);
});

it('allows two different relations between the same pair', function (): void {
    $first = Asset::factory()->create();
    $second = Asset::factory()->create();

    app(AssetService::class)->relate($first, $second, 'depends_on');
    app(AssetService::class)->relate($first, $second, 'backs_up');

    expect(AssetRelation::query()->count())->toBe(2);
});

it('refuses to remove a relation reached from an unrelated asset', function (): void {
    $agent = makeUserWithPermissions('assets.view', 'assets.manage');
    $first = Asset::factory()->create();
    $second = Asset::factory()->create();
    $bystander = Asset::factory()->create();

    $relation = app(AssetService::class)->relate($first, $second, 'connected_to');

    $this->actingAs($agent)
        ->delete("/agent/assets/{$bystander->asset_tag}/relations/{$relation->id}")
        ->assertNotFound();

    expect(AssetRelation::query()->count())->toBe(1);
});

// -----------------------------------------------------------------
// Tags
// -----------------------------------------------------------------

it('generates a tag from the type prefix', function (): void {
    $type = AssetType::factory()->named('Laptop', 'LAP')->create();

    $asset = app(AssetService::class)->create(['type' => $type, 'name' => 'A laptop']);

    expect($asset->asset_tag)->toBe('LAP-0001');

    $second = app(AssetService::class)->create(['type' => $type, 'name' => 'Another laptop']);

    expect($second->asset_tag)->toBe('LAP-0002');
});

/**
 * Assets arrive by import carrying tags somebody else allocated, so the next
 * tag is derived from the data rather than from a counter that would have
 * drifted out of step on the first import.
 */
it('continues the sequence past tags an import brought in', function (): void {
    $type = AssetType::factory()->named('Laptop', 'LAP')->create();

    Asset::factory()->for($type, 'type')->create(['asset_tag' => 'LAP-0117']);

    $asset = app(AssetService::class)->create(['type' => $type, 'name' => 'Next one']);

    expect($asset->asset_tag)->toBe('LAP-0118');
});

it('never reuses a tag a deleted asset still holds', function (): void {
    $type = AssetType::factory()->named('Laptop', 'LAP')->create();

    $first = app(AssetService::class)->create(['type' => $type, 'name' => 'Gone']);
    app(AssetService::class)->delete($first);

    $second = app(AssetService::class)->create(['type' => $type, 'name' => 'New']);

    expect($second->asset_tag)->not->toBe($first->asset_tag);
});

it('keeps a tag somebody supplied', function (): void {
    $type = AssetType::factory()->named('Laptop', 'LAP')->create();

    $asset = app(AssetService::class)->create([
        'type' => $type,
        'name' => 'From the sticker',
        'asset_tag' => 'ZV-99213',
    ]);

    expect($asset->asset_tag)->toBe('ZV-99213');
});

// -----------------------------------------------------------------
// Assigning
// -----------------------------------------------------------------

it('moves an asset out of stock when it is handed to somebody', function (): void {
    $user = makeRequester();
    $asset = Asset::factory()->create(['status' => Asset::IN_STOCK]);

    app(AssetService::class)->assign($asset, $user);

    expect($asset->refresh()->status)->toBe(Asset::IN_USE)
        ->and($asset->assigned_to)->toBe($user->getKey());
});

/**
 * "In repair" and "retired" are statements somebody made deliberately;
 * assigning must not quietly overwrite them.
 */
it('does not overwrite a deliberate status when assigning', function (): void {
    $user = makeRequester();
    $asset = Asset::factory()->create(['status' => Asset::IN_REPAIR]);

    app(AssetService::class)->assign($asset, $user);

    expect($asset->refresh()->status)->toBe(Asset::IN_REPAIR)
        ->and($asset->assigned_to)->toBe($user->getKey());
});

it('returns an asset to stock when it is taken back', function (): void {
    $user = makeRequester();
    $asset = Asset::factory()->assignedTo($user)->create();

    app(AssetService::class)->assign($asset, null);

    expect($asset->refresh()->status)->toBe(Asset::IN_STOCK)
        ->and($asset->assigned_to)->toBeNull();
});

// -----------------------------------------------------------------
// Permissions
// -----------------------------------------------------------------

it('refuses the register without the permission', function (): void {
    $agent = makeUserWithPermissions('tickets.view');

    $this->actingAs($agent)->get('/agent/assets')->assertForbidden();
});

it('refuses editing with only the read permission', function (): void {
    $agent = makeUserWithPermissions('tickets.view', 'assets.view');
    $asset = Asset::factory()->create();

    $this->actingAs($agent)
        ->put("/agent/assets/{$asset->asset_tag}", [
            'asset_type_id' => $asset->asset_type_id,
            'name' => 'Renamed',
            'status' => Asset::IN_USE,
        ])
        ->assertForbidden();

    expect($asset->refresh()->name)->not->toBe('Renamed');
});

// -----------------------------------------------------------------
// What a requester may see
// -----------------------------------------------------------------

it('shows a requester their own equipment', function (): void {
    $user = makeRequester();
    $mine = Asset::factory()->assignedTo($user)->create(['asset_tag' => 'LAP-0001']);
    Asset::factory()->create(['asset_tag' => 'LAP-0002']);

    $response = $this->actingAs($user)->get('/portal/equipment')->assertOk();

    expect(collect($response->viewData('page')['props']['assets']['data'])->pluck('asset_tag'))
        ->toContain('LAP-0001')
        ->not->toContain('LAP-0002');
});

/**
 * The portal payload is built by naming what may be shown, not by removing a
 * few keys from the agent one — a payload built by subtraction leaks the next
 * field somebody adds.
 */
it('never sends purchase cost or internal notes to the portal', function (): void {
    $user = makeRequester();
    Asset::factory()->assignedTo($user)->create([
        'purchase_cost' => 129900,
        'notes' => 'Bought from the grey list supplier, do not repeat',
    ]);

    $response = $this->actingAs($user)->get('/portal/equipment')->assertOk();

    $payload = $response->viewData('page')['props']['assets']['data'][0];

    expect($payload)->not->toHaveKey('purchase_cost')
        ->and($payload)->not->toHaveKey('notes')
        ->and(json_encode($payload))->not->toContain('grey list');
});

it('hides retired equipment from the portal', function (): void {
    $user = makeRequester();
    Asset::factory()->assignedTo($user)->retired()->create(['asset_tag' => 'LAP-0009']);

    $response = $this->actingAs($user)->get('/portal/equipment')->assertOk();

    expect($response->viewData('page')['props']['assets']['data'])->toBe([]);
});

it('shows a colleague equipment only when the organisation shares tickets', function (bool $shared, int $expected): void {
    $organization = Organization::factory()->create(['shared_ticket_visibility' => $shared]);
    $user = makeRequester(['organization_id' => $organization->getKey()]);
    $colleague = makeRequester(['organization_id' => $organization->getKey()]);

    Asset::factory()->assignedTo($colleague)->create();

    $response = $this->actingAs($user)->get('/portal/equipment')->assertOk();

    expect($response->viewData('page')['props']['assets']['data'])->toHaveCount($expected);
})->with([
    'shared' => [true, 1],
    'separate' => [false, 0],
]);

it('never shows the desk own stock on the portal', function (): void {
    $user = makeRequester();
    // No owner, no organisation: a laptop in a cupboard.
    Asset::factory()->create();

    $response = $this->actingAs($user)->get('/portal/equipment')->assertOk();

    expect($response->viewData('page')['props']['assets']['data'])->toBe([]);
});
