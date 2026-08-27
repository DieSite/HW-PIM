<?php

use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;

/**
 * Eén asset in een map, zoals de DAM ze aanmaakt.
 */
function makePickerAsset(string $fileName, string $extension): int
{
    $directoryId = DB::table('dam_directories')->value('id')
        ?? DB::table('dam_directories')->insertGetId([
            'name'       => 'WEBPTEST-map',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $assetId = DB::table('dam_assets')->insertGetId([
        'file_name'  => $fileName,
        'file_type'  => 'image',
        'file_size'  => 1024,
        'mime_type'  => 'image/'.$extension,
        'extension'  => $extension,
        'path'       => 'assets/'.$fileName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('dam_asset_directory')->insert([
        'asset_id'     => $assetId,
        'directory_id' => $directoryId,
    ]);

    return $assetId;
}

afterEach(function () {
    $ids = DB::table('dam_assets')->where('file_name', 'like', 'WEBPTEST-%')->pluck('id');

    DB::table('dam_asset_directory')->whereIn('asset_id', $ids)->delete();
    DB::table('dam_assets')->whereIn('id', $ids)->delete();
    DB::table('dam_directories')->where('name', 'WEBPTEST-map')->delete();
});

it('offers webp assets in the picker, like every other image', function () {
    $webp = makePickerAsset('WEBPTEST-kleed.webp', 'webp');
    $jpg = makePickerAsset('WEBPTEST-kleed.jpg', 'jpg');

    $ids = collect(
        test()->actingAs(Admin::query()->firstOrFail(), 'admin')
            // De picker geeft alleen JSON terug op een ajax-verzoek.
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('admin.dam.asset_picker.index'))
            ->assertOk()
            ->json('records')
    )->pluck('id');

    expect($ids)->toContain($webp)
        ->and($ids)->toContain($jpg);
});
