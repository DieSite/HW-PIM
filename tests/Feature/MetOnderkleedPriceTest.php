<?php

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;

/**
 * A parent with both onderkleed variants of one size. Returns the met-onderkleed
 * variant, since that is the one the "Prijs berekenen" button runs on.
 */
function makeOnderkleedVariantPair(array $zonder, array $met, string $size = '160 cm x 230 cm'): Product
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
        // Expliciete waarden winnen van de standaardmaat en het standaard
        // onderkleed, zodat een test de twee varianten uit elkaar kan laten lopen.
        $variant->values = ['common' => array_merge([
            'maat'       => $size,
            'onderkleed' => $onderkleed,
        ], $values)];
        $variant->save();

        $variants[$onderkleed] = $variant;
    }

    return $variants['Met onderkleed'];
}

/**
 * Een met-onderkleed-variant zonder kale tegenhanger, zoals een pas aangemaakt
 * product waarvan alleen de bundelvariant al bestaat.
 */
function makeLonelyMetOnderkleed(string $size = '160 cm x 230 cm'): Product
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

    $variant = new Product();
    $variant->attribute_family_id = $familyId;
    $variant->parent_id = $parent->id;
    $variant->sku = 'OKTEST-MET';
    $variant->type = 'simple';
    $variant->status = 1;
    $variant->values = ['common' => [
        'maat'       => $size,
        'onderkleed' => 'Met onderkleed',
        'prijs'      => ['EUR' => '0'],
    ]];
    $variant->save();

    return $variant;
}

afterEach(function () {
    Product::where('sku', 'like', 'OKTEST-%')->delete();
});

it('derives the adviesverkoopprijs from the bare variant plus the surcharge', function () {
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800'], 'adviesverkoopprijs' => ['EUR' => '1000']],
        met: ['prijs' => ['EUR' => '0'], 'adviesverkoopprijs' => ['EUR' => '0']],
    );

    $service = app(ProductService::class);

    expect($service->calculateMetOnderkleedPrice($met))->toBe('830')
        ->and($service->calculateMetOnderkleedAdviesPrice($met))->toBe('1030');
});

it('leaves the adviesverkoopprijs alone when the bare variant has none', function () {
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800']],
        met: ['prijs' => ['EUR' => '0']],
    );

    expect(app(ProductService::class)->calculateMetOnderkleedAdviesPrice($met))->toBeNull();
});

it('still derives the adviesverkoopprijs when the size has no surcharge', function () {
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800'], 'adviesverkoopprijs' => ['EUR' => '1000']],
        met: ['prijs' => ['EUR' => '0']],
        size: '999 cm x 999 cm',
    );

    $service = app(ProductService::class);

    expect($service->calculateMetOnderkleedPrice($met))->toBe('800')
        ->and($service->calculateMetOnderkleedAdviesPrice($met))->toBe('1000');
});

it('returns both derived prices from the price endpoint', function () {
    $met = makeOnderkleedVariantPair(
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

it('finds the surcharge for a round size however the maat is spelled', function (string $size) {
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800'], 'adviesverkoopprijs' => ['EUR' => '1000']],
        met: ['prijs' => ['EUR' => '0']],
        size: $size,
    );

    $service = app(ProductService::class);

    expect($service->calculateMetOnderkleedPrice($met))->toBe('850')
        ->and($service->calculateMetOnderkleedAdviesPrice($met))->toBe('1050');
})->with(['Rond 240 cm', '240 cm Rond', '240 cm rond', '  240 CM ROND ']);

it('falls back to the maatgroep when the maat itself is not in the table', function () {
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800']],
        met: ['prijs' => ['EUR' => '0'], 'maatgroep' => 'Rond 240 cm'],
        size: 'Rond 240 cm (op maat gesneden)',
    );

    expect(app(ProductService::class)->calculateMetOnderkleedPrice($met))->toBe('850');
});

it('warns through the endpoint when no surcharge is known for the size', function () {
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800']],
        met: ['prijs' => ['EUR' => '0']],
        size: '999 cm x 999 cm',
    );

    $response = $this->postJson('/product/met_onderkleed_price', ['sku' => $met->sku])
        ->assertOk()
        ->assertJson(['price' => '800', 'error' => null]);

    expect($response->json('warning'))->toContain('999 cm x 999 cm');
});

