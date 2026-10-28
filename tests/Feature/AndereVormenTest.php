<?php

use App\Models\Product;
use App\Services\AndereVormenLinker;
use App\Services\Mcp\ProductDeletionService;
use App\Services\Mcp\ProductUpsertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\Product as BaseProduct;
use Webkul\Product\Type\AbstractType;
use Webkul\User\Models\Admin;

/**
 * One shape of a rug: in this catalogue that is its own parent product.
 *
 * @param  list<string>  $andereVormen
 */
function makeShape(string $sku, string $naam, string $vorm = 'Rechthoek', string $merk = 'De Munk', array $andereVormen = []): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $values = ['common' => ['sku' => $sku, 'productnaam' => $naam, 'merk' => $merk, 'vorm' => $vorm]];

    if ($andereVormen !== []) {
        $values[AbstractType::ASSOCIATION_VALUES_KEY][AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY] = $andereVormen;
    }

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = $sku;
    $product->type = 'configurable';
    $product->status = 1;
    $product->values = $values;
    $product->save();

    return $product;
}

/**
 * @return list<string>
 */
function shapesOf(string $sku): array
{
    return AndereVormenLinker::listFrom(Product::where('sku', $sku)->firstOrFail()->values);
}

/**
 * @param  list<string>  $skus
 */
function setShapes(string $sku, array $skus): void
{
    $product = Product::where('sku', $sku)->firstOrFail();
    $values = AndereVormenLinker::decode($product->values);
    $values[AbstractType::ASSOCIATION_VALUES_KEY][AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY] = $skus;
    $product->values = $values;
    $product->save();
}

beforeEach(function () {
    Event::fake(['catalog.product.create.after', 'catalog.product.update.after', 'catalog.product.delete.before', 'catalog.product.delete.after']);
});

afterEach(function () {
    Product::where('sku', 'like', 'AVTEST-%')->delete();
});

it('groups the shapes of one rug by name without the shape and by brand', function () {
    makeShape('AVTEST-1', 'Diamante 01');
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond');
    makeShape('AVTEST-3', 'Diamante 01 Eye', vorm: 'Eye');
    makeShape('AVTEST-4', 'Diamante 01 Rond', vorm: 'Rond', merk: 'Karpi');
    makeShape('AVTEST-5', 'Diamante 02 Rond', vorm: 'Rond');

    $groups = collect(app(AndereVormenLinker::class)->groups()['groups'])
        ->map(fn ($members) => $members->pluck('sku')->sort()->values()->all())
        ->filter(fn (array $skus) => str_starts_with($skus[0], 'AVTEST-'))
        ->values()
        ->all();

    expect($groups)->toBe([['AVTEST-1', 'AVTEST-2', 'AVTEST-3']]);
});

it('matches a rectangle whose name carries a colour name to its round version', function () {
    $rect = makeShape('AVTEST-1', 'Vernon 13 - Linnen Grey');
    $round = makeShape('AVTEST-2', 'Vernon 13 Rond', vorm: 'Rond');

    $linker = app(AndereVormenLinker::class);

    expect($linker->groupKey($rect))->toBe($linker->groupKey($round));
});

it('leaves a group with the same shape twice to be linked by hand', function () {
    makeShape('AVTEST-1', 'Parel 880');
    makeShape('AVTEST-2', 'Parel 880 Rond', vorm: 'Rond');
    makeShape('AVTEST-3', 'Parel 880');

    ['groups' => $groups, 'ambiguous' => $ambiguous] = app(AndereVormenLinker::class)->groups();
    $ours = fn (array $list) => collect($list)->filter(fn ($members) => $members->contains('sku', 'AVTEST-1'))->count();

    expect($ours($groups))->toBe(0)
        ->and($ours($ambiguous))->toBe(1);
});

