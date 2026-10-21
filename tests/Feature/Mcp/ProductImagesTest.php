<?php

use App\Mcp\Servers\ProductsServer;
use App\Mcp\Tools\GetProductsTool;
use App\Mcp\Tools\SetProductImagesTool;
use App\Mcp\Tools\UploadProductImageTool;
use App\Mcp\Tools\ViewAssetsTool;
use App\Models\AssetLogoVariant;
use App\Services\Mcp\ProductImageService;
use App\Services\ProductImageEditor\HwIconResolver;
use App\Services\ProductService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Tests\Feature\Mcp\McpCatalogFixture;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;
use Webkul\Product\Models\Product;

function mcpPhotoBytes(string $colour = 'cc8844', int $width = 800, int $height = 1000): string
{
    return (string) app(ImageManager::class)->createImage($width, $height)->fill($colour)->encodeUsingFormat(Format::JPEG);
}

function mcpPhoto(string $fileName, string $colour = 'cc8844'): Asset
{
    $bytes = mcpPhotoBytes($colour);
    $path = "wp-content/uploads/{$fileName}";
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
 * @return array{afbeelding: list<int>, afbeelding_zonder_logo: list<int>}
 */
function mcpPhotoLists(string $sku): array
{
    $common = Product::query()->where('sku', $sku)->firstOrFail()->values['common'];

    $ids = fn (?string $value): array => array_values(array_filter(array_map('intval', explode(',', (string) $value))));

    return [
        'afbeelding'             => $ids($common['afbeelding'] ?? null),
        'afbeelding_zonder_logo' => $ids($common['afbeelding_zonder_logo'] ?? null),
    ];
}

beforeEach(function () {
    Storage::fake('private');
    McpCatalogFixture::install();

    config()->set('product_image_editor.enabled', true);

    Event::fake(['catalog.product.create.after', 'catalog.product.update.after']);

    $this->productService = $this->mock(ProductService::class);
    $this->productService->shouldReceive('triggerWCSyncForParent')->byDefault();

    $this->admin = McpCatalogFixture::admin();
    $this->images = fn (array $items, bool $dryRun = false): array => app(ProductImageService::class)->setImages($items, $this->admin, $dryRun);
});

it('uploads a photo from base64 under a URL-safe name and reuses identical bytes', function () {
    Directory::query()->firstOrCreate(['name' => 'uploads', 'parent_id' => null]);
    $bytes = mcpPhotoBytes();

    $dry = app(ProductImageService::class)->upload(['content_base64' => base64_encode($bytes), 'file_name' => 'HW Trevis 01 & beige.jpg'], $this->admin, true);

    expect($dry['result'])->action->toBe('would_create')->width->toBe(800)->height->toBe(1000)
        ->and($dry['preview'])->not->toBeNull()
        ->and(Asset::query()->count())->toBe(0);

    $created = app(ProductImageService::class)->upload(['content_base64' => 'data:image/jpeg;base64,'.base64_encode($bytes), 'file_name' => 'HW Trevis 01 & beige.jpg'], $this->admin);

    $asset = Asset::query()->sole();

    expect($created['result']['action'])->toBe('created')
        ->and($asset->file_name)->toMatch('/^hw-trevis-01-beige-[a-z0-9]{6}\.jpg$/')
        ->and($asset->path)->toBe('wp-content/uploads/'.$asset->file_name)
        ->and(Storage::disk('private')->get($asset->path))->toBe($bytes)
        ->and($asset->directories()->pluck('name')->all())->toBe(['uploads'])
        ->and($created['result']['asset'])->toMatchArray(['id' => $asset->id, 'file_name' => $asset->file_name]);

    $again = app(ProductImageService::class)->upload(['content_base64' => base64_encode($bytes)], $this->admin);

    expect($again['result'])->action->toBe('reused')
        ->and($again['result']['asset']['id'])->toBe($asset->id)
        ->and(Asset::query()->count())->toBe(1);
});

it('downloads a photo from a public URL and refuses private addresses and non-images', function () {
    Http::fake([
        '93.184.215.14/rug.jpg'  => Http::response(mcpPhotoBytes(), 200),
        '93.184.215.14/page'     => Http::response('<html></html>', 200),
    ]);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(UploadProductImageTool::class, ['url' => 'https://93.184.215.14/rug.jpg'])
        ->assertOk()
        ->assertSee(['"action":"created"', '"file_name":"rug-']);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(UploadProductImageTool::class, ['url' => 'http://127.0.0.1/rug.jpg'])
        ->assertHasErrors(['not a public address']);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(UploadProductImageTool::class, ['url' => 'https://93.184.215.14/page'])
        ->assertHasErrors(['not a JPEG, PNG or WebP image']);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(UploadProductImageTool::class, [])
        ->assertHasErrors(['Give either "url" or "content_base64"']);
});

it('dry-runs a new main photo with before/after and previews without writing anything', function () {
    $old = mcpPhoto('old-main.jpg', '333333');
    $new = mcpPhoto('new-main.jpg');
    McpCatalogFixture::rug('MCP-I1', ['afbeelding' => (string) $old->id]);
    $assetCount = Asset::query()->count();

    $outcome = ($this->images)([['sku' => 'MCP-I1', 'images' => [$new->id, $old->id]]], true);
    $result = $outcome['result']['results'][0];

    expect($outcome['result']['dry_run'])->toBeTrue()
        ->and($result['action'])->toBe('updated')
        ->and($result['primary_edit'])->toMatchArray(['source_asset_id' => $new->id, 'shape' => 'rechthoek', 'scale' => 1.0])
        ->and($result['changes']['common.afbeelding']['before'][0])->toMatchArray(['id' => $old->id, 'file_name' => 'old-main.jpg'])
        ->and($result['changes']['common.afbeelding']['after'][0])->toMatchArray(['id' => null, 'generated_from' => $new->id])
        ->and($result['changes']['common.afbeelding']['after'][1])->toMatchArray(['id' => $old->id])
        ->and($result['changes']['common.afbeelding_zonder_logo']['after'][0]['file_name'])->toContain('-zonder-logo-')
        ->and(array_column($outcome['previews'], 'label'))->toBe([
            'MCP-I1: hoofdafbeelding (afbeelding, with HW icon)',
            'MCP-I1: afbeelding_zonder_logo',
        ])
        ->and(getimagesizefromstring($outcome['previews'][0]['image'])[0])->toBe(600)
        ->and(mcpPhotoLists('MCP-I1')['afbeelding'])->toBe([$old->id])
        ->and(Asset::query()->count())->toBe($assetCount);

    Event::assertNotDispatched('catalog.product.update.after');
});

it('saves a new main photo through the editor like the admin does', function () {
    $old = mcpPhoto('old-main.jpg', '333333');
    $new = mcpPhoto('new-main.jpg');
    [$parent] = McpCatalogFixture::rug('MCP-I2', ['afbeelding' => (string) $old->id]);

    $this->productService->shouldReceive('triggerWCSyncForParent')->once();

    $outcome = ($this->images)([['sku' => 'MCP-I2', 'images' => [$new->id, $old->id], 'primary_edit' => ['rotation' => 90, 'scale' => 1.2]]]);

    $lists = mcpPhotoLists('MCP-I2');
    $composite = Asset::find($lists['afbeelding'][0]);
    $noLogo = Asset::find($lists['afbeelding_zonder_logo'][0]);
    $record = $parent->fresh()->additional['primary_image_editor'];

    expect($outcome['result']['results'][0]['action'])->toBe('updated')
        ->and($lists['afbeelding'])->toHaveCount(2)
        ->and($lists['afbeelding'][1])->toBe($old->id)
        ->and($composite->file_name)->toMatch('/^new-main-hw-[a-z0-9]{6}\.jpg$/')
        ->and($noLogo->file_name)->toMatch('/^new-main-zonder-logo-[a-z0-9]{6}\.jpg$/')
        ->and(array_slice(getimagesizefromstring(Storage::disk('private')->get($composite->path)), 0, 2))->toBe([917, 1094])
        ->and($record)->toMatchArray(['source_asset_id' => $new->id, 'edited_asset_id' => $composite->id, 'rotation' => 90.0, 'scale' => 1.2])
        ->and(array_column($outcome['previews'], 'label'))->toBe(['MCP-I2: hoofdafbeelding (afbeelding)', 'MCP-I2: afbeelding_zonder_logo'])
        ->and($outcome['result']['results'][0]['changes']['common.afbeelding']['after'][0])->toMatchArray(['id' => $composite->id, 'file_name' => $composite->file_name]);

    Event::assertDispatched('catalog.product.update.after', fn (string $event, Product $product): bool => $product->is($parent));
});

it('warns when the HW icon cannot be loaded and not when it can', function () {
    $photo = mcpPhoto('photo.jpg');
    McpCatalogFixture::rug('MCP-I10');

    $warnings = fn (): array => ($this->images)([['sku' => 'MCP-I10', 'images' => [$photo->id]]], true)['result']['results'][0]['warnings'] ?? [];

    expect($warnings()[0] ?? '')->toContain('HW icon');

    $this->mock(HwIconResolver::class)->shouldReceive('contents')->andReturn(mcpPhotoBytes('ff6700', 60, 60));

    expect($warnings())->toBe([]);
});

it('re-edits the current composite from its recorded original', function () {
    $original = mcpPhoto('original.jpg');
    McpCatalogFixture::rug('MCP-I3', ['afbeelding' => (string) $original->id]);

    ($this->images)([['sku' => 'MCP-I3', 'images' => [$original->id]]]);
    $firstComposite = mcpPhotoLists('MCP-I3')['afbeelding'][0];

    $dry = ($this->images)([['sku' => 'MCP-I3', 'primary_edit' => ['offset_x' => 40]]], true);

    expect($dry['result']['results'][0]['primary_edit'])->toMatchArray(['source_asset_id' => $original->id, 'offset_x' => 40]);

    $unchanged = ($this->images)([['sku' => 'MCP-I3']]);

    expect($unchanged['result']['results'][0]['action'])->toBe('unchanged')
        ->and(mcpPhotoLists('MCP-I3')['afbeelding'][0])->toBe($firstComposite);
});

it('reorders and unlinks photos without the editor when the main photo stays', function () {
    $main = mcpPhoto('main.jpg');
    $second = mcpPhoto('second.jpg', '445566');
    $third = mcpPhoto('third.jpg', '665544');
    McpCatalogFixture::rug('MCP-I4', ['afbeelding' => "{$main->id},{$second->id},{$third->id}"]);

    $outcome = ($this->images)([['sku' => 'MCP-I4', 'images' => [$main->id, $third->id]]]);

    expect($outcome['result']['results'][0])->not->toHaveKey('primary_edit')
        ->and(mcpPhotoLists('MCP-I4')['afbeelding'])->toBe([$main->id, $third->id]);
});

it('skips the editor on request and warns that afbeelding_zonder_logo is stale', function () {
    $old = mcpPhoto('old.jpg');
    $new = mcpPhoto('lifestyle.jpg', '778899');
    McpCatalogFixture::rug('MCP-I5', ['afbeelding' => (string) $old->id, 'afbeelding_zonder_logo' => (string) $old->id]);

    $outcome = ($this->images)([['sku' => 'MCP-I5', 'images' => [$new->id], 'primary_edit' => false]]);

    expect($outcome['result']['results'][0]['warnings'][0])->toContain('afbeelding_zonder_logo still shows the previous main photo')
        ->and(mcpPhotoLists('MCP-I5'))->toBe(['afbeelding' => [$new->id], 'afbeelding_zonder_logo' => [$old->id]]);

    ($this->images)([['sku' => 'MCP-I5', 'images' => []]]);

    expect(mcpPhotoLists('MCP-I5'))->toBe(['afbeelding' => [], 'afbeelding_zonder_logo' => []]);
});

it('edits the original of a logo-stamped photo, never the stamped copy', function () {
    $original = mcpPhoto('photo.jpg');
    $stamped = mcpPhoto('photo-hw-abc123.jpg');
    AssetLogoVariant::create(['source_asset_id' => $original->id, 'variant_asset_id' => $stamped->id]);
    McpCatalogFixture::rug('MCP-I6');

    $outcome = ($this->images)([['sku' => 'MCP-I6', 'images' => [$stamped->id]]], true);

    expect($outcome['result']['results'][0]['primary_edit']['source_asset_id'])->toBe($original->id);
});

it('rejects unknown assets, composites without an original and bad edit settings', function (array $item, string $message) {
    $composite = mcpPhoto('rug-zonder-logo-abc123.jpg');
    McpCatalogFixture::rug('MCP-I7');

    $item = json_decode(str_replace('"COMPOSITE"', (string) $composite->id, json_encode($item)), true);

    $outcome = ($this->images)([$item], true);

    expect($outcome['result']['results'][0]['action'])->toBe('error')
        ->and($outcome['result']['results'][0]['errors'][0])->toContain($message);
})->with([
    'unknown sku'      => [['sku' => 'NOPE', 'images' => []], 'does not exist'],
    'unknown asset'    => [['sku' => 'MCP-I7', 'images' => [999999]], 'Unknown asset id(s): 999999'],
    'duplicate id'     => [['sku' => 'MCP-I7', 'images' => ['COMPOSITE', 'COMPOSITE']], 'same asset id twice'],
    'composite source' => [['sku' => 'MCP-I7', 'images' => ['COMPOSITE']], 'already an edited composite'],
    'bad rotation'     => [['sku' => 'MCP-I7', 'images' => ['COMPOSITE'], 'primary_edit' => ['rotation' => 45, 'source_asset_id' => 'COMPOSITE']], 'rotation must be 0, 90, 180 or 270'],
    'bad shape'        => [['sku' => 'MCP-I7', 'images' => ['COMPOSITE'], 'primary_edit' => ['shape' => 'driehoek']], 'shape must be one of'],
    'edit without img' => [['sku' => 'MCP-I7', 'images' => [], 'primary_edit' => ['scale' => 1]], 'needs a main photo'],
    'unknown field'    => [['sku' => 'MCP-I7', 'values' => []], 'Unknown field(s): values'],
]);

it('needs the product edit permission', function () {
    $readOnly = McpCatalogFixture::admin(['catalog.products']);

    ProductsServer::actingAs($readOnly, 'api')
        ->tool(SetProductImagesTool::class, ['items' => [['sku' => 'X', 'images' => []]]])
        ->assertHasErrors(['catalog.products.edit']);
});

it('shows file name and URL per asset when reading products, and previews assets', function () {
    $main = mcpPhoto('trevis-main.jpg');
    $second = mcpPhoto('trevis-second.jpg', '445566');
    McpCatalogFixture::rug('MCP-I8', ['afbeelding' => "{$main->id},{$second->id}"]);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(GetProductsTool::class, ['skus' => ['MCP-I8']])
        ->assertOk()
        ->assertSee([
            '"assets":{"afbeelding":[{"id":'.$main->id.',"file_name":"trevis-main.jpg","url":',
            '"file_name":"trevis-second.jpg"',
        ]);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(ViewAssetsTool::class, ['asset_ids' => [$main->id, 999999]])
        ->assertOk()
        ->assertSee(['"width":800', '"height":1000', 'Preview: asset '.$main->id.' (trevis-main.jpg)', '"id":999999', '"missing":true']);
});

it('returns previews as image content through the tool', function () {
    $main = mcpPhoto('main.jpg');
    McpCatalogFixture::rug('MCP-I9', ['afbeelding' => (string) $main->id]);
    $new = mcpPhoto('new.jpg', '445566');

    $response = ProductsServer::actingAs($this->admin, 'api')
        ->tool(SetProductImagesTool::class, ['dry_run' => true, 'items' => [['sku' => 'MCP-I9', 'images' => [$new->id]]]])
        ->assertOk()
        ->assertSee(['"dry_run":true', '"generated_from":'.$new->id, 'Preview: MCP-I9: afbeelding_zonder_logo']);

    $images = collect((fn (): array => $this->response->toArray())->call($response)['result']['content'])->where('type', 'image');

    expect($images)->toHaveCount(2)
        ->and($images->first()['mimeType'])->toBe('image/jpeg')
        ->and(getimagesizefromstring(base64_decode($images->first()['data']))[0])->toBe(600);
});
