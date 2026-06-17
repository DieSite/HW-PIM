<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Persist a minimal variant product carrying only the `common` keys the
 * eligibility scope inspects. Uses a recognisable SKU prefix so afterEach can
 * clean it up without touching real data in the (prod-copy) database.
 */
function makeEligibilityProduct(array $common): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = 'ELIGTEST-'.uniqid();
    $product->type = 'simple';
    $product->status = 1;
    $product->values = ['common' => $common];
    $product->save();

    return $product;
}

afterEach(function () {
    Product::where('sku', 'like', 'ELIGTEST-%')->delete();
});

it('selects only products with an EAN, Eurogros stock and "Zonder onderkleed"', function () {
    $eligible = makeEligibilityProduct([
        'ean'               => '5414452716061',
        'voorraad_eurogros' => 5,
        'onderkleed'        => 'Zonder onderkleed',
    ]);

    $emptyEan = makeEligibilityProduct([
        'ean'               => '',
        'voorraad_eurogros' => 5,
        'onderkleed'        => 'Zonder onderkleed',
    ]);

    $missingEan = makeEligibilityProduct([
        'voorraad_eurogros' => 5,
        'onderkleed'        => 'Zonder onderkleed',
    ]);

    $noStock = makeEligibilityProduct([
        'ean'               => '5414452716078',
        'voorraad_eurogros' => 0,
        'onderkleed'        => 'Zonder onderkleed',
    ]);

    $withUnderlay = makeEligibilityProduct([
        'ean'               => '5414452716085',
        'voorraad_eurogros' => 5,
        'onderkleed'        => 'Met onderkleed',
    ]);

    $negativeStock = makeEligibilityProduct([
        'ean'               => '5414452716092',
        'voorraad_eurogros' => -1,
        'onderkleed'        => 'Zonder onderkleed',
    ]);

    $selected = Product::query()->bolSyncEligible()
        ->where('sku', 'like', 'ELIGTEST-%')
        ->pluck('sku');

    expect($selected)->toContain($eligible->sku)
        ->and($selected)->not->toContain($emptyEan->sku)
        ->and($selected)->not->toContain($missingEan->sku)
        ->and($selected)->not->toContain($noStock->sku)
        ->and($selected)->not->toContain($withUnderlay->sku)
        ->and($selected)->not->toContain($negativeStock->sku)
        ->and($selected)->toHaveCount(1);
});

it('ignores products that are double-encoded (raw JSON string column)', function () {
    $eligible = makeEligibilityProduct([
        'ean'               => '5414452716061',
        'voorraad_eurogros' => 5,
        'onderkleed'        => 'Zonder onderkleed',
    ]);

    // Reproduce the double-encoding bug on a second, otherwise-eligible product.
    $double = makeEligibilityProduct([
        'ean'               => '5414452716078',
        'voorraad_eurogros' => 5,
        'onderkleed'        => 'Zonder onderkleed',
    ]);
    DB::table('products')->where('id', $double->id)
        ->update(['values' => json_encode(json_encode(['common' => [
            'ean'               => '5414452716078',
            'voorraad_eurogros' => 5,
            'onderkleed'        => 'Zonder onderkleed',
        ]]))]);

    $selected = Product::query()->bolSyncEligible()
        ->where('sku', 'like', 'ELIGTEST-%')
        ->pluck('sku');

    expect($selected)->toContain($eligible->sku)
        ->and($selected)->not->toContain($double->sku);
});
