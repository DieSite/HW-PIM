<?php

use App\Jobs\ApplyDeliveryTimesJob;
use App\Jobs\SyncWooCommerceStockJob;
use App\Models\DeliveryTimeRule;
use App\Models\Product;
use App\Services\DeliveryTimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Models\Admin;

/**
 * @param  array<string, mixed>  $common
 */
function makeDeliveryTimeProduct(array $common, ?int $parentId = null): Product
{
    $product = new Product();
    $product->attribute_family_id = DB::table('attribute_families')->value('id');
    $product->sku = 'LEVERTIJD-'.uniqid();
    $product->type = $parentId ? 'simple' : 'configurable';
    $product->parent_id = $parentId;
    $product->status = 1;
    $product->values = ['common' => $common];
    $product->save();

    return $product;
}

$rules = [
    'showroom' => '2 tot 3 dagen',
    'brands'   => [
        'De Munk' => ['in_stock' => '1 tot 2 weken', 'out_of_stock' => '8 tot 12 weken'],
        'Desso'   => ['in_stock' => '1 tot 2 weken', 'out_of_stock' => '1 tot 2 weken'],
    ],
];

it('resolves the delivery time of a variant', function (array $common, ?string $brand, ?string $expected) use ($rules) {
    expect(DeliveryTimeService::resolve($common, $brand, $rules))->toBe($expected);
})->with([
    'showroom stock wins'                   => [['voorraad_hw_5_korting' => 1, 'voorraad_5_korting_handmatig' => 3], 'De Munk', '2 tot 3 dagen'],
    'showroom stock without a brand rule'   => [['voorraad_hw_5_korting' => '2'], 'Louis De Poortere', '2 tot 3 dagen'],
    'supplier stock (De Munk)'              => [['voorraad_5_korting_handmatig' => 3], 'De Munk', '1 tot 2 weken'],
    'supplier stock (Eurogros field)'       => [['voorraad_eurogros' => '4'], 'De Munk', '1 tot 2 weken'],
    'no stock'                              => [['voorraad_eurogros' => 0, 'voorraad_hw_5_korting' => 'null'], 'De Munk', '8 tot 12 weken'],
    'Desso is always the same'              => [[], 'Desso', '1 tot 2 weken'],
    'brand without a rule and no showroom'  => [['voorraad_eurogros' => 5], 'Louis De Poortere', null],
    'no brand'                              => [[], null, null],
]);

it('ignores showroom stock when no showroom delivery time is set', function () use ($rules) {
    $rules['showroom'] = null;

    expect(DeliveryTimeService::resolve(['voorraad_hw_5_korting' => 1], 'De Munk', $rules))->toBe('8 tot 12 weken');
});

it('seeds the brand rules and the showroom time', function () {
    expect(app(DeliveryTimeService::class)->rules())->toMatchArray([
        'showroom' => '2 tot 3 dagen',
        'brands'   => [
            'De Munk'           => ['in_stock' => '1 tot 2 weken', 'out_of_stock' => '8 tot 12 weken'],
            'Karpi'             => ['in_stock' => '1 tot 2 weken', 'out_of_stock' => '3 tot 5 weken'],
            'Mart Visser'       => ['in_stock' => '1 tot 2 weken', 'out_of_stock' => '3 tot 5 weken'],
            'Mart Visser|Karpi' => ['in_stock' => '1 tot 2 weken', 'out_of_stock' => '3 tot 5 weken'],
            'Eurogros'          => ['in_stock' => '1 tot 2 weken', 'out_of_stock' => '3 tot 5 weken'],
            'Desso'             => ['in_stock' => '1 tot 2 weken', 'out_of_stock' => '1 tot 2 weken'],
        ],
    ]);
});

it('keeps the delivery time of a variant in step with its stock on save', function () {
    $parent = makeDeliveryTimeProduct(['merk' => 'Mart Visser|Karpi']);
    $variant = makeDeliveryTimeProduct(['maat' => '200 cm x 290 cm'], $parent->id);

    expect($variant->fresh()->values['common']['levertijd'])->toBe('3 tot 5 weken');

    $values = $variant->values;
    $values['common']['voorraad_5_korting_handmatig'] = 2;
    $variant->values = $values;
    $variant->save();

    expect($variant->fresh()->values['common']['levertijd'])->toBe('1 tot 2 weken');

    $values['common']['voorraad_hw_5_korting'] = 1;
    $variant->values = $values;
    $variant->save();

    expect($variant->fresh()->values['common']['levertijd'])->toBe('2 tot 3 dagen');

    $values['common']['voorraad_hw_5_korting'] = 0;
    $values['common']['voorraad_5_korting_handmatig'] = 0;
    $variant->values = $values;
    $variant->save();

    expect($variant->fresh()->values['common']['levertijd'])->toBe('3 tot 5 weken');
});

it('recomputes on a save through the Webkul product model too', function () {
    $parent = makeDeliveryTimeProduct(['merk' => 'Eurogros']);
    $variant = Webkul\Product\Models\Product::find(makeDeliveryTimeProduct([], $parent->id)->id);

    $values = $variant->values;
    $values['common']['voorraad_eurogros'] = 7;
    $variant->values = $values;
    $variant->save();

    expect($variant->fresh()->values['common']['levertijd'])->toBe('1 tot 2 weken');
});

