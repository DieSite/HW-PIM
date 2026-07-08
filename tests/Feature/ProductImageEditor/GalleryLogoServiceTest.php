<?php

use App\Models\AssetLogoVariant;
use App\Services\ProductImageEditor\GalleryLogoService;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Webkul\Core\Models\CoreConfig;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\AssetResourceMapping;
use Webkul\Product\Models\Product;

function makeGalleryAsset(?string $fileName = null, ?string $bytes = null): Asset
{
    static $counter = 0;
    $counter++;

    $bytes ??= (string) app(ImageManager::class)->create(800, 1000)->fill('cc8844')->toJpeg();

    $fileName ??= "gallery-image-{$counter}.jpg";
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
    Storage::fake(config('filesystems.default'));

    config()->set('product_image_editor.enabled', true);
    config()->set('product_image_editor.primary_attribute', 'afbeelding');

    $icon = (string) app(ImageManager::class)->create(50, 50)->fill('0000ff')->toPng();
    Storage::disk(config('filesystems.default'))->put('configuration/hw-icon.png', $icon);

    CoreConfig::create([
        'code'  => 'image_editor.settings.general.hw_icon',
        'value' => 'configuration/hw-icon.png',
    ]);
});

it('stamps the HW logo on every gallery image and rewires the product', function () {
    $a = makeGalleryAsset();
    $b = makeGalleryAsset();
    $c = makeGalleryAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => implode(',', [$a->id, $b->id, $c->id])]],
    ]);

    $summary = app(GalleryLogoService::class)->apply($product);

    expect($summary['stamped'])->toBe([$a->id, $b->id, $c->id]);

    $ids = array_map('intval', explode(',', $product->fresh()->values['common']['afbeelding']));

    expect($ids)->toHaveCount(3)
        ->not->toContain($a->id)
        ->not->toContain($b->id)
        ->not->toContain($c->id);

    foreach ($ids as $id) {
        $variant = Asset::find($id);

        expect($variant)->not->toBeNull()
            ->and($variant->file_name)->toMatch('/-hw-[a-z0-9]{6}\.jpg$/');

        // Original dimensions are preserved; the icon sits in the bottom-left
        // (source 800x1000 -> icon box roughly x 35..148, y 852..965).
        $image = app(ImageManager::class)->read(Storage::disk('private')->get($variant->path));

        // JPEG compression may shift channels by a unit, so allow a small tolerance.
        [$red, $green, $blue] = array_map(
            fn ($channel) => $channel->value(),
            $image->pickColor(60, 900)->channels(),
        );

        expect($image->width())->toBe(800)
            ->and($image->height())->toBe(1000)
            ->and($red)->toBeLessThan(10)
            ->and($green)->toBeLessThan(10)
            ->and($blue)->toBeGreaterThan(245);
    }

    expect(AssetLogoVariant::count())->toBe(3)
        ->and(AssetResourceMapping::where('product_id', $product->id)->where('related_field', 'afbeelding')->count())->toBe(3);
});

it('does not stamp an image twice on repeated runs', function () {
    $a = makeGalleryAsset();
    $b = makeGalleryAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => implode(',', [$a->id, $b->id])]],
    ]);

    app(GalleryLogoService::class)->apply($product);

    $valuesAfterFirstRun = $product->fresh()->values['common']['afbeelding'];
    $assetCount = Asset::count();

    $summary = app(GalleryLogoService::class)->apply($product->fresh());

    expect($summary['stamped'])->toBeEmpty()
        ->and($summary['reused'])->toBeEmpty()
        ->and($product->fresh()->values['common']['afbeelding'])->toBe($valuesAfterFirstRun)
        ->and(Asset::count())->toBe($assetCount);
});

it('reuses an existing logo variant when another product shares the source image', function () {
    $shared = makeGalleryAsset();

    $productA = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $shared->id]],
    ]);
    $productB = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $shared->id]],
    ]);

    app(GalleryLogoService::class)->apply($productA);
    $variantId = (int) $productA->fresh()->values['common']['afbeelding'];

    $assetCount = Asset::count();
    $summary = app(GalleryLogoService::class)->apply($productB);

    expect($summary['reused'])->toBe([$shared->id])
        ->and((int) $productB->fresh()->values['common']['afbeelding'])->toBe($variantId)
        ->and(Asset::count())->toBe($assetCount);
});

