<?php

use App\Enums\ProductKind;
use App\Models\Product;
use App\Services\CompetitorPricingService;
use App\Services\CushionFilling;
use App\Services\ProductService;
use App\Services\VariantOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;

function cushionFamilyId(): int
{
    return (int) (DB::table('attribute_families')->where('code', 'kussens')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'kussens', 'status' => 0]));
}

/**
 * A cushion parent with one variant per given common-values array.
 *
 * @param  list<array<string, mixed>>  $variants
 * @return array{parent: Product, variants: list<Product>}
 */
function makeCushion(array $variants): array
{
    $familyId = cushionFamilyId();

    $parent = new Product();
    $parent->attribute_family_id = $familyId;
    $parent->sku = 'KUSTEST-PARENT';
    $parent->type = 'configurable';
    $parent->status = 1;
    $parent->values = ['common' => ['productnaam' => 'Testkussen']];
    $parent->save();

    $made = [];

    foreach ($variants as $index => $common) {
        $variant = new Product();
        $variant->attribute_family_id = $familyId;
        $variant->parent_id = $parent->id;
        $variant->sku = 'KUSTEST-'.$index;
        $variant->type = 'simple';
        $variant->status = 1;
        $variant->values = ['common' => $common];
        $variant->save();

        $made[] = $variant;
    }

    return ['parent' => $parent, 'variants' => $made];
}

function commonOf(Product $product): array
{
    return app(ProductService::class)->commonValues($product->fresh());
}

beforeEach(function () {
    Event::fake(['catalog.product.update.after']);
    config(['cushions.filling_surcharge' => ['45 x 45 cm' => 12.5]]);
});

afterEach(function () {
    Product::where('sku', 'like', 'KUSTEST-%')->delete();
});

it('tells a cushion from a rug by its attribute family', function () {
    ['parent' => $cushion] = makeCushion([]);

    $rug = new Product(['attribute_family_id' => cushionFamilyId() + 1000]);

    expect(ProductKind::of($cushion))->toBe(ProductKind::Cushion)
        ->and(ProductKind::of($rug))->toBe(ProductKind::Rug);
});

it('derives the met-vulling price and copies stock when the zonder-vulling variant is saved', function () {
    ['variants' => [$zonder, $met]] = makeCushion([
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Zonder vulling', 'prijs' => ['EUR' => '30'], 'adviesverkoopprijs' => ['EUR' => '40'], 'voorraad_hw_5_korting' => 3],
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Met vulling', 'prijs' => ['EUR' => '0'], 'voorraad_hw_5_korting' => 0],
    ]);

    app(ProductService::class)->copyStockValuesOnderkleed($zonder->fresh());

    expect(commonOf($met))
        ->prijs->toBe(['EUR' => '42.5'])
        ->adviesverkoopprijs->toBe(['EUR' => '52.5'])
        ->voorraad_hw_5_korting->toBe(3);
});

it('re-derives its own price when the met-vulling variant is saved with another price', function () {
    ['variants' => [$zonder, $met]] = makeCushion([
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Zonder vulling', 'prijs' => ['EUR' => '30'], 'voorraad_hw_5_korting' => 0],
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Met vulling', 'prijs' => ['EUR' => '99'], 'voorraad_hw_5_korting' => 4],
    ]);

    app(ProductService::class)->copyStockValuesOnderkleed($met->fresh());

    expect(commonOf($met)['prijs'])->toBe(['EUR' => '42.5'])
        ->and(commonOf($zonder)['voorraad_hw_5_korting'])->toBe(4);
});

it('pairs variants by size and colour only', function () {
    ['variants' => [$groen, $blauwMet, $groenMet]] = makeCushion([
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Zonder vulling'],
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Blauw', 'vulling' => 'Met vulling'],
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Met vulling'],
    ]);

    expect(app(CushionFilling::class)->twin($groen->fresh())?->id)->toBe($groenMet->id);
});

