<?php

use App\Mcp\Servers\ProductsServer;
use App\Mcp\Tools\AddAttributeOptionsTool;
use App\Mcp\Tools\CreateCategoriesTool;
use App\Mcp\Tools\DeleteProductsTool;
use App\Mcp\Tools\GetCategoriesTool;
use App\Mcp\Tools\GetProductsTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Services\Mcp\CatalogStructureService;
use App\Services\Mcp\ProductDeletionService;
use App\Services\Mcp\ProductUpsertService;
use App\Services\ProductService;
use App\Services\WooCommerce\WooCommerceCategorySync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Mcp\McpCatalogFixture;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\Product;

beforeEach(function () {
    McpCatalogFixture::install();

    Event::fake(['catalog.product.create.after', 'catalog.product.update.after', 'catalog.product.delete.before', 'catalog.product.delete.after']);

    $this->productService = $this->mock(ProductService::class);
    $this->productService->shouldReceive('triggerWCSyncForParent')->byDefault();

    $this->admin = McpCatalogFixture::admin();
    $this->upsert = fn (array $items, bool $dryRun = false): array => app(ProductUpsertService::class)->upsert($items, $this->admin, $dryRun);
});

function structureValues(string $sku): array
{
    return Product::query()->where('sku', $sku)->firstOrFail()->values;
}

it('writes cross-sells between parents created in the same batch, dropping the product itself', function () {
    McpCatalogFixture::rug('MCP-X1');

    $new = fn (string $sku, array $crossSells): array => [
        'sku'    => $sku, 'type' => 'configurable', 'family' => McpCatalogFixture::FAMILY, 'super_attributes' => ['onderkleed', 'maatgroep'],
        'values' => ['common' => ['productnaam' => $sku], 'associations' => ['cross_sells' => $crossSells]],
    ];

    $dry = ($this->upsert)([$new('BRK-1', ['BRK-1', 'BRK-2', 'MCP-X1']), $new('BRK-2', ['BRK-1'])], true);

    expect($dry['results'][0]['changes']['associations.cross_sells'])->toBe(['before' => null, 'after' => ['BRK-2', 'MCP-X1']])
        ->and(Product::query()->where('sku', 'BRK-1')->exists())->toBeFalse();

    $saved = ($this->upsert)([$new('BRK-1', ['BRK-1', 'BRK-2', 'MCP-X1']), $new('BRK-2', ['BRK-1'])]);

    expect($saved['summary'])->toMatchArray(['created' => 2])
        ->and(structureValues('BRK-1')['associations']['cross_sells'])->toBe(['BRK-2', 'MCP-X1'])
        ->and(structureValues('BRK-2')['associations']['cross_sells'])->toBe(['BRK-1']);

    $replaced = ($this->upsert)([['sku' => 'BRK-1', 'values' => ['associations' => ['cross_sells' => ['MCP-X1']]]]]);

    expect($replaced['results'][0])->action->toBe('updated')
        ->and($replaced['results'][0]['changes'])->toBe(['associations.cross_sells' => ['before' => ['BRK-2', 'MCP-X1'], 'after' => ['MCP-X1']]]);
});

it('refuses cross-sells to variants, missing SKUs, on variants and of other kinds', function (array $item, string $message) {
    McpCatalogFixture::rug('MCP-X2');
    McpCatalogFixture::rug('MCP-X3');

    $result = ($this->upsert)([$item], true)['results'][0];

    expect($result['action'])->toBe('error')
        ->and(implode(' ', $result['errors']))->toContain($message);
})->with([
    'variant target'  => [['sku' => 'MCP-X2', 'values' => ['associations' => ['cross_sells' => ['MCP-X3.1']]]], 'MCP-X3.1 is a variant; use its parent MCP-X3'],
    'missing target'  => [['sku' => 'MCP-X2', 'values' => ['associations' => ['cross_sells' => ['NOPE']]]], 'NOPE does not exist'],
    'on a variant'    => [['sku' => 'MCP-X2.1', 'values' => ['associations' => ['cross_sells' => ['MCP-X3']]]], 'belong on the parent MCP-X2'],
    'up_sells'        => [['sku' => 'MCP-X2', 'values' => ['associations' => ['up_sells' => ['MCP-X3']]]], 'only takes "cross_sells"'],
]);

