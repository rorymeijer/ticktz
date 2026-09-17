<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Organization;
use App\Services\Assets\AssetImporter;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    seedServiceDesk();
    AssetType::factory()->named('Laptop', 'LAP')->create();
    AssetType::factory()->named('Monitor', 'MON')->create();
});

function csv(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('assets.csv', $contents);
}

/*
|--------------------------------------------------------------------------
| Parsing
|--------------------------------------------------------------------------
*/

it('reads a comma separated file', function (): void {
    $rows = app(AssetImporter::class)->parse("asset_tag,name,type\nLAP-1,A laptop,Laptop\n");

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('A laptop');
});

/**
 * A Dutch Excel export is semicolon-separated. A desk that has to convert its
 * own export before importing it will not import it.
 */
it('reads a semicolon separated file', function (): void {
    $rows = app(AssetImporter::class)->parse("asset_tag;name;type\nLAP-1;A laptop;Laptop\n");

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('A laptop');
});

/**
 * Excel on Windows writes a BOM, which turns the first header into
 * "\xEF\xBB\xBFasset_tag" and matches nothing.
 */
it('survives a byte order mark', function (): void {
    $rows = app(AssetImporter::class)->parse("\xEF\xBB\xBFasset_tag,name,type\nLAP-1,A laptop,Laptop\n");

    expect($rows[0])->toHaveKey('asset_tag')
        ->and($rows[0]['asset_tag'])->toBe('LAP-1');
});

it('accepts headers with different capitalisation and spacing', function (): void {
    $rows = app(AssetImporter::class)->parse("Asset Tag,Name,Type\nLAP-1,A laptop,Laptop\n");

    expect($rows[0]['asset_tag'])->toBe('LAP-1');
});

/*
|--------------------------------------------------------------------------
| The dry run
|--------------------------------------------------------------------------
*/

it('says what it would do without doing it', function (): void {
    $rows = app(AssetImporter::class)->parse(
        "asset_tag,name,type\nLAP-1,First,Laptop\nLAP-2,Second,Laptop\n"
    );

    $result = app(AssetImporter::class)->dryRun($rows);

    expect($result['create'])->toBe(2)
        ->and($result['update'])->toBe(0)
        ->and($result['errors'])->toBe([])
        ->and(Asset::query()->count())->toBe(0);
});

it('counts a row as an update when the tag already exists', function (): void {
    Asset::factory()->create(['asset_tag' => 'LAP-1']);

    $rows = app(AssetImporter::class)->parse("asset_tag,name,type\nLAP-1,First,Laptop\nLAP-2,Second,Laptop\n");

    $result = app(AssetImporter::class)->dryRun($rows);

    expect($result['create'])->toBe(1)->and($result['update'])->toBe(1);
});

/**
 * A file listing the same tag twice performs one create and one update, so the
 * dry run has to say so rather than promising two creations.
 */
it('counts the second mention of a tag in the same file as an update', function (): void {
    $rows = app(AssetImporter::class)->parse("asset_tag,name,type\nLAP-1,First,Laptop\nLAP-1,Again,Laptop\n");

    $result = app(AssetImporter::class)->dryRun($rows);

    expect($result['create'])->toBe(1)->and($result['update'])->toBe(1);
});

