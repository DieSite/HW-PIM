<?php

use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceExclusion;
use App\Models\Product;
use App\Services\CompetitorPriceAnalysis;
use App\Services\CompetitorPricingService;
use App\Services\ProductEditSections;
use App\Services\VariantOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * A rug with one size: advies €2.500, two fresh competitor prices
 * (shopa €2.100, shopb €2.300), so our price starts at €2.100.
 *
 * @return array{parent: Product, variant: Product, cheap: CompetitorPrice, dear: CompetitorPrice}
 */
function makeExclusionRug(): array
{
    $familyId = DB::table('attribute_families')->value('id');

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = 'EXCL-'.uniqid();
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => ['productnaam' => 'Uitsluitkleed']];
    $parent->save();

    $variant = new Product();
    $variant->attribute_family_id = $familyId;
    $variant->parent_id = $parent->id;
    $variant->sku = $parent->sku.'.1';
    $variant->type = 'simple';
    $variant->status = 1;
    $variant->values = ['common' => [
        'maat'               => '200 cm x 290 cm',
        'onderkleed'         => 'Zonder onderkleed',
        'prijs'              => ['EUR' => '2500'],
        'adviesverkoopprijs' => ['EUR' => '2500'],
    ]];
    $variant->save();

    $cheap = CompetitorPrice::query()->create(['sku' => $variant->sku, 'shop' => 'shopa.nl', 'price' => 2100, 'url' => 'https://shopa.nl/kleed', 'scraped_at' => now()]);
    $dear = CompetitorPrice::query()->create(['sku' => $variant->sku, 'shop' => 'shopb.nl', 'price' => 2300, 'url' => 'https://shopb.nl/kleed', 'scraped_at' => now()]);

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    return ['parent' => $parent, 'variant' => $variant, 'cheap' => $cheap, 'dear' => $dear];
}

function sellingPriceOf(Product $variant): string
{
    return $variant->fresh()->values['common']['prijs']['EUR'];
}

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
});

it('takes an excluded competitor price out of our price at once', function () {
    $admin = Admin::factory()->create();
    $this->actingAs($admin, 'admin');
    ['parent' => $parent, 'variant' => $variant, 'cheap' => $cheap] = makeExclusionRug();

    expect(sellingPriceOf($variant))->toBe('2100');

    $response = $this->postJson(route('admin.catalog.products.competitor-prices.exclude', [$parent->id, $cheap->id]), ['reason' => 'Ander kleed'])
        ->assertOk();

    expect($response->json('prices')[$variant->sku])->toEqual(2300)
        ->and($response->json('competition')[$variant->sku])->toMatchArray(['cheapest' => 2300, 'shop' => 'shopb.nl']);

    expect(sellingPriceOf($variant))->toBe('2300')
        ->and(CompetitorPriceExclusion::query()->where('sku', $variant->sku)->first())
        ->shop->toBe('shopa.nl')
        ->reason->toBe('Ander kleed')
        ->admin_id->toBe($admin->id);

    expect($response->json('html'))
        ->toContain('ca-excluded')
        ->toContain('Uitgesloten door '.e($admin->name))
        ->toContain('Ander kleed')
        ->toContain('Weer meetellen');
});

it('counts the shop again once its price changes', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    ['parent' => $parent, 'variant' => $variant, 'cheap' => $cheap] = makeExclusionRug();

    $this->postJson(route('admin.catalog.products.competitor-prices.exclude', [$parent->id, $cheap->id]))->assertOk();
    expect(sellingPriceOf($variant))->toBe('2300');

    $cheap->update(['price' => 2050]);
    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    expect(sellingPriceOf($variant))->toBe('2050');
});

it('counts an excluded price again on request', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    ['parent' => $parent, 'variant' => $variant, 'cheap' => $cheap] = makeExclusionRug();

    $this->postJson(route('admin.catalog.products.competitor-prices.exclude', [$parent->id, $cheap->id]))->assertOk();
    $response = $this->deleteJson(route('admin.catalog.products.competitor-prices.include', [$parent->id, $cheap->id]))->assertOk();

    expect($response->json('prices')[$variant->sku])->toEqual(2100);

    expect(sellingPriceOf($variant))->toBe('2100')
        ->and(CompetitorPriceExclusion::query()->where('sku', $variant->sku)->exists())->toBeFalse();
});

it('works from the edit page of the size itself', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    ['variant' => $variant, 'cheap' => $cheap] = makeExclusionRug();

    $response = $this->postJson(route('admin.catalog.products.competitor-prices.exclude', [$variant->id, $cheap->id]))->assertOk();

    expect($response->json('prices')[$variant->sku])->toEqual(2300);
});