it('connects a group by giving every member the same list, itself included', function () {
    $members = collect([makeShape('AVTEST-1', 'Diamante 01'), makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond')]);

    expect(app(AndereVormenLinker::class)->connect($members))->toBe(2)
        ->and(shapesOf('AVTEST-1'))->toBe(['AVTEST-1', 'AVTEST-2'])
        ->and(shapesOf('AVTEST-2'))->toBe(['AVTEST-1', 'AVTEST-2'])
        ->and(app(AndereVormenLinker::class)->connect($members->map->fresh()))->toBe(0);
});

it('mirrors a shape added on one product to the other', function () {
    makeShape('AVTEST-1', 'Diamante 01');
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond');

    setShapes('AVTEST-1', ['AVTEST-2']);

    expect(shapesOf('AVTEST-1'))->toBe(['AVTEST-1', 'AVTEST-2'])
        ->and(shapesOf('AVTEST-2'))->toBe(['AVTEST-1', 'AVTEST-2']);
});

it('mirrors through the Webkul model the admin edit screen saves', function () {
    makeShape('AVTEST-1', 'Diamante 01');
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond');

    $product = BaseProduct::where('sku', 'AVTEST-2')->firstOrFail();
    $product->getTypeInstance()->update([AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY => ['', 'AVTEST-1']], $product->id);

    expect(shapesOf('AVTEST-1'))->toBe(['AVTEST-1', 'AVTEST-2'])
        ->and(shapesOf('AVTEST-2'))->toBe(['AVTEST-1', 'AVTEST-2']);
});

it('takes a removed shape out of the group on both sides', function () {
    $group = ['AVTEST-1', 'AVTEST-2', 'AVTEST-3'];
    makeShape('AVTEST-1', 'Diamante 01', andereVormen: $group);
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond', andereVormen: $group);
    makeShape('AVTEST-3', 'Diamante 01 Eye', vorm: 'Eye', andereVormen: $group);

    setShapes('AVTEST-1', ['AVTEST-1', 'AVTEST-2']);

    expect(shapesOf('AVTEST-1'))->toBe(['AVTEST-1', 'AVTEST-2'])
        ->and(shapesOf('AVTEST-2'))->toBe(['AVTEST-1', 'AVTEST-2'])
        ->and(shapesOf('AVTEST-3'))->toBe([]);
});

it('lets the rest of the group stay together when one product is unlinked', function () {
    $group = ['AVTEST-1', 'AVTEST-2', 'AVTEST-3'];
    makeShape('AVTEST-1', 'Diamante 01', andereVormen: $group);
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond', andereVormen: $group);
    makeShape('AVTEST-3', 'Diamante 01 Eye', vorm: 'Eye', andereVormen: $group);

    $product = BaseProduct::where('sku', 'AVTEST-1')->firstOrFail();
    // What the edit form sends when the last product was removed: only the empty marker.
    $product->getTypeInstance()->update([AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY => ['']], $product->id);

    expect(shapesOf('AVTEST-1'))->toBe([])
        ->and(shapesOf('AVTEST-2'))->toBe(['AVTEST-2', 'AVTEST-3'])
        ->and(shapesOf('AVTEST-3'))->toBe(['AVTEST-2', 'AVTEST-3']);
});

it('leaves the list alone when the edit form does not send it', function () {
    makeShape('AVTEST-1', 'Diamante 01', andereVormen: ['AVTEST-1', 'AVTEST-2']);
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond', andereVormen: ['AVTEST-1', 'AVTEST-2']);

    $product = BaseProduct::where('sku', 'AVTEST-1')->firstOrFail();
    $product->getTypeInstance()->update(['status' => 1], $product->id);

    expect(shapesOf('AVTEST-1'))->toBe(['AVTEST-1', 'AVTEST-2']);
});

it('sends the shop the other shapes as SKUs, without the product itself', function () {
    $item = ['sku' => 'AVTEST-1', 'values' => ['associations' => ['andere_vormen' => ['AVTEST-1', 'AVTEST-2', 'AVTEST-3']]]];

    expect(AndereVormenLinker::shopValue($item))->toBe('AVTEST-2,AVTEST-3')
        ->and(AndereVormenLinker::shopValue(['sku' => 'AVTEST-1', 'values' => json_encode(json_encode($item['values']))]))->toBe('AVTEST-2,AVTEST-3')
        ->and(AndereVormenLinker::shopValue(['sku' => 'AVTEST-1', 'values' => []]))->toBe('');
});

it('writes other shapes over MCP and keeps them mutual', function () {
    makeShape('AVTEST-1', 'Diamante 01');
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond');

    $admin = Admin::query()->firstOrFail();
    $result = app(ProductUpsertService::class)->upsert([['sku' => 'AVTEST-1', 'values' => ['associations' => ['andere_vormen' => ['AVTEST-2']]]]], $admin);

    expect($result['results'][0]['action'])->toBe('updated')
        ->and(shapesOf('AVTEST-1'))->toBe(['AVTEST-1', 'AVTEST-2'])
        ->and(shapesOf('AVTEST-2'))->toBe(['AVTEST-1', 'AVTEST-2']);
});

it('refuses an unknown SKU as another shape over MCP', function () {
    makeShape('AVTEST-1', 'Diamante 01');

    $admin = Admin::query()->firstOrFail();
    $result = app(ProductUpsertService::class)->upsert([['sku' => 'AVTEST-1', 'values' => ['associations' => ['andere_vormen' => ['AVTEST-404']]]]], $admin);

    expect($result['results'][0]['action'])->toBe('error')
        ->and(shapesOf('AVTEST-1'))->toBe([]);
});

it('takes a deleted product out of the groups it was in', function () {
    $group = ['AVTEST-1', 'AVTEST-2'];
    makeShape('AVTEST-1', 'Diamante 01', andereVormen: $group);
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond', andereVormen: $group);

    $admin = Admin::query()->firstOrFail();
    app(ProductDeletionService::class)->delete(['AVTEST-2'], $admin, deleteVariants: true);

    expect(shapesOf('AVTEST-1'))->toBe([]);
});

it('shows the other shapes on the edit page, without the product itself', function () {
    $group = ['AVTEST-1', 'AVTEST-2'];
    $rect = makeShape('AVTEST-1', 'Diamante 01', andereVormen: $group);
    makeShape('AVTEST-2', 'Diamante 01 Rond', vorm: 'Rond', andereVormen: $group);

    $html = $this->actingAs(Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.catalog.products.edit', ['id' => $rect->id]))
        ->assertOk()
        ->getContent();

    // The line the edit screen's Vue data gets the listed products from.
    preg_match("/'andere_vormen': (\[.*\])/", $html, $match);

    expect(collect(json_decode($match[1] ?? 'null', true))->pluck('sku')->all())->toBe(['AVTEST-2'])
        ->and($html)->toContain("key: 'andere_vormen'");
});