it('moves a variant to another axis value and refuses taken or unknown ones', function () {
    McpCatalogFixture::rug('MCP-A1');

    $moved = ($this->upsert)([['sku' => 'MCP-A1.1', 'values' => ['common' => ['maatgroep' => '200 cm x 290 cm']]]]);

    expect($moved['results'][0]['changes']['common.maatgroep'])->toBe(['before' => '160 cm x 230 cm', 'after' => '200 cm x 290 cm'])
        ->and(structureValues('MCP-A1.1')['common']['maatgroep'])->toBe('200 cm x 290 cm');

    ($this->upsert)([['sku' => 'MCP-A1.2', 'parent_sku' => 'MCP-A1', 'values' => ['common' => ['onderkleed' => 'Zonder onderkleed', 'maatgroep' => '160 cm x 230 cm']]]]);

    $taken = ($this->upsert)([['sku' => 'MCP-A1.2', 'values' => ['common' => ['maatgroep' => '200 cm x 290 cm']]]], true)['results'][0];
    $unknown = ($this->upsert)([['sku' => 'MCP-A1.2', 'values' => ['common' => ['maatgroep' => '80 cm x 250 cm']]]], true)['results'][0];
    $onParent = ($this->upsert)([['sku' => 'MCP-A1', 'values' => ['common' => ['maatgroep' => '200 cm x 290 cm']]]], true)['results'][0];

    expect(implode(' ', $taken['errors']))->toContain('MCP-A1 already has a variant with {"maatgroep":"200 cm x 290 cm","onderkleed":"Zonder onderkleed"} (MCP-A1.1)')
        ->and(implode(' ', $unknown['errors']))->toContain('"80 cm x 250 cm" is not an option of maatgroep. Add it with add-attribute-options')
        ->and(implode(' ', $onParent['errors']))->toContain('change it on the variants');
});

it('adds select options in order, so a variant can use them', function () {
    $service = app(CatalogStructureService::class);
    DB::table('attribute_option_translations')->insert([
        'attribute_option_id' => AttributeOption::query()->where('code', '160 cm x 230 cm')->value('id'),
        'locale'              => 'nl_NL',
        'label'               => '160 cm x 230 cm',
    ]);

    $dry = $service->addOptions('maatgroep', [['code' => '80 cm x 250 cm', 'after' => '160 cm x 230 cm']], $this->admin, true);

    expect($dry['results'][0])->toMatchArray(['action' => 'created', 'sort_order' => 1])
        ->and(AttributeOption::query()->where('code', '80 cm x 250 cm')->exists())->toBeFalse();

    $saved = $service->addOptions('maatgroep', [
        ['code' => '80 cm x 250 cm', 'after' => '160 cm x 230 cm'],
        ['code' => '160 cm x 230 cm'],
    ], $this->admin);

    $order = AttributeOption::query()->whereHas('attribute', fn ($query) => $query->where('code', 'maatgroep'))->orderBy('sort_order')->pluck('code')->all();

    expect(array_column($saved['results'], 'action'))->toBe(['created', 'exists'])
        ->and($order)->toBe(['160 cm x 230 cm', '80 cm x 250 cm', '200 cm x 290 cm'])
        ->and(AttributeOption::query()->where('code', '80 cm x 250 cm')->first()->translate('nl_NL')?->label)->toBe('80 cm x 250 cm');

    McpCatalogFixture::rug('MCP-O1');

    expect(($this->upsert)([['sku' => 'MCP-O1.1', 'values' => ['common' => ['maatgroep' => '80 cm x 250 cm']]]])['results'][0]['action'])->toBe('updated');

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(AddAttributeOptionsTool::class, ['attribute' => 'merk', 'options' => [['code' => 'x']]])
        ->assertHasErrors(['merk is not a select attribute']);
});

it('lists and creates categories, also in WooCommerce', function () {
    Category::query()->create(['code' => 'Karpi']);
    McpCatalogFixture::rug('MCP-C1', [], []);
    $parent = Product::query()->where('sku', 'MCP-C1')->first();
    $parent->values = array_merge($parent->values, ['categories' => ['Karpi']]);
    $parent->save();

    $categorySync = $this->mock(WooCommerceCategorySync::class);
    $categorySync->shouldReceive('isMapped')->andReturn(true);
    $categorySync->shouldReceive('name')->andReturnUsing(fn (Category $category): string => $category->code);
    $categorySync->shouldReceive('ensure')->once()->andReturn(4321);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(GetCategoriesTool::class)
        ->assertOk()
        ->assertSee(['"code":"Karpi"', '"products":1', '"in_woocommerce":true']);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(CreateCategoriesTool::class, ['dry_run' => true, 'categories' => [['code' => 'Brinker']]])
        ->assertOk()
        ->assertSee('"action":"would_create"');

    expect(Category::query()->where('code', 'Brinker')->exists())->toBeFalse();

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(CreateCategoriesTool::class, ['categories' => [['code' => 'Brinker'], ['code' => 'Karpi'], ['code' => 'bad/code']]])
        ->assertOk()
        ->assertSee(['"code":"Brinker","action":"created"', '"woocommerce_id":4321', '"code":"Karpi","action":"exists"', 'A category code is a name']);

    expect(Category::query()->where('code', 'Brinker')->exists())->toBeTrue();

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(SearchProductsTool::class, ['category' => 'Karpi'])
        ->assertSee(['"total":1', '"sku":"MCP-C1"']);
});

