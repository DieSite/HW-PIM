<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Persist a configurable parent with one variant carrying the given common
 * values, using a recognisable SKU prefix so cleanup never touches real data.
 */
function makeMinimalePrijsParent(array $variantCommon, string $sku = 'MPTEST-V1'): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = 'MPTEST-PARENT-'.uniqid();
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => []];
    $parent->save();

    $variant = new Product();
    $variant->attribute_family_id = $familyId;
    $variant->parent_id = $parent->id;
    $variant->sku = $sku;
    $variant->type = 'simple';
    $variant->status = 1;
    $variant->values = ['common' => $variantCommon];
    $variant->save();

    return $parent;
}

afterEach(function () {
    Product::where('sku', 'like', 'MPTEST-%')->delete();
});

it('syncs parents that have a variant with a minimale prijs', function () {
    Queue::fake();

    makeMinimalePrijsParent(['minimale_prijs' => ['EUR' => '89.50']]);

    $this->artisan('wc:export-minimale-prijs')->assertSuccessful();

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class);
});

it('does not sync parents whose variants have no minimale prijs', function () {
    Queue::fake();

    makeMinimalePrijsParent(['minimale_prijs' => ['EUR' => '']]);
    makeMinimalePrijsParent(['prijs' => ['EUR' => '199']], 'MPTEST-V2');

    $this->artisan('wc:export-minimale-prijs')
        ->expectsOutputToContain('No products with a minimale prijs found.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('reports the count without syncing on a dry run', function () {
    Queue::fake();

    makeMinimalePrijsParent(['minimale_prijs' => ['EUR' => '120.00']]);

    $this->artisan('wc:export-minimale-prijs --dry-run')
        ->expectsOutputToContain('would be synced to WooCommerce')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
