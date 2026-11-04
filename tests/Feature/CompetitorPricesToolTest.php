<?php

use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceExclusion;
use App\Models\Product;
use App\Services\CompetitorPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * A rug with the given sizes. Each size: [suffix, maat, advies, competitor
 * prices as shop => price]; prices are fresh and our price is computed.
 *
 * @param  list<array{0: string, 1: string, 2: int, 3: array<string, int>}>  $sizes
 * @return array{parent: Product, variants: array<string, Product>, prices: array<string, CompetitorPrice>}
 */
function makeToolRug(string $name, array $sizes, string $brand = 'De Munk'): array
{
    $familyId = DB::table('attribute_families')->value('id');

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = 'TOOL-'.uniqid();
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => ['productnaam' => $name, 'merk' => $brand]];
    $parent->save();

    $variants = [];
    $prices = [];

    foreach ($sizes as [$suffix, $size, $advies, $competitors]) {
        $variant = new Product();
        $variant->attribute_family_id = $familyId;
        $variant->parent_id = $parent->id;
        $variant->sku = $parent->sku.$suffix;
        $variant->type = 'simple';
        $variant->status = 1;
        $variant->values = ['common' => [
            'maat'               => $size,
            'onderkleed'         => 'Zonder onderkleed',
            'prijs'              => ['EUR' => (string) $advies],
            'adviesverkoopprijs' => ['EUR' => (string) $advies],
        ]];
        $variant->save();

        foreach ($competitors as $shop => $price) {
            $prices[$suffix.'@'.$shop] = CompetitorPrice::query()->create(['sku' => $variant->sku, 'shop' => $shop, 'price' => $price, 'scraped_at' => now()]);
        }

        $variants[$suffix] = $variant;
    }

    app(CompetitorPricingService::class)->recomputeForSkus(collect($variants)->pluck('sku')->all());

    return ['parent' => $parent, 'variants' => $variants, 'prices' => $prices];
}

function toolPrice(Product $variant): string
{
    return $variant->fresh()->values['common']['prijs']['EUR'];
}

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    $this->actingAs(Admin::factory()->create(), 'admin');
});

it('lists only the sizes discounted at least the chosen percentage, deepest first', function () {
    $deep = makeToolRug('Diep kleed', [
        ['.1', '200 cm x 300 cm', 1000, ['goedkoop.nl' => 760, 'duur.nl' => 990]],
        ['.2', '160 cm x 230 cm', 1000, ['goedkoop.nl' => 950]],
        ['.3', '250 cm x 350 cm', 1000, []],
    ]);
    $mild = makeToolRug('Mild kleed', [['.1', '200 cm x 300 cm', 1000, ['goedkoop.nl' => 850]]]);
    makeToolRug('Vol kleed', [['.1', '200 cm x 300 cm', 1000, []]]);

    $response = $this->get(route('admin.tools.competitor-prices.index'))->assertOk();

    $rugs = $response->viewData('rugs')->getCollection();

    expect($rugs->pluck('name')->all())->toBe(['Diep kleed'])
        ->and(array_column($rugs[0]['sizes'], 'sku'))->toBe([$deep['variants']['.1']->sku])
        ->and(array_column($rugs[0]['sizes'][0]['rows'], 'shop'))->toBe(['Huis & Wonen', 'goedkoop.nl', 'duur.nl'])
        ->and($rugs[0]['sizes'][0]['discount'])->toEqualWithDelta(24.0, 0.01)
        ->and($response->viewData('sizeCount'))->toBe(1);

    $wider = $this->get(route('admin.tools.competitor-prices.index', ['korting' => 10]))->viewData('rugs')->getCollection();

    expect($wider->pluck('name')->all())->toBe(['Diep kleed', 'Mild kleed']);

    $response->assertSee('Bepaalt onze prijs')
        ->assertSee('gelijk')
        ->assertSee('+€ 230', false)
        ->assertSee('(+30,3%)', false)
        ->assertSee('−24,0%', false)
        ->assertSee('t.o.v. advies')
        ->assertSee('top: calc(57px + var(--cp-bar-h, 0px))', false)
        ->assertSee('top: calc(57px + var(--cp-bar-h, 0px) + var(--cp-rug-h, 0px))', false)
        ->assertDontSee($deep['variants']['.2']->sku)
        ->assertDontSee($deep['variants']['.3']->sku);
});

