<?php

use App\Models\CompetitorPrice;
use App\Models\CompetitorPriceExclusion;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * @param  array<string, mixed>  $common
 */
function makeStockLookupProduct(array $common, ?int $parentId = null, string $sku = '', int $status = 1): Product
{
    $product = new Product();
    $product->attribute_family_id = DB::table('attribute_families')->value('id');
    $product->sku = $sku !== '' ? $sku : 'VOORRAAD-'.uniqid();
    $product->type = $parentId ? 'simple' : 'configurable';
    $product->parent_id = $parentId;
    $product->status = $status;
    $product->values = ['common' => $common];
    $product->save();

    return $product;
}

/**
 * @param  list<string>  $permissions
 */
function loginWithPermissions(array $permissions): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/**
 * A rug "Firenze 04" by De Munk with three sizes (each with its .O twin)
 * and a maatwerk variant.
 */
function makeFirenze(): Product
{
    $parent = makeStockLookupProduct([
        'productnaam'        => 'Firenze 04',
        'merk'               => 'De Munk',
        'collectie'          => 'Modern',
        'kleuren'            => 'Taupe',
        'materiaal'          => 'Wol',
        'poolhoogte'         => '10 mm',
        'maximale_breedte_cm'=> '400 cm',
        'prijs_per_m2'       => ['EUR' => '362'],
        'beschrijving_l'     => '<p>Handgeweven van <strong>wol</strong> &amp; jute.</p><p><script>alert(1)</script>Tweezijdig.</p>',
    ], sku: 'LOOKUP-FIR');

    foreach ([
        ['200 cm x 300 cm', 1, 0, 1, '2169', 230],
        ['170 cm x 240 cm', 0, 2, 0, '1319', 180],
        ['250 cm x 350 cm', 0, 0, 0, '3165', 270],
    ] as $i => [$size, $showroom, $supplier, $sale, $price, $sort]) {
        foreach (['Zonder onderkleed' => '', 'Met onderkleed' => '.O'] as $underlay => $suffix) {
            makeStockLookupProduct([
                'maat'                         => $size,
                'onderkleed'                   => $underlay,
                'voorraad_hw_5_korting'        => $showroom,
                'voorraad_5_korting_handmatig' => $supplier,
                'voorraad_eurogros'            => 'null',
                'uitverkoop_15_korting'        => $sale,
                'prijs'                        => ['EUR' => $suffix === '' ? $price : (string) ($price + 40)],
                'adviesverkoopprijs'           => ['EUR' => $suffix === '' ? $price + 100 : null],
                'sorteer_volgorde'             => $sort,
                'ean'                          => $suffix === '' ? '871000000000'.$i : '',
            ], $parent->id, "LOOKUP-FIR.{$i}{$suffix}");
        }
    }

    makeStockLookupProduct(['maat' => 'Maatwerk', 'onderkleed' => 'Zonder onderkleed', 'prijs' => ['EUR' => 362]], $parent->id, 'LOOKUP-FIR.M');

    return $parent;
}

beforeEach(function () {
    $this->withoutVite();
});

it('finds a rug case-insensitively by any of its words and lists each size once', function () {
    loginWithPermissions(['simple-stock']);
    makeFirenze();
    makeStockLookupProduct(['productnaam' => 'Firenze 05', 'merk' => 'Karpi'], sku: 'LOOKUP-OTHER');

    $response = $this->get(route('admin.simple-stock.index', ['q' => 'firenze de munk']))->assertOk();

    $rugs = $response->viewData('rugs');

    expect($rugs)->toHaveCount(1)
        ->and($rugs[0]['name'])->toBe('Firenze 04')
        ->and(array_column($rugs[0]['sizes'], 'size'))->toBe(['170 cm x 240 cm', '200 cm x 300 cm', '250 cm x 350 cm', 'Maatwerk'])
        ->and($rugs[0]['sizes'][1])->toMatchArray(['showroom' => 1, 'supplier' => 0, 'sale' => 1, 'price' => 2169.0])
        ->and($rugs[0]['sizes'][0])->toMatchArray(['showroom' => 0, 'supplier' => 2])
        ->and($rugs[0]['sizes'][3]['custom'])->toBeTrue();

    $response->assertSee('Firenze 04')->assertDontSee('Firenze 05')->assertSee('Op maat gemaakt');
});

