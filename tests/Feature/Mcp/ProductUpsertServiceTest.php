<?php

use App\Exceptions\ProductUpsertException;
use App\Services\Mcp\ProductUpsertService;
use App\Services\ProductService;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Feature\Mcp\McpCatalogFixture;
use Webkul\Product\Models\Product;

beforeEach(function () {
    McpCatalogFixture::install();

    Event::fake(['catalog.product.create.after', 'catalog.product.update.after']);

    $this->productService = $this->mock(ProductService::class);
    $this->productService->shouldReceive('triggerWCSyncForParent')->byDefault();

    $this->admin = McpCatalogFixture::admin();
    $this->upsert = fn (array $items, bool $dryRun = false): array => app(ProductUpsertService::class)->upsert($items, $this->admin, $dryRun);
});

function mcpValues(string $sku): array
{
    return Product::query()->where('sku', $sku)->firstOrFail()->values;
}

it('patches only the attributes sent and keeps the rest', function () {
    McpCatalogFixture::rug('MCP-1', ['merk' => 'Eurogros', 'materiaal' => 'Wol']);

    $result = ($this->upsert)([
        ['sku' => 'MCP-1', 'values' => ['common' => ['materiaal' => 'Polyester', 'productnaam' => 'Trevis 601']]],
    ]);

    expect($result['results'][0])
        ->action->toBe('updated')
        ->changes->toHaveKeys(['common.materiaal', 'common.productnaam'])
        ->and($result['results'][0]['changes']['common.materiaal'])->toBe(['before' => 'Wol', 'after' => 'Polyester'])
        ->and(mcpValues('MCP-1')['common'])->toMatchArray([
            'sku'         => 'MCP-1',
            'merk'        => 'Eurogros',
            'materiaal'   => 'Polyester',
            'productnaam' => 'Trevis 601',
        ]);
});

it('clears an attribute with null and replaces categories and prices as a whole', function () {
    McpCatalogFixture::rug('MCP-2', ['merk' => 'Eurogros', 'materiaal' => 'Wol'], ['prijs' => ['EUR' => '329', 'USD' => '350']]);

    ($this->upsert)([
        ['sku' => 'MCP-2', 'values' => ['common' => ['materiaal' => null]]],
        ['sku' => 'MCP-2.1', 'values' => ['common' => ['prijs' => ['EUR' => '299']], 'categories' => ['root']]],
    ]);

    expect(mcpValues('MCP-2')['common'])->not->toHaveKey('materiaal')
        ->and(mcpValues('MCP-2.1')['common']['prijs'])->toBe(['EUR' => '299'])
        ->and(mcpValues('MCP-2.1')['categories'])->toBe(['root']);
});

it('reports unchanged items without saving or syncing them', function () {
    McpCatalogFixture::rug('MCP-3', ['merk' => 'Eurogros']);

    $this->productService->shouldNotReceive('triggerWCSyncForParent');

    $result = ($this->upsert)([['sku' => 'MCP-3', 'values' => ['common' => ['merk' => 'Eurogros']]]]);

    expect($result['results'][0]['action'])->toBe('unchanged');
    Event::assertNotDispatched('catalog.product.update.after');
});

it('creates a configurable parent and its variants in one batch, variants listed first', function () {
    $result = ($this->upsert)([
        ['sku' => 'MCP-NEW.2', 'parent_sku' => 'MCP-NEW', 'values' => ['common' => [
            'onderkleed' => 'Zonder onderkleed', 'maatgroep' => '200 cm x 290 cm', 'prijs' => ['EUR' => '499'], 'ean' => '8712345678906',
        ]]],
        ['sku' => 'MCP-NEW', 'type' => 'configurable', 'family' => McpCatalogFixture::FAMILY, 'super_attributes' => ['onderkleed', 'maatgroep'],
            'values' => ['common' => ['merk' => 'Eurogros', 'productnaam' => 'Nieuw kleed']]],
        ['sku' => 'MCP-NEW.1', 'parent_sku' => 'MCP-NEW', 'values' => ['common' => [
            'onderkleed' => 'Zonder onderkleed', 'maatgroep' => '160 cm x 230 cm', 'prijs' => ['EUR' => '329'],
        ]]],
    ]);

    expect(array_column($result['results'], 'action'))->toBe(['created', 'created', 'created'])
        ->and(array_column($result['results'], 'sku'))->toBe(['MCP-NEW.2', 'MCP-NEW', 'MCP-NEW.1']);

    $parent = Product::query()->where('sku', 'MCP-NEW')->firstOrFail();

    expect($parent->type)->toBe('configurable')
        ->and($parent->super_attributes->pluck('code')->sort()->values()->all())->toBe(['maatgroep', 'onderkleed'])
        ->and($parent->variants->pluck('sku')->sort()->values()->all())->toBe(['MCP-NEW.1', 'MCP-NEW.2']);

    expect(mcpValues('MCP-NEW.2')['common'])->toMatchArray([
        'merk'        => 'Eurogros',
        'productnaam' => 'Nieuw kleed',
        'maatgroep'   => '200 cm x 290 cm',
        'prijs'       => ['EUR' => '499'],
    ]);

    Event::assertDispatchedTimes('catalog.product.create.after', 3);
});

