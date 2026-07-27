<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Persist a configurable parent with one variant carrying the given common
 * values, using a recognisable SKU prefix so cleanup never touches real data.
 */
function makeMaatwerkVariant(array $common, string $sku = 'BMPTEST-V1'): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = 'BMPTEST-PARENT-'.uniqid();
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
    $variant->values = ['common' => $common];
    $variant->save();

    return $variant;
}

function minimalePrijs(Product $product): ?string
{
    return $product->fresh()->values['common']['minimale_prijs']['EUR'] ?? null;
}

afterEach(function () {
    Product::where('sku', 'like', 'BMPTEST-%')->delete();
});

it('fills an empty minimale prijs of a maatwerk variant with its m² rate', function () {
    $variant = makeMaatwerkVariant([
        'maat'  => 'Maatwerk',
        'prijs' => ['EUR' => '149.5'],
    ]);

    $this->artisan('pricing:backfill-minimale-prijs')->assertSuccessful();

    expect(minimalePrijs($variant))->toBe('149.50');
});

it('prices round and square maatwerk from the same rate', function () {
    $rond = makeMaatwerkVariant(['maat' => 'Rond Maatwerk', 'prijs' => ['EUR' => '199']], 'BMPTEST-ROND');
    $vierkant = makeMaatwerkVariant(['maat' => 'Vierkant Maatwerk', 'prijs' => ['EUR' => '210']], 'BMPTEST-VIERKANT');

    $this->artisan('pricing:backfill-minimale-prijs')->assertSuccessful();

    expect(minimalePrijs($rond))->toBe('199.00')
        ->and(minimalePrijs($vierkant))->toBe('210.00');
});

it('falls back to the legacy per-m² attributes when the variant has no price', function () {
    $rechthoek = makeMaatwerkVariant(['maat' => 'Maatwerk', 'prijs_per_m2' => ['EUR' => '95']], 'BMPTEST-LEGACY1');
    $rond = makeMaatwerkVariant(['maat' => 'Rond Maatwerk', 'prijs_rond_m2' => ['EUR' => '105']], 'BMPTEST-LEGACY2');

    $this->artisan('pricing:backfill-minimale-prijs')->assertSuccessful();

    expect(minimalePrijs($rechthoek))->toBe('95.00')
        ->and(minimalePrijs($rond))->toBe('105.00');
});

it('treats an empty string minimale prijs as unfilled', function () {
    $variant = makeMaatwerkVariant([
        'maat'           => 'Maatwerk',
        'prijs'          => ['EUR' => '80'],
        'minimale_prijs' => ['EUR' => ''],
    ]);

    $this->artisan('pricing:backfill-minimale-prijs')->assertSuccessful();

    expect(minimalePrijs($variant))->toBe('80.00');
});

it('leaves a filled minimale prijs alone unless forced', function () {
    $variant = makeMaatwerkVariant([
        'maat'           => 'Maatwerk',
        'prijs'          => ['EUR' => '80'],
        'minimale_prijs' => ['EUR' => '65.00'],
    ]);

    $this->artisan('pricing:backfill-minimale-prijs')->assertSuccessful();

    expect(minimalePrijs($variant))->toBe('65.00');

    $this->artisan('pricing:backfill-minimale-prijs --force')->assertSuccessful();

    expect(minimalePrijs($variant))->toBe('80.00');
});

it('ignores variants that are not maatwerk', function () {
    $variant = makeMaatwerkVariant([
        'maat'  => '200 cm x 300 cm',
        'prijs' => ['EUR' => '80'],
    ]);

    $this->artisan('pricing:backfill-minimale-prijs')->assertSuccessful();

    expect(minimalePrijs($variant))->toBeNull();
});

it('skips maatwerk variants without any m² rate', function () {
    $variant = makeMaatwerkVariant([
        'maat'  => 'Maatwerk',
        'prijs' => ['EUR' => ''],
    ]);

    $this->artisan('pricing:backfill-minimale-prijs')
        ->expectsOutputToContain('skipped 1')
        ->assertSuccessful();

    expect(minimalePrijs($variant))->toBeNull();
});

it('writes nothing on a dry run', function () {
    $variant = makeMaatwerkVariant([
        'maat'  => 'Maatwerk',
        'prijs' => ['EUR' => '80'],
    ]);

    $this->artisan('pricing:backfill-minimale-prijs --dry-run')
        ->expectsOutputToContain('would get a minimale prijs')
        ->assertSuccessful();

    expect(minimalePrijs($variant))->toBeNull();
});

it('honours the limit', function () {
    makeMaatwerkVariant(['maat' => 'Maatwerk', 'prijs' => ['EUR' => '80']], 'BMPTEST-L1');
    makeMaatwerkVariant(['maat' => 'Maatwerk', 'prijs' => ['EUR' => '90']], 'BMPTEST-L2');

    $this->artisan('pricing:backfill-minimale-prijs --limit=1')->assertSuccessful();

    expect(Product::maatwerk()->hasMinimalePrijs()->count())->toBe(1);
});

it('queues a WooCommerce sync for the parents when asked', function () {
    Queue::fake();

    makeMaatwerkVariant(['maat' => 'Maatwerk', 'prijs' => ['EUR' => '80']]);

    $this->artisan('pricing:backfill-minimale-prijs --sync')->assertSuccessful();

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class);
});
