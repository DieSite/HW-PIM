<?php

use App\Services\DefaultProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Webkul\User\Models\Admin;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;
use Webkul\WooCommerce\Models\DataTransferMapping;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;

/**
 * Upload a default image through the admin Configuration screen, the way a
 * user sets it.
 */
function uploadDefaultProductImage(): void
{
    test()->actingAs(Admin::factory()->create(['password' => Hash::make('password')]), 'admin');

    $configItem = collect(config('core'))->firstWhere('key', 'product_images.settings.general');

    test()->post(route('admin.configuration.store'), [
        'product_images' => ['settings' => ['general' => ['default_image' => UploadedFile::fake()->image('geen-afbeelding.png', 800, 800)]]],
        'keys'           => [json_encode($configItem)],
    ])->assertRedirect();
}

/**
 * Run the exporter's protected addDefaultImage() on a formatted parent payload,
 * without the exporter's dependency-heavy constructor.
 *
 * @param  array<string, mixed>  $formatted
 * @return array<string, mixed>
 */
function applyDefaultImage(array $formatted): array
{
    $exporter = (new ReflectionClass(Exporter::class))->newInstanceWithoutConstructor();

    (new ReflectionProperty($exporter, 'credential'))->setValue($exporter, ['shopUrl' => 'https://shop.test']);
    (new ReflectionProperty($exporter, 'dataTransferMappingRepository'))->setValue($exporter, app(DataTransferMappingRepository::class));

    (new ReflectionMethod($exporter, 'addDefaultImage'))->invokeArgs($exporter, [&$formatted, 'ERG0411']);

    return $formatted;
}

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
});

it('renders the default image upload field on the configuration page', function () {
    $this->actingAs(Admin::factory()->create(['password' => Hash::make('password')]), 'admin');

    $this->get(route('admin.configuration.edit', ['product_images', 'settings']))
        ->assertOk()
        ->assertSeeText('Geen afbeelding beschikbaar')
        ->assertSee('type="file"', false);
});

it('links the default image setting from the configuration menu', function () {
    $this->actingAs(Admin::factory()->create(['password' => Hash::make('password')]), 'admin');

    $this->get(route('admin.dashboard.index'))
        ->assertOk()
        ->assertSee(route('admin.configuration.edit', ['product_images', 'settings']), false)
        ->assertSeeText('Productafbeeldingen');
});

it('resolves the uploaded default image to a url', function () {
    uploadDefaultProductImage();

    $path = core()->getConfigData('product_images.settings.general.default_image');

    expect($path)->toStartWith('configuration/')
        ->and(app(DefaultProductImage::class)->url())->toBe(Storage::disk(config('filesystems.default'))->url($path));
});

it('has no default image until one is uploaded', function () {
    expect(app(DefaultProductImage::class)->url())->toBeNull();
});

it('gives a parent without images the default image for WooCommerce', function () {
    uploadDefaultProductImage();

    $url = app(DefaultProductImage::class)->url();

    expect(applyDefaultImage(['name' => 'Vloerkleed'])['images'])->toBe([
        ['src' => $url, 'name' => basename($url), 'position' => 0],
    ]);
});

it('reuses the default image WooCommerce already has', function () {
    uploadDefaultProductImage();

    $url = app(DefaultProductImage::class)->url();

    DataTransferMapping::query()->create([
        'entityType' => 'image',
        'code'       => 'ERG0411-'.basename($url),
        'externalId' => '987',
        'apiUrl'     => 'https://shop.test',
    ]);

    expect(applyDefaultImage(['name' => 'Vloerkleed'])['images'])->toBe([
        ['id' => '987', 'position' => 0],
    ]);
});

it('leaves the payload without images when no default image is set', function () {
    expect(applyDefaultImage(['name' => 'Vloerkleed']))->not->toHaveKey('images');
});
