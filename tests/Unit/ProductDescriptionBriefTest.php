<?php

use App\Models\Product;
use App\Services\AI\ProductDescriptionBrief;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $common
 * @param  array<string, mixed>  $extra
 */
function makeBriefProduct(array $common, ?int $parentId = null, array $extra = []): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = 'BRIEF-'.uniqid();
    $product->type = $parentId ? 'simple' : 'configurable';
    $product->parent_id = $parentId;
    $product->status = 1;
    $product->values = ['common' => $common] + $extra;
    $product->save();

    return $product;
}

beforeEach(function () {
    $this->brief = new ProductDescriptionBrief();
});

it('splits pipe separated attributes into trimmed tokens', function () {
    $product = makeBriefProduct([
        'productnaam' => 'Diamante 01',
        'kleuren'     => 'Beige| Ecru |Creme',
        'materiaal'   => ' Wol|Jute',
    ]);

    $brief = $this->brief->build($product);

    expect($brief['uiterlijk']['kleuren'])->toBe(['Beige', 'Ecru', 'Creme'])
        ->and($brief['constructie']['materiaal'])->toBe(['Wol', 'Jute']);
});

it('drops the literal string null that a chunk of the catalogue carries', function () {
    $product = makeBriefProduct([
        'productnaam'   => 'Malta 6969',
        'loopvlak'      => 'null',
        'gewicht'       => 'null',
        'randafwerking' => 'Band',
    ]);

    $brief = $this->brief->build($product);

    expect($brief['constructie'])->not->toHaveKey('loopvlak')
        ->and($brief['constructie'])->not->toHaveKey('gewicht')
        ->and($brief['constructie']['randafwerking'])->toBe('Band');
});

it('aggregates the real sizes from the variants and separates round from rectangular', function () {
    $parent = makeBriefProduct(['productnaam' => 'Diamante 01']);

    makeBriefProduct(['maat' => '170 cm x 240 cm', 'onderkleed' => 'Zonder onderkleed'], $parent->id);
    makeBriefProduct(['maat' => '200 cm Rond', 'onderkleed' => 'Zonder onderkleed'], $parent->id);
    makeBriefProduct(['maat' => 'Maatwerk', 'onderkleed' => 'Zonder onderkleed'], $parent->id);

    $sizes = $this->brief->build($parent)['maten_en_levering'];

    expect($sizes['standaardmaten_rechthoekig'])->toBe(['170 cm x 240 cm'])
        ->and($sizes['standaardmaten_rond'])->toBe(['200 cm Rond'])
        ->and($sizes['maatwerk_mogelijk'])->toBe(['Maatwerk']);
});

it('takes the price range from the variants without an underlay', function () {
    $parent = makeBriefProduct(['productnaam' => 'Diamante 01']);

    makeBriefProduct(['maat' => '170 cm x 240 cm', 'onderkleed' => 'Zonder onderkleed', 'prijs' => ['EUR' => '1299']], $parent->id);
    makeBriefProduct(['maat' => '170 cm x 240 cm', 'onderkleed' => 'Met onderkleed', 'prijs' => ['EUR' => '1329']], $parent->id);
    makeBriefProduct(['maat' => '300 cm x 400 cm', 'onderkleed' => 'Zonder onderkleed', 'prijs' => ['EUR' => '3815']], $parent->id);
    makeBriefProduct(['maat' => '300 cm x 400 cm', 'onderkleed' => 'Met onderkleed', 'prijs' => ['EUR' => '3885']], $parent->id);

    $sizes = $this->brief->build($parent)['maten_en_levering'];

    expect($sizes['prijs_vanaf'])->toBe(1299.0)
        ->and($sizes['prijs_tot'])->toBe(3815.0);
});

it('reads lead times off the variants, where they actually live', function () {
    $parent = makeBriefProduct(['productnaam' => 'Diamante 01']);

    makeBriefProduct([
        'maat'                     => '170 cm x 240 cm',
        'levertijd_voorradig'      => '2-3 weken',
        'levertijd_niet_voorradig' => 'null',
    ], $parent->id);

    $sizes = $this->brief->build($parent)['maten_en_levering'];

    expect($sizes['levertijd_voorradig'])->toBe('2-3 weken')
        ->and($sizes)->not->toHaveKey('levertijd_niet_voorradig');
});

it('counts the colourways from cross_sells', function () {
    $parent = makeBriefProduct(
        ['productnaam' => 'Diamante 01'],
        null,
        ['associations' => ['cross_sells' => ['DMC0014', 'DMC0015', 'DMC0016']]],
    );

    expect($this->brief->build($parent)['context']['aantal_kleurvarianten'])->toBe(3);
});

it('lists every size a text is allowed to mention', function () {
    $parent = makeBriefProduct([
        'productnaam'         => 'Diamante 01',
        'maximale_breedte_cm' => '400 cm',
        'maximale_lengte_cm'  => '1200 cm',
    ]);

    makeBriefProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    expect($this->brief->allowedSizes($parent))
        ->toContain('170 cm x 240 cm', '400 cm', '1200 cm');
});

it('ignores variants that are switched off', function () {
    $parent = makeBriefProduct(['productnaam' => 'Diamante 01']);

    $disabled = makeBriefProduct(['maat' => '999 cm x 999 cm'], $parent->id);
    $disabled->status = 0;
    $disabled->save();

    expect($this->brief->allowedSizes($parent))->not->toContain('999 cm x 999 cm');
});

it('survives a double-encoded values column', function () {
    $product = makeBriefProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);

    DB::table('products')->where('id', $product->id)->update([
        'values' => json_encode(json_encode(['common' => ['productnaam' => 'Diamante 01', 'merk' => 'De Munk']])),
    ]);

    $brief = $this->brief->build(Product::find($product->id));

    expect($brief['identiteit']['productnaam'])->toBe('Diamante 01')
        ->and($brief['identiteit']['merk'])->toBe(['De Munk']);
});

it('builds a brief from raw form values for a product that does not exist yet', function () {
    $brief = $this->brief->buildFromValues([
        'sku'         => 'NIEUW-1',
        'productnaam' => 'Nieuw kleed',
        'kleuren'     => 'Grijs|Taupe',
        'materiaal'   => 'Wol',
    ]);

    expect($brief['identiteit']['sku'])->toBe('NIEUW-1')
        ->and($brief['uiterlijk']['kleuren'])->toBe(['Grijs', 'Taupe'])
        ->and($brief['maten_en_levering'])->toBe([]);
});
