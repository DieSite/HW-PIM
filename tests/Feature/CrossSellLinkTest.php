<?php

use App\Models\Product;
use App\Services\CrossSellLinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Product\Type\AbstractType;
use Webkul\User\Models\Admin;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Eén kleur van een kleed: in deze catalogus is dat een eigen hoofdproduct.
 */
function makeColourway(string $sku, string $naam, string $merk = 'Eurogros', string $vorm = 'Rechthoek'): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = $sku;
    $product->type = 'configurable';
    $product->status = 1;
    $product->values = ['common' => [
        'productnaam' => $naam,
        'merk'        => $merk,
        'vorm'        => $vorm,
    ]];
    $product->save();

    return $product;
}

/**
 * @return list<string>
 */
function crossSellsOf(string $sku): array
{
    $values = Product::where('sku', $sku)->firstOrFail()->values;

    return $values[AbstractType::ASSOCIATION_VALUES_KEY][AbstractType::CROSS_SELLS_ASSOCIATION_KEY] ?? [];
}

function asAdmin(): mixed
{
    return test()->actingAs(Admin::query()->firstOrFail(), 'admin');
}

beforeEach(function () {
    Queue::fake();
});

afterEach(function () {
    Product::where('sku', 'like', 'XSTEST-%')->delete();
});

it('proposes the other colours of the same rug', function () {
    $anaheim = makeColourway('XSTEST-1', 'Anaheim 3243');
    makeColourway('XSTEST-2', 'Anaheim 3434');
    makeColourway('XSTEST-3', 'Anaheim 4248');

    $skus = app(CrossSellLinker::class)->candidates($anaheim)->pluck('sku');

    expect($skus->all())->toBe(['XSTEST-2', 'XSTEST-3']);
});

it('keeps other brands, other shapes and other rugs out of the proposal', function () {
    $anaheim = makeColourway('XSTEST-1', 'Anaheim 3243');

    makeColourway('XSTEST-2', 'Anaheim 3434', merk: 'Karpi');
    makeColourway('XSTEST-3', 'Anaheim 4248 Rond', vorm: 'Rond');
    makeColourway('XSTEST-4', 'Athene 3243');
    makeColourway('XSTEST-5', 'Anaheim 3243');

    $skus = app(CrossSellLinker::class)->candidates($anaheim)->pluck('sku');

    expect($skus->all())->toBe(['XSTEST-5']);
});

it('groups round variants with each other', function () {
    $rond = makeColourway('XSTEST-1', 'Anaheim 3243 Rond', vorm: 'Rond');
    makeColourway('XSTEST-2', 'Anaheim 3434 Rond', vorm: 'Rond');
    makeColourway('XSTEST-3', 'Anaheim 3434');

    expect(app(CrossSellLinker::class)->candidates($rond)->pluck('sku')->all())->toBe(['XSTEST-2']);
});

it('finds a colourway whose values column is double encoded', function () {
    $anaheim = makeColourway('XSTEST-1', 'Anaheim 3243');
    $sibling = makeColourway('XSTEST-2', 'Anaheim 3434');

    DB::table('products')->where('id', $sibling->id)->update([
        'values' => json_encode(json_encode($sibling->values)),
    ]);

    expect(app(CrossSellLinker::class)->candidates($anaheim)->pluck('sku')->all())->toBe(['XSTEST-2']);
});

it('connects every chosen product to every other, itself included', function () {
    makeColourway('XSTEST-1', 'Anaheim 3243');
    makeColourway('XSTEST-2', 'Anaheim 3434');
    makeColourway('XSTEST-3', 'Anaheim 4248');

    app(CrossSellLinker::class)->connect(['XSTEST-1', 'XSTEST-2', 'XSTEST-3']);

    $expected = ['XSTEST-1', 'XSTEST-2', 'XSTEST-3'];

    expect(crossSellsOf('XSTEST-1'))->toBe($expected)
        ->and(crossSellsOf('XSTEST-2'))->toBe($expected)
        ->and(crossSellsOf('XSTEST-3'))->toBe($expected);
});

it('always queues a WooCommerce sync for each connected product', function () {
    makeColourway('XSTEST-1', 'Anaheim 3243');
    makeColourway('XSTEST-2', 'Anaheim 3434');

    app(CrossSellLinker::class)->connect(['XSTEST-1', 'XSTEST-2']);

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class, 2);
});