it('reports a bad row with its line number and keeps the rest', function (): void {
    $rows = app(AssetImporter::class)->parse(
        "asset_tag,name,type\nLAP-1,First,Laptop\nLAP-2,Second,Spaceship\nLAP-3,Third,Laptop\n"
    );

    $result = app(AssetImporter::class)->dryRun($rows);

    expect($result['create'])->toBe(2)
        ->and($result['errors'])->toHaveCount(1)
        // +2: one for the header, one because humans count from one.
        ->and($result['errors'][0]['line'])->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Importing
|--------------------------------------------------------------------------
*/

it('creates the assets', function (): void {
    $rows = app(AssetImporter::class)->parse(
        "asset_tag,name,type,serial_number,location\nLAP-1,A laptop,Laptop,SN123,Floor 3\n"
    );

    $result = app(AssetImporter::class)->import($rows);

    expect($result['created'])->toBe(1);

    $asset = Asset::query()->where('asset_tag', 'LAP-1')->sole();

    expect($asset->name)->toBe('A laptop')
        ->and($asset->serial_number)->toBe('SN123')
        ->and($asset->location)->toBe('Floor 3')
        ->and($asset->type->name)->toBe('Laptop');
});

/**
 * Importing the same export twice is what actually happens — usually somebody
 * re-running it after fixing three rows.
 */
it('updates rather than duplicating on a second run', function (): void {
    $importer = app(AssetImporter::class);
    $rows = $importer->parse("asset_tag,name,type\nLAP-1,First name,Laptop\n");

    $importer->import($rows);
    $importer->import($importer->parse("asset_tag,name,type\nLAP-1,Corrected name,Laptop\n"));

    expect(Asset::query()->count())->toBe(1)
        ->and(Asset::query()->sole()->name)->toBe('Corrected name');
});

/**
 * A CSV with no `location` column must not blank the locations somebody typed
 * in by hand.
 */
it('leaves columns the file does not carry alone', function (): void {
    $importer = app(AssetImporter::class);

    $importer->import($importer->parse("asset_tag,name,type,location\nLAP-1,A laptop,Laptop,Floor 3\n"));
    $importer->import($importer->parse("asset_tag,name,type\nLAP-1,A laptop,Laptop\n"));

    expect(Asset::query()->sole()->location)->toBe('Floor 3');
});

it('generates a tag for a row that has none', function (): void {
    $importer = app(AssetImporter::class);

    $importer->import($importer->parse("name,type\nA laptop,Laptop\n"));

    expect(Asset::query()->sole()->asset_tag)->toBe('LAP-0001');
});

it('resolves an owner by e-mail address', function (): void {
    $user = makeRequester(['email' => 'sanne@example.test']);
    $importer = app(AssetImporter::class);

    $importer->import($importer->parse("asset_tag,name,type,assigned_to\nLAP-1,A laptop,Laptop,sanne@example.test\n"));

    expect(Asset::query()->sole()->assigned_to)->toBe($user->getKey());
});

it('resolves an organisation by name', function (): void {
    $organization = Organization::factory()->create(['name' => 'Gemeente Zandvliet']);
    $importer = app(AssetImporter::class);

    $importer->import($importer->parse(
        "asset_tag,name,type,organization\nLAP-1,A laptop,Laptop,Gemeente Zandvliet\n"
    ));

    expect(Asset::query()->sole()->organization_id)->toBe($organization->getKey());
});

/**
 * Dutch spreadsheets write 31-12-2026 and everything else writes 2026-12-31.
 */
it('reads dates in either order', function (string $written, string $expected): void {
    $importer = app(AssetImporter::class);

    $importer->import($importer->parse("asset_tag,name,type,warranty_ends_at\nLAP-1,A laptop,Laptop,{$written}\n"));

    expect(Asset::query()->sole()->warranty_ends_at->toDateString())->toBe($expected);
})->with([
    ['2026-12-31', '2026-12-31'],
    ['31-12-2026', '2026-12-31'],
    ['31/12/2026', '2026-12-31'],
]);

it('reports a date it cannot read', function (): void {
    $importer = app(AssetImporter::class);
    $rows = $importer->parse("asset_tag,name,type,purchased_at\nLAP-1,A laptop,Laptop,last Tuesday\n");

    $result = $importer->import($rows);

    expect($result['created'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1);
});

/**
 * "€ 1.299,00" and "1299.00" are the same number written by two spreadsheets.
 */
it('reads money in either notation', function (string $written, int $cents): void {
    $importer = app(AssetImporter::class);

    $importer->import($importer->parse("asset_tag,name,type,purchase_cost\nLAP-1,A laptop,Laptop,\"{$written}\"\n"));

    expect(Asset::query()->sole()->purchase_cost)->toBe($cents);
})->with([
    ['1299.00', 129900],
    ['1.299,00', 129900],
    ['€ 1.299,00', 129900],
    ['1299', 129900],
]);

it('carries on past a bad row', function (): void {
    $importer = app(AssetImporter::class);
    $rows = $importer->parse(
        "asset_tag,name,type\nLAP-1,First,Laptop\nLAP-2,,Laptop\nLAP-3,Third,Laptop\n"
    );

    $result = $importer->import($rows);

    expect($result['created'])->toBe(2)
        ->and($result['errors'])->toHaveCount(1)
        ->and(Asset::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Over the wire
|--------------------------------------------------------------------------
*/

it('previews an upload without writing anything', function (): void {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->post('/admin/assets/import/preview', [
            'file' => csv("asset_tag,name,type\nLAP-1,A laptop,Laptop\n"),
        ])
        ->assertRedirect()
        ->assertSessionHas('assets.import.result');

    expect(Asset::query()->count())->toBe(0);
});

it('imports on the second, explicit request', function (): void {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/admin/assets/import/preview', [
        'file' => csv("asset_tag,name,type\nLAP-1,A laptop,Laptop\n"),
    ]);

    $this->actingAs($admin)->post('/admin/assets/import')->assertRedirect();

    expect(Asset::query()->count())->toBe(1);
});

it('refuses the importer without the permission', function (): void {
    $agent = makeUserWithPermissions('assets.view', 'assets.manage');

    $this->actingAs($agent)
        ->post('/admin/assets/import/preview', ['file' => csv("name,type\nA laptop,Laptop\n")])
        ->assertForbidden();
});