it('finds a rug by the sku or ean of one of its sizes', function (string $query) {
    loginWithPermissions(['simple-stock']);
    makeFirenze();

    expect($this->get(route('admin.simple-stock.index', ['q' => $query]))->viewData('rugs'))->toHaveCount(1);
})->with([
    'variant sku' => ['lookup-fir.1'],
    'ean'         => ['8710000000001'],
]);

it('narrows to stocked sizes or to the showroom', function () {
    loginWithPermissions(['simple-stock']);
    makeFirenze();
    makeStockLookupProduct(['productnaam' => 'Firenze leeg'], sku: 'LOOKUP-EMPTY');

    $inStock = $this->get(route('admin.simple-stock.index', ['q' => 'firenze', 'voorraad' => 'op-voorraad']))->viewData('rugs');

    expect($inStock)->toHaveCount(1)
        ->and(array_column($inStock[0]['sizes'], 'size'))->toBe(['170 cm x 240 cm', '200 cm x 300 cm']);

    $showroom = $this->get(route('admin.simple-stock.index', ['voorraad' => 'showroom']))->viewData('rugs');
    $firenze = collect($showroom)->firstWhere('sku', 'LOOKUP-FIR');

    expect($firenze)->not->toBeNull()
        ->and(array_column($firenze['sizes'], 'size'))->toBe(['200 cm x 300 cm'])
        ->and(collect($showroom)->firstWhere('sku', 'LOOKUP-EMPTY'))->toBeNull();
});

it('shows nothing until something is searched', function () {
    loginWithPermissions(['simple-stock']);
    makeFirenze();

    $this->get(route('admin.simple-stock.index'))
        ->assertOk()
        ->assertViewHas('rugs', [])
        ->assertSee('Zoek op naam');
});

it('finds a rug by its colour', function () {
    loginWithPermissions(['simple-stock']);
    makeFirenze();

    expect($this->get(route('admin.simple-stock.index', ['q' => 'firenze taupe']))->viewData('rugs'))->toHaveCount(1);
});

it('shows all details of a rug', function () {
    loginWithPermissions(['simple-stock']);
    $parent = makeFirenze();

    $response = $this->get(route('admin.simple-stock.show', ['rug' => $parent->id, 'q' => 'firenze']))->assertOk();

    $rug = $response->viewData('rug');

    expect($rug['specifications'])->toMatchArray([
        'Materiaal'              => 'Wol',
        'Poolhoogte'             => '10 mm',
        'Kleur'                  => 'Taupe',
        'Maatwerk: max. breedte' => '400 cm',
        'Maatwerk: prijs per m²' => '€ 362,00',
    ])
        ->and($rug['description'])->toBe(['Handgeweven van wol & jute.', 'alert(1)Tweezijdig.'])
        ->and($rug['sizes'][1])->toMatchArray([
            'size'           => '200 cm x 300 cm',
            'sku'            => 'LOOKUP-FIR.0',
            'ean'            => '8710000000000',
            'price'          => 2169.0,
            'advice_price'   => 2269.0,
            'underlay_price' => 2209.0,
        ]);

    $response->assertSee('Handgeweven van wol &amp; jute.', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee(route('admin.simple-stock.index', ['q' => 'firenze']), false);
});

it('links to the other colours of a rug', function () {
    loginWithPermissions(['simple-stock']);
    $other = makeStockLookupProduct(['productnaam' => 'Firenze 05', 'kleuren' => 'Grijs|Taupe'], sku: 'LOOKUP-FIR5');
    $parent = makeFirenze();
    $parent->values = ['common' => $parent->values['common'], 'associations' => ['cross_sells' => ['LOOKUP-FIR', 'LOOKUP-FIR5']]];
    $parent->save();

    $related = $this->get(route('admin.simple-stock.show', $parent->id))->viewData('rug')['related'];

    expect($related)->toHaveCount(1)
        ->and($related[0])->toMatchArray(['id' => $other->id, 'name' => 'Firenze 05', 'colour' => 'Grijs, Taupe']);
});

it('does not open a size as a rug', function () {
    loginWithPermissions(['simple-stock']);
    $variantId = makeFirenze()->id + 1;

    $this->get(route('admin.simple-stock.show', $variantId))->assertNotFound();
});

it('hides edit and full PIM links from a stock-only role', function () {
    loginWithPermissions(['simple-stock']);
    $parent = makeFirenze();

    $this->get(route('admin.simple-stock.show', $parent->id))
        ->assertOk()
        ->assertDontSee(route('admin.catalog.products.edit', $parent->id))
        ->assertDontSee('Volledige PIM');
});

it('shows edit and full PIM links to a full admin', function () {
    test()->actingAs(Admin::factory()->create(), 'admin');
    $parent = makeFirenze();

    $this->get(route('admin.simple-stock.show', $parent->id))
        ->assertSee(route('admin.catalog.products.edit', $parent->id))
        ->assertSee('Volledige PIM');
});

it('refuses a role without the permission', function () {
    loginWithPermissions(['dashboard']);

    $this->get(route('admin.simple-stock.index'))->assertUnauthorized();

    $parent = makeFirenze();

    $this->get(route('admin.simple-stock.show', $parent->id))->assertUnauthorized();
});

it('sends a stock-only role to the lookup after login', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['simple-stock']]);
    $admin = Admin::factory()->create(['role_id' => $role->id, 'password' => bcrypt('geheim123')]);

    $this->withSession(['url.intended' => route('admin.dashboard.index')])
        ->post(route('admin.session.store'), ['email' => $admin->email, 'password' => 'geheim123'])
        ->assertRedirect(route('admin.simple-stock.index'));
});

