<?php

use App\Mcp\Servers\ProductsServer;
use App\Mcp\Tools\GetFamilyAttributesTool;
use App\Mcp\Tools\GetProductsTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Mcp\Tools\UpsertProductsTool;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Mcp\McpCatalogFixture;
use Webkul\Product\Models\Product;

beforeEach(function () {
    McpCatalogFixture::install();

    $this->admin = McpCatalogFixture::admin();
});

it('lists the families and describes one', function () {
    ProductsServer::actingAs($this->admin, 'api')
        ->tool(GetFamilyAttributesTool::class)
        ->assertOk()
        ->assertSee(McpCatalogFixture::FAMILY);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(GetFamilyAttributesTool::class, ['family' => McpCatalogFixture::FAMILY])
        ->assertOk()
        ->assertSee([
            '"code":"maatgroep"',
            '"options":[{"code":"160 cm x 230 cm"},{"code":"200 cm x 290 cm"}]',
            '"code":"mcp_tagline"',
            '"section":"locale_specific"',
            '"code":"afbeelding"',
            '"writable":false',
            '"variant_axis_candidates":["maatgroep","onderkleed"]',
        ]);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(GetFamilyAttributesTool::class, ['family' => 'nope'])
        ->assertHasErrors(['Unknown family "nope"']);
});

it('searches and reads products', function () {
    McpCatalogFixture::rug('MCP-S1', ['merk' => 'Eurogros', 'productnaam' => 'Trevis']);
    McpCatalogFixture::rug('MCP-S2', ['merk' => 'De Munk']);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(SearchProductsTool::class, ['attribute' => ['code' => 'merk', 'value' => 'Eurogros'], 'type' => 'configurable'])
        ->assertOk()
        ->assertSee(['"total":1', '"sku":"MCP-S1"', '"variants_count":1'])
        ->assertDontSee('MCP-S2');

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(SearchProductsTool::class, ['parent_sku' => 'MCP-S2'])
        ->assertOk()
        ->assertSee(['"sku":"MCP-S2.1"', '"parent_sku":"MCP-S2"']);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(GetProductsTool::class, ['skus' => ['MCP-S1', 'MISSING']])
        ->assertOk()
        ->assertSee([
            '"productnaam":"Trevis"',
            '"super_attributes":',
            '"sku":"MCP-S1.1"',
            '"not_found":["MISSING"]',
        ]);
});

it('upserts through the tool', function () {
    Event::fake(['catalog.product.update.after']);
    McpCatalogFixture::rug('MCP-T1', ['merk' => 'Oud']);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(UpsertProductsTool::class, ['dry_run' => true, 'items' => [['sku' => 'MCP-T1', 'values' => ['common' => ['merk' => 'Nieuw']]]]])
        ->assertOk()
        ->assertSee(['"dry_run":true', '"action":"updated"', '"common.merk":{"before":"Oud","after":"Nieuw"}']);

    expect(Product::query()->where('sku', 'MCP-T1')->first()->values['common']['merk'])->toBe('Oud');

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(UpsertProductsTool::class, ['items' => []])
        ->assertHasErrors();
});

it('refuses admins without catalog access', function () {
    $admin = McpCatalogFixture::admin(['dashboard']);

    ProductsServer::actingAs($admin, 'api')
        ->tool(SearchProductsTool::class, ['sku_prefix' => 'MCP'])
        ->assertHasErrors(['catalog.products']);

    ProductsServer::actingAs($admin, 'api')
        ->tool(GetProductsTool::class, ['skus' => ['MCP']])
        ->assertHasErrors(['catalog.products']);
});