it('takes the delivery time off a variant whose brand has no rule', function () {
    $parent = makeDeliveryTimeProduct(['merk' => 'Louis De Poortere']);
    $variant = makeDeliveryTimeProduct(['voorraad_hw_5_korting' => 1], $parent->id);

    expect($variant->fresh()->values['common']['levertijd'])->toBe('2 tot 3 dagen');

    $values = $variant->values;
    $values['common']['voorraad_hw_5_korting'] = 0;
    $variant->values = $values;
    $variant->save();

    expect($variant->fresh()->values['common'])->not->toHaveKey('levertijd');
});

it('leaves parents alone on save', function () {
    $parent = makeDeliveryTimeProduct(['merk' => 'De Munk', 'voorraad_hw_5_korting' => 1]);

    expect($parent->fresh()->values['common'])->not->toHaveKey('levertijd');
});

it('lists every brand on the delivery times screen', function () {
    makeDeliveryTimeProduct(['merk' => 'Louis De Poortere']);

    $this->actingAs(Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.tools.delivery-times.index'))
        ->assertOk()
        ->assertSee('Louis De Poortere')
        ->assertSee('Mart Visser|Karpi')
        ->assertSee('8 tot 12 weken')
        ->assertSee('2 tot 3 dagen');
});

it('saves the rules and recomputes every variant in the background', function () {
    Queue::fake();

    $this->actingAs(Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.tools.delivery-times.update'), [
            'showroom' => ' 1 tot 3 dagen ',
            'rules'    => [
                ['brand' => 'De Munk', 'in_stock' => '1 tot 2 weken', 'out_of_stock' => '10 tot 14 weken'],
                ['brand' => 'Karpi', 'in_stock' => '', 'out_of_stock' => ''],
                ['brand' => 'Louis De Poortere', 'in_stock' => '2 tot 3 weken', 'out_of_stock' => '4 tot 6 weken'],
                ['brand' => '', 'in_stock' => '', 'out_of_stock' => ''],
            ],
        ])
        ->assertRedirect(route('admin.tools.delivery-times.index'))
        ->assertSessionHasNoErrors();

    $rules = app(DeliveryTimeService::class)->rules();

    expect($rules['showroom'])->toBe('1 tot 3 dagen')
        ->and($rules['brands']['De Munk']['out_of_stock'])->toBe('10 tot 14 weken')
        ->and($rules['brands']['Louis De Poortere'])->toBe(['in_stock' => '2 tot 3 weken', 'out_of_stock' => '4 tot 6 weken'])
        ->and($rules['brands'])->not->toHaveKey('Karpi')
        ->and(DeliveryTimeRule::query()->where('brand', '')->exists())->toBeFalse();

    Queue::assertPushed(ApplyDeliveryTimesJob::class);
});

it('switches the showroom time off when it is saved empty', function () {
    Queue::fake();

    $this->actingAs(Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.tools.delivery-times.update'), ['showroom' => '', 'rules' => []])
        ->assertSessionHasNoErrors();

    expect(app(DeliveryTimeService::class)->rules()['showroom'])->toBeNull();
});

it('rejects a brand listed twice', function () {
    Queue::fake();

    $this->actingAs(Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.tools.delivery-times.update'), [
            'rules' => [
                ['brand' => 'Karpi', 'in_stock' => '1 week'],
                ['brand' => 'Karpi', 'in_stock' => '2 weken'],
            ],
        ])
        ->assertSessionHasErrors('rules.1.brand');

    Queue::assertNothingPushed();
});

it('applies changed rules to every variant and parent, and pushes the changes', function () {
    config(['delivery_times.sync_external' => true]);

    $parent = makeDeliveryTimeProduct(['merk' => 'De Munk', 'levertijd_voorradig' => '1 week', 'levertijd_niet_voorradig' => '6 tot 8 weken']);
    $inStock = makeDeliveryTimeProduct(['voorraad_5_korting_handmatig' => 2], $parent->id);
    $outOfStock = makeDeliveryTimeProduct([], $parent->id);

    DeliveryTimeRule::query()->where('brand', 'De Munk')->update(['out_of_stock' => '10 tot 14 weken']);
    app(DeliveryTimeService::class)->forgetRules();

    Queue::fake();

    (new ApplyDeliveryTimesJob())->handle(app(DeliveryTimeService::class));

    expect($outOfStock->fresh()->values['common']['levertijd'])->toBe('10 tot 14 weken')
        ->and($inStock->fresh()->values['common']['levertijd'])->toBe('1 tot 2 weken')
        ->and($parent->fresh()->values['common'])->toMatchArray([
            'levertijd_voorradig'      => '1 tot 2 weken',
            'levertijd_niet_voorradig' => '10 tot 14 weken',
        ]);

    Queue::assertPushed(SyncWooCommerceStockJob::class, fn (SyncWooCommerceStockJob $job): bool => $job->updates === [[
        'sku'            => $outOfStock->sku,
        'stock_quantity' => 0,
        'stock_status'   => 'onbackorder',
        'levertijd'      => '10 tot 14 weken',
    ]]);
});

it('does not push to the shop when external sync is off', function () {
    config(['delivery_times.sync_external' => false]);

    $parent = makeDeliveryTimeProduct(['merk' => 'De Munk']);
    $variant = makeDeliveryTimeProduct([], $parent->id);

    DeliveryTimeRule::query()->where('brand', 'De Munk')->update(['out_of_stock' => '10 tot 14 weken']);
    app(DeliveryTimeService::class)->forgetRules();

    Queue::fake();

    (new ApplyDeliveryTimesJob())->handle(app(DeliveryTimeService::class));

    expect($variant->fresh()->values['common']['levertijd'])->toBe('10 tot 14 weken');

    Queue::assertNotPushed(SyncWooCommerceStockJob::class);
});
