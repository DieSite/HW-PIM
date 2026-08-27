<?php

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;

/**
 * A parent with both onderkleed variants of one size. Returns the met-onderkleed
 * variant, since that is the one the "Prijs berekenen" button runs on.
 */
function makeOnderkleedPair(array $zonder, array $met, string $size = '160 cm x 230 cm'): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = 'OKTEST-PARENT';
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => []];
    $parent->save();

    $variants = [];

    foreach ([['OKTEST-ZONDER', 'Zonder onderkleed', $zonder], ['OKTEST-MET', 'Met onderkleed', $met]] as [$sku, $onderkleed, $values]) {
        $variant = new Product();
        $variant->attribute_family_id = $familyId;
        $variant->parent_id = $parent->id;
        $variant->sku = $sku;
        $variant->type = 'simple';
        $variant->status = 1;
        $variant->values = ['common' => array_merge($values, [
            'maat'       => $size,
            'onderkleed' => $onderkleed,
        ])];
        $variant->save();

        $variants[$onderkleed] = $variant;
    }

    return $variants['Met onderkleed'];
}

afterEach(function () {
    Product::where('sku', 'like', 'OKTEST-%')->delete();
});

it('derives the adviesverkoopprijs from the bare variant plus the surcharge', function () {
    $met = makeOnderkleedPair(
        zonder: ['prijs' => ['EUR' => '800'], 'adviesverkoopprijs' => ['EUR' => '1000']],
        met: ['prijs' => ['EUR' => '0'], 'adviesverkoopprijs' => ['EUR' => '0']],
    );

    $service = app(ProductService::class);

    expect($service->calculateMetOnderkleedPrice($met))->toBe('830')
        ->and($service->calculateMetOnderkleedAdviesPrice($met))->toBe('1030');
});

it('leaves the adviesverkoopprijs alone when the bare variant has none', function () {
    $met = makeOnderkleedPair(
        zonder: ['prijs' => ['EUR' => '800']],
        met: ['prijs' => ['EUR' => '0']],
    );

    expect(app(ProductService::class)->calculateMetOnderkleedAdviesPrice($met))->toBeNull();
});

it('still derives the adviesverkoopprijs when the size has no surcharge', function () {
    $met = makeOnderkleedPair(
        zonder: ['prijs' => ['EUR' => '800'], 'adviesverkoopprijs' => ['EUR' => '1000']],
        met: ['prijs' => ['EUR' => '0']],
        size: '999 cm x 999 cm',
    );

    $service = app(ProductService::class);

    expect($service->calculateMetOnderkleedPrice($met))->toBe('800')
        ->and($service->calculateMetOnderkleedAdviesPrice($met))->toBe('1000');
});

it('returns both derived prices from the price endpoint', function () {
    $met = makeOnderkleedPair(
        zonder: ['prijs' => ['EUR' => '800'], 'adviesverkoopprijs' => ['EUR' => '1000']],
        met: ['prijs' => ['EUR' => '0'], 'adviesverkoopprijs' => ['EUR' => '0']],
    );

    $this->postJson('/product/met_onderkleed_price', ['sku' => $met->sku])
        ->assertOk()
        ->assertJson([
            'price'                 => '830',
            'original_price'        => '800',
            'advies_price'          => '1030',
            'original_advies_price' => '1000',
        ]);
});