it('still sends a full admin to the dashboard after login', function () {
    $admin = Admin::factory()->create(['password' => bcrypt('geheim123')]);

    $this->post(route('admin.session.store'), ['email' => $admin->email, 'password' => 'geheim123'])
        ->assertRedirect(route('admin.dashboard.index'));
});

it('ranks every competitor price per size around our own price', function () {
    loginWithPermissions(['simple-stock']);
    $parent = makeFirenze();

    foreach ([
        ['kleed.nl', 2299, 1],
        ['karpettenkelder.nl', 2099, 2],
        ['vloerkledenspecialist.nl', 2399, 40],
    ] as [$shop, $price, $daysAgo]) {
        CompetitorPrice::query()->create([
            'sku'        => 'LOOKUP-FIR.0',
            'shop'       => $shop,
            'price'      => $price,
            'url'        => "https://{$shop}/firenze-04",
            'scraped_at' => now()->subDays($daysAgo),
        ]);
    }

    CompetitorPrice::query()->create(['sku' => 'LOOKUP-FIR.0.O', 'shop' => 'kleed.nl', 'price' => 99, 'scraped_at' => now()]);

    $response = $this->get(route('admin.simple-stock.show', $parent->id))->assertOk();

    $analysis = collect($response->viewData('rug')['price_analysis'])->keyBy('size');
    $size = $analysis['200 cm x 300 cm'];

    expect($size['competitors'])->toBe(3)
        ->and($size['cheapest'])->toBe(['shop' => 'karpettenkelder.nl', 'price' => 2099.0])
        ->and(array_column($size['rows'], 'shop'))->toBe(['karpettenkelder.nl', 'Huis & Wonen', 'kleed.nl', 'vloerkledenspecialist.nl'])
        ->and(array_column($size['rows'], 'stale'))->toBe([false, false, false, true])
        ->and($size['rows'][0]['difference'])->toBe(-70.0)
        ->and($size['rows'][2]['difference'])->toBe(130.0)
        ->and($analysis['170 cm x 240 cm']['competitors'])->toBe(0)
        ->and($analysis->has('Maatwerk'))->toBeFalse();

    $response->assertSee('Prijsanalyse concurrenten')
        ->assertSee('https://karpettenkelder.nl/firenze-04', false)
        ->assertSee('−€ 70 (−3%)', false)
        ->assertSee('Geen concurrentprijs gevonden voor: 170 cm x 240 cm, 250 cm x 350 cm.', false);
});

it('marks an excluded competitor price on the lookup page', function () {
    loginWithPermissions(['simple-stock']);
    $parent = makeFirenze();

    CompetitorPrice::query()->create(['sku' => 'LOOKUP-FIR.0', 'shop' => 'kleed.nl', 'price' => 1999, 'scraped_at' => now()]);
    CompetitorPriceExclusion::query()->create(['sku' => 'LOOKUP-FIR.0', 'shop' => 'kleed.nl', 'price' => 1999, 'reason' => 'Ander kleed']);

    $response = $this->get(route('admin.simple-stock.show', $parent->id))->assertOk();

    $row = collect(collect($response->viewData('rug')['price_analysis'])->firstWhere('sku', 'LOOKUP-FIR.0')['rows'])->firstWhere('shop', 'kleed.nl');

    expect($row)->toMatchArray(['excluded' => true, 'counts' => false]);

    $response->assertSee('class="stale excluded"', false)
        ->assertSee('<span class="sl-excluded-label" title="Ander kleed">uitgesloten</span>', false);
});
