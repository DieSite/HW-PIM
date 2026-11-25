<?php

use App\Models\Product;
use App\Services\PoolhoogteNormalizer;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product as WebkulProduct;

/**
 * @param  array<string, mixed>  $common
 */
function makePoolhoogteProduct(array $common, ?int $parentId = null): Product
{
    $product = new Product();
    $product->attribute_family_id = DB::table('attribute_families')->value('id');
    $product->sku = 'POOL-'.uniqid();
    $product->type = $parentId ? 'simple' : 'configurable';
    $product->parent_id = $parentId;
    $product->status = 1;
    $product->values = ['common' => $common];
    $product->save();

    return $product;
}

/**
 * Store a raw poolhoogte without the saving hook, as older rows were.
 */
function setRawPoolhoogte(Product $product, mixed $value): void
{
    $values = $product->values;
    $values['common']['poolhoogte'] = $value;

    DB::table('products')->where('id', $product->id)->update(['values' => json_encode($values)]);
}

it('normalises a poolhoogte to "<number> mm"', function (mixed $input, mixed $expected) {
    expect(app(PoolhoogteNormalizer::class)->normalize($input))->toBe($expected);
})->with([
    'already normal'    => ['25 mm', '25 mm'],
    'decimal comma'     => ['4,3 mm', '4,3 mm'],
    'missing space'     => ['25mm', '25 mm'],
    'decimal no space'  => ['9,5mm', '9,5 mm'],
    'decimal point'     => ['4.3 mm', '4,3 mm'],
    'uppercase'         => ['10 MM', '10 mm'],
    'extra whitespace'  => ['  11   mm ', '11 mm'],
    'trailing dot'      => ['7 mm.', '7 mm'],
    'bare number'       => ['15', '15 mm'],
    'integer'           => [15, '15 mm'],
    'zero decimal'      => ['10,0 mm', '10 mm'],
    'centimetres'       => ['1,5 cm', '15 mm'],
    'whole centimetres' => ['2cm', '20 mm'],
    'unreadable'        => ['variabel', 'variabel'],
    'range'             => ['5-7 mm', '5-7 mm'],
    'null'              => [null, null],
]);

it('tells a readable height from free text', function () {
    $normalizer = app(PoolhoogteNormalizer::class);

    expect($normalizer->isReadable('25mm'))->toBeTrue()
        ->and($normalizer->isReadable('4,3 mm'))->toBeTrue()
        ->and($normalizer->isReadable('variabel'))->toBeFalse()
        ->and($normalizer->isReadable(null))->toBeFalse();
});

it('normalises the poolhoogte on every save', function () {
    $product = makePoolhoogteProduct(['productnaam' => 'Kleed', 'poolhoogte' => '25mm']);

    expect($product->fresh()->values['common']['poolhoogte'])->toBe('25 mm');

    $webkul = WebkulProduct::query()->findOrFail($product->id);
    $values = $webkul->values;
    $values['common']['poolhoogte'] = '9,5mm';
    $webkul->values = $values;
    $webkul->save();

    expect($webkul->fresh()->values['common']['poolhoogte'])->toBe('9,5 mm');
});

it('leaves a product without poolhoogte alone', function () {
    $product = makePoolhoogteProduct(['productnaam' => 'Kussen']);

    expect($product->fresh()->values['common'])->not->toHaveKey('poolhoogte');
});

it('reports the changes on a dry run without writing', function () {
    $product = makePoolhoogteProduct(['poolhoogte' => '25 mm']);
    setRawPoolhoogte($product, '25mm');

    $this->mock(ProductService::class)->shouldNotReceive('triggerWCSyncForParent');

    $this->artisan('products:normalize-poolhoogte', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] Would normalise the poolhoogte of 1 product(s).')
        ->assertSuccessful();

    expect(DB::table('products')->where('id', $product->id)->value('values'))->toContain('"25mm"');
});

it('normalises the stored values and resyncs each parent once', function () {
    $parent = makePoolhoogteProduct(['poolhoogte' => '25 mm']);
    $variant = makePoolhoogteProduct(['poolhoogte' => '7 mm'], $parent->id);
    $clean = makePoolhoogteProduct(['poolhoogte' => '10 mm']);
    $odd = makePoolhoogteProduct(['poolhoogte' => 'variabel']);

    setRawPoolhoogte($parent, '25mm');
    setRawPoolhoogte($variant, '7MM');

    $this->mock(ProductService::class)->shouldReceive('triggerWCSyncForParent')
        ->once()
        ->with(Mockery::on(fn (Product $root): bool => $root->id === $parent->id));

    $this->artisan('products:normalize-poolhoogte')
        ->expectsOutputToContain('Normalised the poolhoogte of 2 product(s).')
        ->expectsOutputToContain('Left unchanged because they cannot be read as a height')
        ->assertSuccessful();

    expect($parent->fresh()->values['common']['poolhoogte'])->toBe('25 mm')
        ->and($variant->fresh()->values['common']['poolhoogte'])->toBe('7 mm')
        ->and($clean->fresh()->values['common']['poolhoogte'])->toBe('10 mm')
        ->and($odd->fresh()->values['common']['poolhoogte'])->toBe('variabel');
});

it('skips the sync with --no-sync', function () {
    $product = makePoolhoogteProduct(['poolhoogte' => '25 mm']);
    setRawPoolhoogte($product, '25mm');

    $this->mock(ProductService::class)->shouldNotReceive('triggerWCSyncForParent');

    $this->artisan('products:normalize-poolhoogte', ['--no-sync' => true])->assertSuccessful();

    expect($product->fresh()->values['common']['poolhoogte'])->toBe('25 mm');
});