it('refuses a group of one', function () {
    makeColourway('XSTEST-1', 'Anaheim 3243');

    expect(fn () => app(CrossSellLinker::class)->connect(['XSTEST-1']))
        ->toThrow(RuntimeException::class, 'minstens twee');
});

it('lists the product itself first and preselects everything', function () {
    $anaheim = makeColourway('XSTEST-1', 'Anaheim 3243');
    makeColourway('XSTEST-2', 'Anaheim 3434');

    $response = asAdmin()
        ->getJson(route('admin.catalog.products.cross-sells.candidates', ['productId' => $anaheim->id]))
        ->assertOk();

    expect($response->json('product.sku'))->toBe('XSTEST-1')
        ->and($response->json('product.naam'))->toBe('Anaheim 3243')
        ->and($response->json('candidates.0.sku'))->toBe('XSTEST-2')
        ->and($response->json('candidates.0.selected'))->toBeTrue()
        ->and($response->json('candidates.0.already_linked'))->toBeFalse();
});

it('marks a candidate that is already linked', function () {
    $anaheim = makeColourway('XSTEST-1', 'Anaheim 3243');
    makeColourway('XSTEST-2', 'Anaheim 3434');

    app(CrossSellLinker::class)->connect(['XSTEST-1', 'XSTEST-2']);

    $response = asAdmin()
        ->getJson(route('admin.catalog.products.cross-sells.candidates', ['productId' => $anaheim->id]))
        ->assertOk();

    expect($response->json('candidates.0.already_linked'))->toBeTrue();
});

it('explains through the endpoint that the name carries no colour code', function () {
    $product = makeColourway('XSTEST-1', 'Nepalian');

    asAdmin()
        ->getJson(route('admin.catalog.products.cross-sells.candidates', ['productId' => $product->id]))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'kleurcode'));
});

it('connects only the products that are still ticked', function () {
    makeColourway('XSTEST-1', 'Anaheim 3243');
    makeColourway('XSTEST-2', 'Anaheim 3434');
    makeColourway('XSTEST-3', 'Anaheim 4248');

    asAdmin()
        ->postJson(route('admin.catalog.products.cross-sells.connect'), ['skus' => ['XSTEST-1', 'XSTEST-3']])
        ->assertOk()
        ->assertJson(['connected' => 2]);

    expect(crossSellsOf('XSTEST-1'))->toBe(['XSTEST-1', 'XSTEST-3'])
        ->and(crossSellsOf('XSTEST-3'))->toBe(['XSTEST-1', 'XSTEST-3'])
        ->and(crossSellsOf('XSTEST-2'))->toBe([]);
});

it('rejects a connect call with a single product', function () {
    makeColourway('XSTEST-1', 'Anaheim 3243');

    asAdmin()
        ->postJson(route('admin.catalog.products.cross-sells.connect'), ['skus' => ['XSTEST-1']])
        ->assertStatus(422);
});

it('reports an unknown sku instead of connecting half a group', function () {
    makeColourway('XSTEST-1', 'Anaheim 3243');

    asAdmin()
        ->postJson(route('admin.catalog.products.cross-sells.connect'), ['skus' => ['XSTEST-1', 'XSTEST-BESTAAT-NIET']])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'XSTEST-BESTAAT-NIET'));

    expect(crossSellsOf('XSTEST-1'))->toBe([]);
});

it('shows the button on a parent product and not on a variant', function () {
    $parent = makeColourway('XSTEST-1', 'Anaheim 3243');

    $variant = new Product();
    $variant->attribute_family_id = $parent->attribute_family_id;
    $variant->parent_id = $parent->id;
    $variant->sku = 'XSTEST-1.1';
    $variant->type = 'simple';
    $variant->status = 1;
    $variant->values = ['common' => ['productnaam' => 'Anaheim 3243', 'maat' => '160 cm x 230 cm']];
    $variant->save();

    $parentHtml = asAdmin()->get(route('admin.catalog.products.edit', ['id' => $parent->id]))->getContent();
    $variantHtml = asAdmin()->get(route('admin.catalog.products.edit', ['id' => $variant->id]))->getContent();

    // Op de knop-markup zelf, niet op de tekst: de scriptblokken staan op elke
    // productpagina en noemen de knop in een foutmelding.
    expect($parentHtml)->toContain('openCrossSellModal(this)')
        ->and($variantHtml)->not->toContain('openCrossSellModal(this)');
});
