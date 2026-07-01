<?php

use App\Services\ProductImageEditor\PrimaryImageEditorService;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\AssetResourceMapping;
use Webkul\DAM\Repositories\AssetResourceMappingRepository;
use Webkul\Product\Models\Product;

function makeSourceAsset(): Asset
{
    static $counter = 0;
    $counter++;

    $bytes = (string) app(ImageManager::class)->create(800, 1000)->fill('cc8844')->toJpeg();

    $fileName = "source-rug-{$counter}.jpg";
    $path = "wp-content/Images/{$fileName}";
    Storage::disk('private')->put($path, $bytes);

    return Asset::create([
        'file_name' => $fileName,
        'file_type' => 'image',
        'file_size' => strlen($bytes),
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'path'      => $path,
    ]);
}

beforeEach(function () {
    Storage::fake('private');

    config()->set('product_image_editor.enabled', true);
    config()->set('product_image_editor.primary_attribute', 'afbeelding');
    config()->set('product_image_editor.no_logo_attribute', 'afbeelding_zonder_logo');
});

it('composites the primary image into new assets and rewires the product', function () {
    $source = makeSourceAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $source->id]],
    ]);

    app(PrimaryImageEditorService::class)->apply($product, [
        'source_asset_id' => $source->id,
        'scale'           => 1.0,
        'offset_x'        => 0,
        'offset_y'        => 0,
        'resize'          => true,
        'padding'         => true,
        'icon'            => true,
    ]);

    $values = $product->fresh()->values;
    $primaryId = (int) $values['common']['afbeelding'];
    $noLogoId = (int) $values['common']['afbeelding_zonder_logo'];

    // Both attributes now point at freshly generated assets, not the source.
    expect($primaryId)->not->toBe($source->id)
        ->and($noLogoId)->not->toBe($source->id)
        ->and($primaryId)->not->toBe($noLogoId);

    // The generated assets exist at 917x1094.
    $primary = Asset::find($primaryId);
    expect($primary)->not->toBeNull()
        ->and(Storage::disk('private')->exists($primary->path))->toBeTrue();

    $size = getimagesizefromstring(Storage::disk('private')->get($primary->path));
    expect($size[0])->toBe(917)->and($size[1])->toBe(1094);
});

it('replaces only the main image and preserves the other gallery images', function () {
    $source = makeSourceAsset();
    $otherA = makeSourceAsset();
    $otherB = makeSourceAsset();
    $noLogoMain = makeSourceAsset();
    $noLogoOther = makeSourceAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => [
            'afbeelding'              => implode(',', [$source->id, $otherA->id, $otherB->id]),
            'afbeelding_zonder_logo'  => implode(',', [$noLogoMain->id, $noLogoOther->id]),
        ]],
    ]);

    app(AssetResourceMappingRepository::class)->createProductAssetMappings(
        [$source->id, $otherA->id, $otherB->id],
        $product->id,
        'afbeelding',
    );
    app(AssetResourceMappingRepository::class)->createProductAssetMappings(
        [$noLogoMain->id, $noLogoOther->id],
        $product->id,
        'afbeelding_zonder_logo',
    );

    app(PrimaryImageEditorService::class)->apply($product, [
        'source_asset_id' => $source->id,
        'resize'          => true,
        'padding'         => true,
        'icon'            => true,
    ]);

    $values = $product->fresh()->values['common'];
    $ids = array_map('intval', explode(',', $values['afbeelding']));
    $noLogoIds = array_map('intval', explode(',', $values['afbeelding_zonder_logo']));

    // The main (first) image was swapped for a freshly generated asset...
    expect($ids)->toHaveCount(3)
        ->and($ids[0])->not->toBe($source->id)
        // ...while the other two gallery images survived, in order.
        ->and($ids[1])->toBe($otherA->id)
        ->and($ids[2])->toBe($otherB->id);

    // The same holds for the "zonder logo" attribute: main replaced, rest kept.
    expect($noLogoIds)->toHaveCount(2)
        ->and($noLogoIds[0])->not->toBe($noLogoMain->id)
        ->and($noLogoIds[1])->toBe($noLogoOther->id);

    $mapped = AssetResourceMapping::where('product_id', $product->id)
        ->where('related_field', 'afbeelding')
        ->pluck('dam_asset_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($mapped)->toHaveCount(3)
        ->toContain($otherA->id)
        ->toContain($otherB->id)
        ->toContain($ids[0]);

    $mappedNoLogo = AssetResourceMapping::where('product_id', $product->id)
        ->where('related_field', 'afbeelding_zonder_logo')
        ->pluck('dam_asset_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($mappedNoLogo)->toHaveCount(2)
        ->toContain($noLogoOther->id)
        ->toContain($noLogoIds[0]);
});

it('records the transform and source on the product for re-editing', function () {
    $source = makeSourceAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $source->id]],
    ]);

    app(PrimaryImageEditorService::class)->apply($product, [
        'source_asset_id' => $source->id,
        'scale'           => 1.5,
        'offset_x'        => 12,
        'offset_y'        => -8,
        'resize'          => true,
        'padding'         => true,
        'icon'            => false,
    ]);

    $saved = $product->fresh()->additional['primary_image_editor'];

    expect($saved['source_asset_id'])->toBe($source->id)
        ->and($saved['scale'])->toBe(1.5)
        ->and($saved['icon'])->toBeFalse();
});

it('creates DAM resource mappings for both attributes', function () {
    $source = makeSourceAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $source->id]],
    ]);

    app(PrimaryImageEditorService::class)->apply($product, [
        'source_asset_id' => $source->id,
        'resize'          => true,
        'padding'         => true,
        'icon'            => true,
    ]);

    expect(AssetResourceMapping::where('product_id', $product->id)->where('related_field', 'afbeelding')->count())->toBe(1)
        ->and(AssetResourceMapping::where('product_id', $product->id)->where('related_field', 'afbeelding_zonder_logo')->count())->toBe(1);
});

it('stores the selected shape and falls back to default for unknown shapes', function () {
    $source = makeSourceAsset();

    $known = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $source->id]],
    ]);
    app(PrimaryImageEditorService::class)->apply($known, ['source_asset_id' => $source->id, 'shape' => 'rond']);

    $unknown = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $source->id]],
    ]);
    app(PrimaryImageEditorService::class)->apply($unknown, ['source_asset_id' => $source->id, 'shape' => 'does-not-exist']);

    expect($known->fresh()->additional['primary_image_editor']['shape'])->toBe('rond')
        ->and($unknown->fresh()->additional['primary_image_editor']['shape'])->toBe('rechthoek');
});

it('does nothing when the editor is disabled', function () {
    config()->set('product_image_editor.enabled', false);

    $source = makeSourceAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $source->id]],
    ]);

    app(PrimaryImageEditorService::class)->apply($product, ['source_asset_id' => $source->id]);

    expect((int) $product->fresh()->values['common']['afbeelding'])->toBe($source->id);
});