it('reports through the endpoint that there is no bare counterpart to derive from', function () {
    $met = makeLonelyMetOnderkleed();

    $response = $this->postJson('/product/met_onderkleed_price', ['sku' => $met->sku])
        ->assertOk()
        ->assertJson(['price' => null, 'advies_price' => null]);

    expect($response->json('error'))->toContain('Zonder onderkleed');
});

it('reports through the endpoint that the sku is unknown', function () {
    $response = $this->postJson('/product/met_onderkleed_price', ['sku' => 'OKTEST-BESTAAT-NIET'])
        ->assertOk();

    expect($response->json('error'))->toContain('OKTEST-BESTAAT-NIET');
});

it('finds the bare counterpart when the two variants spell the same size differently', function () {
    // De catalogus schrijft dezelfde ronde maat door elkaar heen; de kale
    // variant stond daardoor voor de knop niet te bestaan.
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800'], 'maat' => 'Rond 240 cm'],
        met: ['prijs' => ['EUR' => '0']],
        size: '240 cm Rond',
    );

    $this->postJson('/product/met_onderkleed_price', ['sku' => $met->sku])
        ->assertOk()
        ->assertJson(['price' => '850', 'original_price' => '800', 'error' => null]);
});

it('finds the bare counterpart when its onderkleed is written in another case', function () {
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800'], 'onderkleed' => 'zonder onderkleed '],
        met: ['prijs' => ['EUR' => '0']],
    );

    $this->postJson('/product/met_onderkleed_price', ['sku' => $met->sku])
        ->assertOk()
        ->assertJson(['price' => '830', 'original_price' => '800', 'error' => null]);
});

it('finds the bare counterpart even when its values column is double encoded', function () {
    // ~3888 producten hebben `values` als JSON-string van het object staan;
    // elke `values->common->...`-query mist die rij stilzwijgend, waarna de
    // knop een lege prijs teruggaf.
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800'], 'adviesverkoopprijs' => ['EUR' => '1000']],
        met: ['prijs' => ['EUR' => '0']],
    );

    $zonder = Product::where('sku', 'OKTEST-ZONDER')->firstOrFail();
    DB::table('products')->where('id', $zonder->id)->update([
        'values' => json_encode(json_encode($zonder->values)),
    ]);

    $this->postJson('/product/met_onderkleed_price', ['sku' => $met->sku])
        ->assertOk()
        ->assertJson([
            'price'          => '830',
            'original_price' => '800',
            'advies_price'   => '1030',
            'error'          => null,
        ]);
});

it('names the sizes that do have a bare variant when the match fails', function () {
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800']],
        met: ['prijs' => ['EUR' => '0']],
    );

    // De maat van de bundelvariant wijkt af van elke kale variant.
    $values = $met->values;
    $values['common']['maat'] = '999 cm x 999 cm';
    $met->values = $values;
    $met->save();

    $response = $this->postJson('/product/met_onderkleed_price', ['sku' => $met->sku])
        ->assertOk()
        ->assertJson(['price' => null]);

    expect($response->json('error'))->toContain('999 cm x 999 cm')
        ->and($response->json('error'))->toContain('160 cm x 230 cm');
});

it('names a sibling that is missing its maat or onderkleed', function () {
    // De echte kapotte gevallen: de kale variant heeft geen maat, of helemaal
    // geen waarde bij 'Onderkleed'. Zonder die aanwijzing zegt de knop alleen
    // dat er niets te vinden is.
    $met = makeOnderkleedVariantPair(
        zonder: ['prijs' => ['EUR' => '800'], 'maat' => ''],
        met: ['prijs' => ['EUR' => '0']],
        size: 'Maatwerk',
    );

    $response = $this->postJson('/product/met_onderkleed_price', ['sku' => $met->sku])->assertOk();

    expect($response->json('error'))->toContain('OKTEST-ZONDER (geen maat)');
});