it('creates a simple product', function () {
    $result = ($this->upsert)([
        ['sku' => 'MCP-SIMPLE', 'type' => 'simple', 'family' => McpCatalogFixture::FAMILY, 'values' => ['common' => ['merk' => 'HW']]],
    ]);

    expect($result['results'][0]['action'])->toBe('created')
        ->and(Product::query()->where('sku', 'MCP-SIMPLE')->value('type'))->toBe('simple');
});

it('fails a single bad item and still applies the rest', function () {
    McpCatalogFixture::rug('MCP-4');

    $result = ($this->upsert)([
        ['sku' => 'MCP-4.2', 'parent_sku' => 'MCP-4', 'values' => ['common' => ['onderkleed' => 'Zonder onderkleed', 'maatgroep' => '999 x 999']]],
        ['sku' => 'MCP-4.3', 'parent_sku' => 'MCP-4', 'values' => ['common' => ['onderkleed' => 'Met onderkleed', 'maatgroep' => '160 cm x 230 cm']]],
        ['sku' => 'MCP-4', 'values' => ['common' => ['merk' => 'Eurogros']]],
    ]);

    expect(array_column($result['results'], 'action'))->toBe(['error', 'created', 'updated'])
        ->and($result['results'][0]['errors'][0])->toContain('not an option of maatgroep')
        ->and($result['summary'])->toMatchArray(['error' => 1, 'created' => 1, 'updated' => 1, 'total' => 3])
        ->and(Product::query()->where('sku', 'MCP-4.2')->exists())->toBeFalse()
        ->and(mcpValues('MCP-4')['common']['merk'])->toBe('Eurogros');
});

it('rejects a duplicate variant combination', function () {
    McpCatalogFixture::rug('MCP-5');

    $result = ($this->upsert)([
        ['sku' => 'MCP-5.9', 'parent_sku' => 'MCP-5', 'values' => ['common' => ['onderkleed' => 'Zonder onderkleed', 'maatgroep' => '160 cm x 230 cm']]],
    ]);

    expect($result['results'][0]['action'])->toBe('error')
        ->and($result['results'][0]['errors'][0])->toContain('already has a variant');
});

it('refuses writes the MCP must not make', function (array $item, string $message) {
    McpCatalogFixture::rug('MCP-6');

    $result = ($this->upsert)([$item]);

    expect($result['results'][0]['action'])->toBe('error')
        ->and(implode(' ', $result['results'][0]['errors']))->toContain($message);
})->with([
    'unknown attribute'      => [['sku' => 'MCP-6', 'values' => ['common' => ['kleur_xyz' => 'rood']]], 'not an attribute of this product'],
    'asset attribute'        => [['sku' => 'MCP-6', 'values' => ['common' => ['afbeelding' => '123']]], 'cannot be set through the MCP'],
    'wrong section'          => [['sku' => 'MCP-6', 'values' => ['common' => ['mcp_tagline' => 'x']]], 'belongs in values.locale_specific'],
    'variant axis change'    => [['sku' => 'MCP-6.1', 'values' => ['common' => ['maatgroep' => '200 cm x 290 cm']]], 'variant axis'],
    'sku rename'             => [['sku' => 'MCP-6', 'values' => ['common' => ['sku' => 'OTHER']]], 'cannot be renamed'],
    'type change'            => [['sku' => 'MCP-6', 'type' => 'simple', 'values' => []], 'cannot be changed'],
    'unknown new product'    => [['sku' => 'MCP-UNKNOWN', 'values' => []], 'does not exist yet'],
    'unknown section'        => [['sku' => 'MCP-6', 'values' => ['associations' => []]], 'Unknown values section'],
    'unknown category'       => [['sku' => 'MCP-6', 'values' => ['categories' => ['does-not-exist']]], 'does-not-exist does not exist'],
]);