it('leaves the met-vulling price alone for a size without a surcharge', function () {
    ['variants' => [$zonder, $met]] = makeCushion([
        ['kussenmaat' => '60 x 60 cm', 'kleur' => 'Groen', 'vulling' => 'Zonder vulling', 'prijs' => ['EUR' => '30']],
        ['kussenmaat' => '60 x 60 cm', 'kleur' => 'Groen', 'vulling' => 'Met vulling', 'prijs' => ['EUR' => '55']],
    ]);

    app(ProductService::class)->copyStockValuesOnderkleed($zonder->fresh());

    expect(commonOf($met)['prijs'])->toBe(['EUR' => '55']);
});

it('re-derives every met-vulling price after the parent is saved', function () {
    ['parent' => $parent, 'variants' => [, $met]] = makeCushion([
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Zonder vulling', 'prijs' => ['EUR' => '20']],
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Met vulling', 'prijs' => ['EUR' => '0']],
    ]);

    $changed = app(CushionFilling::class)->syncPrices($parent->fresh());

    expect($changed)->toHaveCount(1)
        ->and(commonOf($met)['prijs'])->toBe(['EUR' => '32.5']);
});

it('never lets the competitor pricing overwrite a cushion price', function () {
    ['variants' => [$zonder]] = makeCushion([
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Zonder vulling', 'prijs' => ['EUR' => '30'], 'adviesverkoopprijs' => ['EUR' => '40']],
    ]);

    expect(app(CompetitorPricingService::class)->recompute($zonder->fresh()))->toBeNull()
        ->and(commonOf($zonder)['prijs'])->toBe(['EUR' => '30']);
});

it('sorts cushion variants by size and colour, met vulling after zonder', function () {
    ['variants' => $variants] = makeCushion([
        ['kussenmaat' => '50 x 50 cm', 'kleur' => 'Groen', 'vulling' => 'Zonder vulling'],
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Met vulling'],
        ['kussenmaat' => '45 x 45 cm', 'kleur' => 'Groen', 'vulling' => 'Zonder vulling'],
    ]);

    expect(VariantOrder::sort(collect($variants))->pluck('sku')->all())
        ->toBe(['KUSTEST-2', 'KUSTEST-1', 'KUSTEST-0']);
});

it('writes a cushion meta title', function () {
    expect(app(ProductService::class)->generateMetaTitle('Velvet', 'Brinker', ProductKind::Cushion))
        ->toBe('Kussen Velvet van Brinker bij Huis & Wonen');
});

it('exports the cushion axes as WooCommerce variation attributes', function () {
    $exporter = new class extends Exporter
    {
        public function __construct() {}

        public function cushionAttributes(array $item): array
        {
            $formatted = ['attributes' => []];
            $this->formatCushionVariationAttributes($formatted, $item);

            return $formatted;
        }

        protected function getDataTransferMapping(string $code, ?string $entityName = null): ?array
        {
            return [['externalId' => ['kussenmaat' => 31, 'kleur' => 32, 'vulling' => 33][$code] ?? null]];
        }
    };

    $variant = fn (string $size, string $kleur, string $vulling): array => ['values' => ['common' => ['kussenmaat' => $size, 'kleur' => $kleur, 'vulling' => $vulling]]];

    $formatted = $exporter->cushionAttributes([
        'sku'                 => 'KUSTEST-PARENT',
        'attribute_family_id' => cushionFamilyId(),
        'variants'            => [
            $variant('45 x 45 cm', 'Groen', 'Zonder vulling'),
            $variant('45 x 45 cm', 'Groen', 'Met vulling'),
            $variant('60 x 60 cm', 'Blauw', 'Zonder vulling'),
        ],
    ]);

    expect($formatted['attributes'])->toBe([
        ['id' => 31, 'visible' => true, 'variation' => true, 'options' => ['45 x 45 cm', '60 x 60 cm']],
        ['id' => 32, 'visible' => true, 'variation' => true, 'options' => ['Groen', 'Blauw']],
        ['id' => 33, 'visible' => true, 'variation' => true, 'options' => ['Zonder vulling', 'Met vulling']],
    ])->and($formatted['default_attributes'])->toBe([
        ['id' => 31, 'option' => '45 x 45 cm'],
        ['id' => 32, 'option' => 'Groen'],
        ['id' => 33, 'option' => 'Zonder vulling'],
    ]);
});