it('refuses a competitor price of another rug', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    ['cheap' => $cheap] = makeExclusionRug();
    ['parent' => $other] = makeExclusionRug();

    $this->postJson(route('admin.catalog.products.competitor-prices.exclude', [$other->id, $cheap->id]))->assertNotFound();

    expect(CompetitorPriceExclusion::query()->count())->toBe(0);
});

it('refuses a user who may not edit products', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['simple-stock']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    ['parent' => $parent, 'cheap' => $cheap] = makeExclusionRug();

    $this->postJson(route('admin.catalog.products.competitor-prices.exclude', [$parent->id, $cheap->id]))->assertForbidden();

    expect(CompetitorPriceExclusion::query()->count())->toBe(0);
});

it('explains the price and computes what the next run would set', function () {
    ['parent' => $parent, 'variant' => $variant, 'cheap' => $cheap] = makeExclusionRug();
    CompetitorPrice::query()->create(['sku' => $variant->sku, 'shop' => 'oud.nl', 'price' => 1500, 'scraped_at' => now()->subDays(60)]);
    CompetitorPriceExclusion::query()->create(['sku' => $variant->sku, 'shop' => 'shopa.nl', 'price' => 2100]);

    [$size] = app(CompetitorPriceAnalysis::class)->forVariants($parent->variants);

    expect($size)->toMatchArray([
        'sku'            => $variant->sku,
        'price'          => 2100.0,
        'advice_price'   => 2500.0,
        'floor'          => 1875.0,
        'computed_price' => 2300.0,
        'cheapest'       => ['shop' => 'shopb.nl', 'price' => 2300.0],
        'competitors'    => 3,
        'counting'       => 1,
    ])
        ->and(array_column($size['rows'], 'shop'))->toBe(['oud.nl', 'Huis & Wonen', 'shopa.nl', 'shopb.nl'])
        ->and(array_column($size['rows'], 'counts'))->toBe([false, true, false, true])
        ->and($size['rows'][2]['excluded'])->toBeTrue()
        ->and($size['rows'][0]['stale'])->toBeTrue();

    app(CompetitorPricingService::class)->recomputeForSkus([$variant->sku]);

    expect(sellingPriceOf($variant))->toBe('2300');
});

it('shows the competitor analysis and the sizes on the edit screen', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');
    ['parent' => $parent] = makeExclusionRug();

    $this->get(route('admin.catalog.products.edit', $parent->id))
        ->assertOk()
        ->assertSee('id="sectie-concurrentie"', false)
        ->assertSee('id="sectie-maten"', false)
        ->assertSee('shopa.nl')
        ->assertSee('Uitsluiten')
        ->assertSee('<script data-keep-scroll>', false);
});

it('puts every editable field in exactly one section', function () {
    ['parent' => $parent, 'variant' => $variant] = makeExclusionRug();

    foreach ([$parent, $variant] as $product) {
        $product = Webkul\Product\Models\Product::query()->find($product->id);
        $sections = app(ProductEditSections::class)->for($product);

        $placed = collect($sections['sections'])
            ->push($sections['collapsed'])
            ->filter()
            ->flatMap(fn (array $section) => $section['attributes']->pluck('code'));

        $editable = $product->attribute_family->familyGroups()->get()
            ->flatMap(fn ($group) => $product->getEditableAttributes($group)->pluck('code'));

        expect($placed->duplicates())->toBeEmpty()
            ->and($placed->sort()->values()->all())->toBe($editable->sort()->values()->all());
    }
});

it('orders sizes naturally, each underlay twin after its size and maatwerk last', function () {
    $variants = collect([
        ['DM1.0', 'Maatwerk'], ['DM1.10', '300 cm x 400 cm'], ['DM1.2.O', '170 cm x 240 cm'],
        ['DM1.2', '170 cm x 240 cm'], ['DM1.0.O', 'Maatwerk'], ['DM1.3', '200 cm x 250 cm'],
    ])->map(function (array $row): Webkul\Product\Models\Product {
        $product = new Webkul\Product\Models\Product();
        $product->sku = $row[0];
        $product->values = ['common' => ['maat' => $row[1]]];

        return $product;
    });

    expect(VariantOrder::sort($variants)->pluck('sku')->all())
        ->toBe(['DM1.2', 'DM1.2.O', 'DM1.3', 'DM1.10', 'DM1.0', 'DM1.0.O']);
});