it('accepts a locale-specific attribute in its own section', function () {
    McpCatalogFixture::rug('MCP-7');

    $locale = core()->getDefaultChannel()->locales->first()->code;

    $result = ($this->upsert)([['sku' => 'MCP-7', 'values' => ['locale_specific' => [$locale => ['mcp_tagline' => 'Zacht en sterk']]]]]);

    expect($result['results'][0]['action'])->toBe('updated')
        ->and(mcpValues('MCP-7')['locale_specific'][$locale]['mcp_tagline'])->toBe('Zacht en sterk');
});

it('writes nothing and syncs nothing on a dry run', function () {
    McpCatalogFixture::rug('MCP-8', ['merk' => 'Oud']);

    $this->productService->shouldNotReceive('triggerWCSyncForParent');

    $result = ($this->upsert)([
        ['sku' => 'MCP-8', 'values' => ['common' => ['merk' => 'Nieuw']]],
        ['sku' => 'MCP-8-NEW', 'type' => 'simple', 'family' => McpCatalogFixture::FAMILY, 'values' => ['common' => ['merk' => 'Nieuw']]],
    ], dryRun: true);

    expect($result['dry_run'])->toBeTrue()
        ->and(array_column($result['results'], 'action'))->toBe(['updated', 'created'])
        ->and($result['results'][0]['changes']['common.merk'])->toBe(['before' => 'Oud', 'after' => 'Nieuw'])
        ->and(mcpValues('MCP-8')['common']['merk'])->toBe('Oud')
        ->and(Product::query()->where('sku', 'MCP-8-NEW')->exists())->toBeFalse();

    Event::assertNothingDispatched();
});

it('checks the admin ACL per action', function () {
    McpCatalogFixture::rug('MCP-9');

    $this->admin = McpCatalogFixture::admin(['catalog.products', 'catalog.products.edit']);

    $result = ($this->upsert)([
        ['sku' => 'MCP-9', 'values' => ['common' => ['merk' => 'Mag']]],
        ['sku' => 'MCP-9-NEW', 'type' => 'simple', 'family' => McpCatalogFixture::FAMILY, 'values' => []],
    ]);

    expect(array_column($result['results'], 'action'))->toBe(['updated', 'error'])
        ->and($result['results'][1]['errors'][0])->toContain('catalog.products.create');
});

it('runs the admin save downstream once per parent', function () {
    [$parent] = McpCatalogFixture::rug('MCP-10');
    McpCatalogFixture::rug('MCP-11');

    Product::query()->where('sku', 'MCP-10.1')->update(['bol_com_sync' => true]);

    $this->productService->shouldReceive('triggerWCSyncForParent')
        ->twice()
        ->withArgs(fn (Product $product): bool => in_array($product->sku, ['MCP-10', 'MCP-11'], true));
    $this->productService->shouldReceive('processBolSync')
        ->once()
        ->withArgs(fn (Product $product, bool $sync): bool => $product->sku === 'MCP-10.1' && $sync);
    $this->productService->shouldReceive('copyStockValuesOnderkleed')
        ->once()
        ->withArgs(fn (Product $product): bool => $product->sku === 'MCP-10.1');

    ($this->upsert)([
        ['sku' => 'MCP-10', 'values' => ['common' => ['merk' => 'A']]],
        ['sku' => 'MCP-10.1', 'values' => ['common' => ['voorraad_eurogros' => '3']]],
        ['sku' => 'MCP-11.1', 'values' => ['common' => ['merk' => 'B']]],
    ]);

    Event::assertDispatchedTimes('catalog.product.update.after', 3);
});

it('refuses batches over the item limit', function () {
    ($this->upsert)(array_fill(0, ProductUpsertService::MAX_ITEMS + 1, ['sku' => 'X', 'values' => []]));
})->throws(ProductUpsertException::class, 'at most');