it('filters on brand, competitor and name', function () {
    makeToolRug('Munk kleed', [['.1', '200 cm x 300 cm', 1000, ['a.nl' => 700]]], 'De Munk');
    makeToolRug('Karpi kleed', [['.1', '200 cm x 300 cm', 1000, ['b.nl' => 700]]], 'Karpi');

    $names = fn (array $query) => $this->get(route('admin.tools.competitor-prices.index', $query))->viewData('rugs')->getCollection()->pluck('name')->all();

    expect($names(['merk' => 'Karpi']))->toBe(['Karpi kleed'])
        ->and($names(['winkel' => 'a.nl']))->toBe(['Munk kleed'])
        ->and($names(['zoek' => 'karpi']))->toBe(['Karpi kleed']);
});

it('rejects a percentage outside 0 to 100', function () {
    $this->get(route('admin.tools.competitor-prices.index', ['korting' => 150]))
        ->assertSessionHasErrors('korting');
});

it('excludes a selection of price points across rugs and recomputes once per size', function () {
    $one = makeToolRug('Een', [['.1', '200 cm x 300 cm', 1000, ['fout.nl' => 760, 'goed.nl' => 900]]]);
    $two = makeToolRug('Twee', [['.1', '200 cm x 300 cm', 1000, ['fout.nl' => 780, 'goed.nl' => 950]]]);

    expect(toolPrice($one['variants']['.1']))->toBe('760');

    $this->from(route('admin.tools.competitor-prices.index'))
        ->post(route('admin.tools.competitor-prices.update'), [
            'action' => 'exclude',
            'ids'    => [$one['prices']['.1@fout.nl']->id, $two['prices']['.1@fout.nl']->id],
            'reason' => 'Ander kleed',
        ])
        ->assertRedirect(route('admin.tools.competitor-prices.index'))
        ->assertSessionHas('success', '2 prijzen uitgesloten; 2 maten kregen een nieuwe prijs, die naar de webshop en Bol gaat.');

    expect(toolPrice($one['variants']['.1']))->toBe('900')
        ->and(toolPrice($two['variants']['.1']))->toBe('950')
        ->and(CompetitorPriceExclusion::query()->where('reason', 'Ander kleed')->count())->toBe(2);
});

it('excludes or re-includes the single price of a row button and ignores the checked boxes', function () {
    $rug = makeToolRug('Rij', [['.1', '200 cm x 300 cm', 1000, ['a.nl' => 760, 'b.nl' => 800, 'c.nl' => 900]]]);
    $a = $rug['prices']['.1@a.nl'];
    $b = $rug['prices']['.1@b.nl'];

    $this->post(route('admin.tools.competitor-prices.update'), [
        'row_action' => 'exclude:'.$a->id,
        'ids'        => [$b->id],
    ])->assertSessionHas('success');

    expect(CompetitorPriceExclusion::query()->pluck('shop')->all())->toBe(['a.nl'])
        ->and(toolPrice($rug['variants']['.1']))->toBe('800');

    $this->post(route('admin.tools.competitor-prices.update'), ['row_action' => 'include:'.$a->id])
        ->assertSessionHas('success', '1 prijs telt weer mee; 1 maat kreeg een nieuwe prijs, die naar de webshop en Bol gaat.');

    expect(CompetitorPriceExclusion::query()->count())->toBe(0)
        ->and(toolPrice($rug['variants']['.1']))->toBe('760');
});

it('asks for a selection before a bulk action', function () {
    $this->post(route('admin.tools.competitor-prices.update'), ['action' => 'exclude'])
        ->assertSessionHasErrors('ids');
});