it('creates categories a saved product is in but WooCommerce lacks before syncing', function () {
    Category::query()->create(['code' => 'Veelhoek']);
    Category::query()->create(['code' => 'Karpi']);
    McpCatalogFixture::rug('MCP-C2');

    $categorySync = $this->mock(WooCommerceCategorySync::class);
    $categorySync->shouldReceive('isMapped')->with('Karpi')->andReturn(true);
    $categorySync->shouldReceive('isMapped')->with('Veelhoek')->andReturn(false);
    $categorySync->shouldReceive('ensure')->once()->withArgs(fn (Category $category): bool => $category->code === 'Veelhoek');

    ($this->upsert)([['sku' => 'MCP-C2', 'values' => ['categories' => ['Karpi', 'Veelhoek']]]]);
});

it('deletes variants and parents with a dry run, guard and cross-sell clean-up', function () {
    McpCatalogFixture::rug('MCP-D1');
    McpCatalogFixture::rug('MCP-D2');
    ($this->upsert)([['sku' => 'MCP-D2', 'values' => ['associations' => ['cross_sells' => ['MCP-D1']]]]]);

    $service = app(ProductDeletionService::class);

    $guarded = $service->delete(['MCP-D1'], $this->admin, deleteVariants: false, dryRun: true);
    $dry = $service->delete(['MCP-D1', 'MCP-D1.1'], $this->admin, deleteVariants: true, dryRun: true);

    expect($guarded['results'][0]['errors'][0])->toContain('pass delete_variants: true')
        ->and($dry['results'][0])->toMatchArray(['action' => 'would_delete', 'variants' => ['MCP-D1.1'], 'cross_sold_by' => ['MCP-D2']])
        ->and($dry['results'][1]['note'])->toBe('Deleted with its parent MCP-D1.')
        ->and(Product::query()->where('sku', 'MCP-D1')->exists())->toBeTrue();

    Event::assertNotDispatched('catalog.product.delete.before');

    $this->productService->shouldReceive('triggerWCSyncForParent')->withArgs(fn (Product $parent): bool => $parent->sku === 'MCP-D2')->once()->ordered();

    $variantOnly = $service->delete(['MCP-D2.1'], $this->admin, deleteVariants: false);

    $this->productService->shouldReceive('triggerWCSyncForParent')->withArgs(fn (Product $parent): bool => $parent->sku === 'MCP-D2')->once()->ordered();

    expect($variantOnly['results'][0]['action'])->toBe('deleted')
        ->and(Product::query()->where('sku', 'MCP-D2.1')->exists())->toBeFalse();

    $deleted = $service->delete(['MCP-D1'], $this->admin, deleteVariants: true);

    expect($deleted['cross_sells_cleaned'])->toBe(['MCP-D2'])
        ->and(Product::query()->whereIn('sku', ['MCP-D1', 'MCP-D1.1'])->exists())->toBeFalse()
        ->and(structureValues('MCP-D2')['associations']['cross_sells'])->toBe([]);

    Event::assertDispatched('catalog.product.delete.before', 2);

    ProductsServer::actingAs(McpCatalogFixture::admin(['catalog.products', 'catalog.products.edit']), 'api')
        ->tool(DeleteProductsTool::class, ['skus' => ['MCP-D2'], 'dry_run' => true])
        ->assertHasErrors(['catalog.products.delete']);
});

it('reports broken cross-sells when reading and searching', function () {
    McpCatalogFixture::rug('MCP-P1');
    McpCatalogFixture::rug('MCP-P2');
    McpCatalogFixture::rug('MCP-P3');

    foreach (['MCP-P1' => ['MCP-P1', 'MCP-P2.1', 'GONE'], 'MCP-P2' => ['MCP-P1'], 'MCP-P3' => ['MCP-P3']] as $sku => $crossSells) {
        $product = Product::query()->where('sku', $sku)->first();
        $product->values = array_merge($product->values, ['associations' => ['cross_sells' => $crossSells]]);
        $product->save();
    }

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(GetProductsTool::class, ['skus' => ['MCP-P1', 'MCP-P2']])
        ->assertSee('"cross_sell_problems":{"variants":{"MCP-P2.1":"MCP-P2"},"missing":["GONE"]}');

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(SearchProductsTool::class, ['cross_sell_problems' => true, 'sku_prefix' => 'MCP-P'])
        ->assertSee(['"total":2', '"sku":"MCP-P1"', '"sku":"MCP-P3"', '"cross_sell_problems":{"empty":true}'])
        ->assertDontSee('"sku":"MCP-P2"');
});
