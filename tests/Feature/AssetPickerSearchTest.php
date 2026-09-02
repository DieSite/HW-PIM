<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;

/**
 * Eén asset in een map, zoals de DAM ze aanmaakt.
 */
function makeSearchAsset(string $fileName): int
{
    $directoryId = DB::table('dam_directories')->value('id')
        ?? DB::table('dam_directories')->insertGetId([
            'name'       => 'SEARCHTEST-map',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $assetId = DB::table('dam_assets')->insertGetId([
        'file_name'  => $fileName,
        'file_type'  => 'image',
        'file_size'  => 1024,
        'mime_type'  => 'image/jpeg',
        'extension'  => 'jpg',
        'path'       => 'assets/'.$fileName.'.jpg',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('dam_asset_directory')->insert([
        'asset_id'     => $assetId,
        'directory_id' => $directoryId,
    ]);

    return $assetId;
}

/**
 * @return list<int>
 */
function pickerSearch(string $term): array
{
    return collect(
        test()->actingAs(Admin::query()->firstOrFail(), 'admin')
            // De picker geeft alleen JSON terug op een ajax-verzoek.
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('admin.dam.asset_picker.index', ['filters' => ['all' => [$term]]]))
            ->assertOk()
            ->json('records')
    )->pluck('id')->all();
}

afterEach(function () {
    $ids = DB::table('dam_assets')->where('file_name', 'like', 'SEARCHTEST-%')->pluck('id');

    DB::table('dam_asset_directory')->whereIn('asset_id', $ids)->delete();
    DB::table('dam_assets')->whereIn('id', $ids)->delete();
    DB::table('dam_directories')->where('name', 'SEARCHTEST-map')->delete();

    Product::where('sku', 'like', 'SEARCHTEST-%')->delete();
});

it('finds an asset whose file name spreads the search terms out', function () {
    $malta = makeSearchAsset('SEARCHTEST-HW-Huis-Wonen-Malta-1-Beige-4873');
    $other = makeSearchAsset('SEARCHTEST-HW-Huis-Wonen-Napoli-1-Beige-4873');

    $found = pickerSearch('Malta 4873');

    expect($found)->toContain($malta)
        ->and($found)->not->toContain($other);
});

it('still requires every search term to match', function () {
    makeSearchAsset('SEARCHTEST-HW-Huis-Wonen-Malta-1-Beige-4873');

    expect(pickerSearch('Malta 9999'))->toBeEmpty();
});

it('matches a hyphenated file name against a spaced search and the other way around', function () {
    $spaced = makeSearchAsset('SEARCHTEST HW Huis & Wonen Malta 1 Beige 6828');

    expect(pickerSearch('Malta-6828'))->toContain($spaced);
});

it('opens the picker on the product name of the product being edited', function () {
    $product = new Product();
    $product->attribute_family_id = DB::table('attribute_families')->value('id');
    $product->sku = 'SEARCHTEST-1';
    $product->type = 'simple';
    $product->status = 1;
    $product->values = ['common' => ['productnaam' => 'Malta 4873']];
    $product->save();

    $html = view('dam::asset.catalog.products.dynamic-attribute-fields.asset-control', [
        'value'     => [],
        'fieldName' => 'values[common][afbeelding]',
        'field'     => null,
        'productId' => $product->id,
    ])->render();

    expect($html)->toContain('default-search="Malta 4873"');
});