it('shows excluded prices struck out and lets nobody without edit rights exclude', function () {
    $rug = makeToolRug('Streep', [['.1', '200 cm x 300 cm', 1000, ['a.nl' => 760, 'b.nl' => 790]]]);
    CompetitorPriceExclusion::query()->create(['sku' => $rug['variants']['.1']->sku, 'shop' => 'a.nl', 'price' => 760, 'reason' => 'Actieprijs']);

    $this->get(route('admin.tools.competitor-prices.index'))
        ->assertSee('cp-excluded')
        ->assertSee('Uitgesloten')
        ->assertSee('Actieprijs');

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['simple-stock']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    $this->post(route('admin.tools.competitor-prices.update'), ['row_action' => 'include:'.$rug['prices']['.1@a.nl']->id])
        ->assertForbidden();
});

it('shows how far a price sits above the advies price too', function () {
    makeToolRug('Boven advies', [['.1', '200 cm x 300 cm', 1000, ['laag.nl' => 760, 'hoog.nl' => 1023, 'gelijk.nl' => 1000]]]);

    $response = $this->get(route('admin.tools.competitor-prices.index'))->assertOk();

    $rows = collect($response->viewData('rugs')->getCollection()[0]['sizes'][0]['rows'])->keyBy('shop');

    expect($rows['hoog.nl']['below_advice'])->toEqualWithDelta(-2.3, 0.001)
        ->and($rows['laag.nl']['below_advice'])->toEqualWithDelta(24.0, 0.001);

    $response->assertSee('<span class="cp-pricier">+2,3%</span>', false)
        ->assertSee('<span class="cp-cheaper">−24,0%</span>', false);
});

it('lists every excluded price, also once the size dropped below the threshold', function () {
    $excluded = makeToolRug('Uitgesloten kleed', [
        ['.1', '200 cm x 300 cm', 1000, ['fout.nl' => 700, 'goed.nl' => 950]],
        ['.2', '160 cm x 230 cm', 1000, ['goed.nl' => 950]],
    ]);
    makeToolRug('Gewoon kleed', [['.1', '200 cm x 300 cm', 1000, ['a.nl' => 760]]]);
    $changed = makeToolRug('Veranderd kleed', [['.1', '200 cm x 300 cm', 1000, ['b.nl' => 780]]]);

    $this->post(route('admin.tools.competitor-prices.update'), ['action' => 'exclude', 'ids' => [
        $excluded['prices']['.1@fout.nl']->id,
        $changed['prices']['.1@b.nl']->id,
    ]])->assertSessionHas('success');

    /* The competitor changed its price: the exclusion no longer applies. */
    $changed['prices']['.1@b.nl']->update(['price' => 790]);

    expect(toolPrice($excluded['variants']['.1']))->toBe('950');

    $normal = $this->get(route('admin.tools.competitor-prices.index'))->assertOk();

    expect($normal->viewData('rugs')->getCollection()->pluck('name')->all())->not->toContain('Uitgesloten kleed')
        ->and($normal->viewData('excludedCount'))->toBe(1);

    $normal->assertSee('Alle 1 uitgesloten prijs bekijken');

    $response = $this->get(route('admin.tools.competitor-prices.index', ['uitgesloten' => 1, 'korting' => 50]))->assertOk();

    $rugs = $response->viewData('rugs')->getCollection();

    expect($rugs->pluck('name')->all())->toBe(['Uitgesloten kleed'])
        ->and(array_column($rugs[0]['sizes'], 'sku'))->toBe([$excluded['variants']['.1']->sku])
        ->and(collect($rugs[0]['sizes'][0]['rows'])->firstWhere('shop', 'fout.nl')['excluded'])->toBeTrue()
        ->and($response->viewData('sizeCount'))->toBe(1);

    $response->assertSee('1 uitgesloten prijs', false)
        ->assertSee('Terug naar de kortingen');
});

it('says so when nothing is excluded', function () {
    makeToolRug('Niets uitgesloten', [['.1', '200 cm x 300 cm', 1000, ['a.nl' => 760]]]);

    $this->get(route('admin.tools.competitor-prices.index', ['uitgesloten' => 1]))
        ->assertOk()
        ->assertSee('Geen uitgesloten prijzen.');
});
