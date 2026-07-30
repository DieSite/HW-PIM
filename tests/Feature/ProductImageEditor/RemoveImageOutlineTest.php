<?php

use App\Services\ProductImageEditor\ImageCompositor;
use App\Services\ProductImageEditor\PrimaryImageEditorService;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Webkul\DAM\Models\Asset;
use Webkul\Product\Models\Product;

/**
 * Store raw image bytes as a DAM asset on the fake private disk.
 */
function outlineTestAsset(string $bytes): Asset
{
    static $counter = 0;
    $counter++;

    $fileName = "outline-test-{$counter}.jpg";
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

/**
 * A finished "rond" composite as the catalog stores it, with or without the
 * black outline baked in.
 */
function outlineTestComposite(bool $outline): string
{
    return (string) app(ImageCompositor::class)->render(
        (string) app(ImageManager::class)->create(800, 800)->fill('cc8844')->toPng(),
        [
            'shape'   => 'rond',
            'rect'    => config('product_image_editor.shapes.rond.rect'),
            'outline' => $outline,
            'resize'  => true,
            'padding' => true,
        ],
        null,
        false,
    )->toJpeg(90);
}

function outlineTestPrimaryBytes(Product $product): string
{
    $id = (int) explode(',', (string) $product->fresh()->values['common']['afbeelding'])[0];

    return Storage::disk('private')->get(Asset::findOrFail($id)->path);
}

beforeEach(function () {
    Storage::fake('private');

    config()->set('product_image_editor.enabled', true);
    config()->set('product_image_editor.primary_attribute', 'afbeelding');
    config()->set('product_image_editor.no_logo_attribute', 'afbeelding_zonder_logo');
});

it('re-composites from the recorded transform and source asset', function () {
    $source = outlineTestAsset((string) app(ImageManager::class)->create(800, 800)->fill('cc8844')->toJpeg());

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $source->id]],
    ]);

    app(PrimaryImageEditorService::class)->apply($product, [
        'source_asset_id' => $source->id,
        'shape'           => 'rond',
        'outline'         => true,
        'icon'            => false,
    ]);
    $product->refresh();

    $outlinedId = (int) $product->values['common']['afbeelding'];
    expect(app(ImageCompositor::class)->detectShapeOutline(outlineTestPrimaryBytes($product), 'rond'))->toBeTrue();

    $result = app(PrimaryImageEditorService::class)->removeOutline($product);

    expect($result['outcome'])->toBe(PrimaryImageEditorService::OUTCOME_REAPPLIED);

    $product->refresh();
    expect((int) $product->values['common']['afbeelding'])->not->toBe($outlinedId)
        ->and($product->additional['primary_image_editor']['outline'])->toBeFalse()
        ->and($product->additional['primary_image_editor']['source_asset_id'])->toBe($source->id)
        ->and(app(ImageCompositor::class)->detectShapeOutline(outlineTestPrimaryBytes($product), 'rond'))->toBeFalse();
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('pixel-strips the outline when no transform or source is available', function () {
    $primary = outlineTestAsset(outlineTestComposite(true));
    $noLogo = outlineTestAsset(outlineTestComposite(true));

    $product = Product::factory()->simple()->create([
        'values' => ['common' => [
            'vorm'                   => 'Rond',
            'afbeelding'             => (string) $primary->id,
            'afbeelding_zonder_logo' => (string) $noLogo->id,
        ]],
    ]);

    $result = app(PrimaryImageEditorService::class)->removeOutline($product);

    expect($result['outcome'])->toBe(PrimaryImageEditorService::OUTCOME_STRIPPED);

    $product->refresh();
    $newPrimaryId = (int) $product->values['common']['afbeelding'];
    $newNoLogoId = (int) $product->values['common']['afbeelding_zonder_logo'];

    expect($newPrimaryId)->not->toBe($primary->id)
        ->and($newNoLogoId)->not->toBe($noLogo->id)
        ->and($product->additional['primary_image_editor']['outline'])->toBeFalse()
        ->and($product->additional['primary_image_editor']['shape'])->toBe('rond')
        ->and(app(ImageCompositor::class)->detectShapeOutline(outlineTestPrimaryBytes($product), 'rond'))->toBeFalse()
        ->and(app(ImageCompositor::class)->detectShapeOutline(
            Storage::disk('private')->get(Asset::findOrFail($newNoLogoId)->path),
            'rond',
        ))->toBeFalse();
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('refuses to re-composite when the transform wants the HW icon but none is configured', function () {
    $source = outlineTestAsset((string) app(ImageManager::class)->create(800, 800)->fill('cc8844')->toJpeg());
    $primary = outlineTestAsset(outlineTestComposite(true));

    $product = Product::factory()->simple()->create([
        'values'     => ['common' => ['afbeelding' => (string) $primary->id]],
        'additional' => ['primary_image_editor' => [
            'shape'           => 'rond',
            'outline'         => true,
            'icon'            => true,
            'source_asset_id' => $source->id,
        ]],
    ]);

    $result = app(PrimaryImageEditorService::class)->removeOutline($product);

    // No icon is configured in the test environment: regenerating would lose
    // the stamped logo, so the product must land in the manual bucket.
    expect($result['outcome'])->toBe(PrimaryImageEditorService::OUTCOME_MANUAL)
        ->and($result['reason'])->toContain('icon')
        ->and((int) $product->fresh()->values['common']['afbeelding'])->toBe($primary->id);
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('skips composites that carry no outline', function () {
    $primary = outlineTestAsset(outlineTestComposite(false));

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['vorm' => 'Rond', 'afbeelding' => (string) $primary->id]],
    ]);

    $result = app(PrimaryImageEditorService::class)->removeOutline($product);

    expect($result['outcome'])->toBe(PrimaryImageEditorService::OUTCOME_NO_RING)
        ->and((int) $product->fresh()->values['common']['afbeelding'])->toBe($primary->id);
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('skips products whose transform already records outline=false', function () {
    $primary = outlineTestAsset(outlineTestComposite(false));

    $product = Product::factory()->simple()->create([
        'values'     => ['common' => ['afbeelding' => (string) $primary->id]],
        'additional' => ['primary_image_editor' => ['shape' => 'rond', 'outline' => false, 'source_asset_id' => $primary->id]],
    ]);

    expect(app(PrimaryImageEditorService::class)->removeOutline($product)['outcome'])
        ->toBe(PrimaryImageEditorService::OUTCOME_NO_RING);
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('reports non-standard images for manual handling and leaves them untouched', function () {
    $odd = outlineTestAsset((string) app(ImageManager::class)->create(500, 400)->fill('cc8844')->toJpeg());

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['vorm' => 'Rond', 'afbeelding' => (string) $odd->id]],
    ]);

    $result = app(PrimaryImageEditorService::class)->removeOutline($product);

    expect($result['outcome'])->toBe(PrimaryImageEditorService::OUTCOME_MANUAL)
        ->and((int) $product->fresh()->values['common']['afbeelding'])->toBe($odd->id);
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('ignores products without a masked shape', function () {
    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['vorm' => 'Rechthoek']],
    ]);

    expect(app(PrimaryImageEditorService::class)->removeOutline($product)['outcome'])
        ->toBe(PrimaryImageEditorService::OUTCOME_NOT_MASKED);

    $noVorm = Product::factory()->simple()->create();

    expect(app(PrimaryImageEditorService::class)->removeOutline($noVorm)['outcome'])
        ->toBe(PrimaryImageEditorService::OUTCOME_NOT_MASKED);
});

it('classifies without writing in dry-run and applies via the command otherwise', function () {
    $primary = outlineTestAsset(outlineTestComposite(true));

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['vorm' => 'Rond', 'afbeelding' => (string) $primary->id]],
    ]);

    $this->artisan('products:remove-image-outline', ['--product-id' => [$product->id], '--dry-run' => true])
        ->assertExitCode(0);

    expect((int) $product->fresh()->values['common']['afbeelding'])->toBe($primary->id)
        ->and($product->fresh()->additional['primary_image_editor'] ?? null)->toBeNull();

    $this->artisan('products:remove-image-outline', ['--product-id' => [$product->id]])
        ->assertExitCode(0);

    expect((int) $product->fresh()->values['common']['afbeelding'])->not->toBe($primary->id)
        ->and($product->fresh()->additional['primary_image_editor']['outline'])->toBeFalse();
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');