it('recognises the primary image editor composite as already stamped', function () {
    $edited = makeGalleryAsset();
    $other = makeGalleryAsset();

    $product = Product::factory()->simple()->create([
        'values'     => ['common' => ['afbeelding' => implode(',', [$edited->id, $other->id])]],
        'additional' => ['primary_image_editor' => ['edited_asset_id' => $edited->id]],
    ]);

    $summary = app(GalleryLogoService::class)->apply($product);

    $ids = array_map('intval', explode(',', $product->fresh()->values['common']['afbeelding']));

    expect($summary['skipped'])->toBe([$edited->id])
        ->and($summary['stamped'])->toBe([$other->id])
        ->and($ids[0])->toBe($edited->id)
        ->and($ids[1])->not->toBe($other->id);
});

it('recognises "-hw-" suffixed assets as already stamped', function () {
    $stamped = makeGalleryAsset('vloerkleed-hw-abc123.jpg');

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $stamped->id]],
    ]);

    $summary = app(GalleryLogoService::class)->apply($product);

    expect($summary['skipped'])->toBe([$stamped->id])
        ->and($product->fresh()->values['common']['afbeelding'])->toBe((string) $stamped->id);
});

it('visually recognises a manually stamped image and never re-stamps it', function () {
    // Simulate a historical manual stamp: same artwork in the bottom-left, but
    // sized ~15% larger and placed slightly differently than the automatic one.
    $manager = app(ImageManager::class);

    $image = $manager->create(800, 1000)->fill('cc8844');
    $manualStamp = $manager->create(50, 50)->fill('0000ff')->resize(130, 130);
    $image->place($manualStamp, 'top-left', 42, 1000 - 42 - 130);

    $manuallyStamped = makeGalleryAsset(null, (string) $image->toJpeg());

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $manuallyStamped->id]],
    ]);

    $assetCount = Asset::count();
    $summary = app(GalleryLogoService::class)->apply($product);

    // Recognised by pixel inspection: skipped, gallery untouched, and recorded
    // as its own variant so the visual check never runs again for this asset.
    expect($summary['skipped'])->toBe([$manuallyStamped->id])
        ->and($summary['stamped'])->toBeEmpty()
        ->and($product->fresh()->values['common']['afbeelding'])->toBe((string) $manuallyStamped->id)
        ->and(Asset::count())->toBe($assetCount)
        ->and(AssetLogoVariant::where('source_asset_id', $manuallyStamped->id)->value('variant_asset_id'))->toBe($manuallyStamped->id);
})->skip(! extension_loaded('imagick'), 'Logo detection requires Imagick.');

it('does nothing when no HW icon is configured', function () {
    CoreConfig::query()->where('code', 'image_editor.settings.general.hw_icon')->delete();

    $a = makeGalleryAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $a->id]],
    ]);

    $summary = app(GalleryLogoService::class)->apply($product);

    expect($summary)->toBe(['stamped' => [], 'reused' => [], 'skipped' => []])
        ->and($product->fresh()->values['common']['afbeelding'])->toBe((string) $a->id);
});

it('reports without writing in dry-run mode', function () {
    $a = makeGalleryAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $a->id]],
    ]);

    $assetCount = Asset::count();
    $summary = app(GalleryLogoService::class)->apply($product, dryRun: true);

    expect($summary['stamped'])->toBe([$a->id])
        ->and($product->fresh()->values['common']['afbeelding'])->toBe((string) $a->id)
        ->and(Asset::count())->toBe($assetCount)
        ->and(AssetLogoVariant::count())->toBe(0);
});

it('stamps gallery images through the artisan backfill command', function () {
    $a = makeGalleryAsset();

    $product = Product::factory()->simple()->create([
        'values' => ['common' => ['afbeelding' => (string) $a->id]],
    ]);

    $this->artisan('products:apply-logo-to-images', ['--product-id' => [$product->id]])
        ->assertExitCode(0);

    expect((int) $product->fresh()->values['common']['afbeelding'])->not->toBe($a->id)
        ->and(AssetLogoVariant::where('source_asset_id', $a->id)->exists())->toBeTrue();
});
