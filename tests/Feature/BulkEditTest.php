<?php

use App\Models\Product;
use App\Services\BulkEditService;
use Illuminate\Support\Facades\DB;

/**
 * Insert a minimal non-localized text/textarea attribute so the service's
 * whitelist (queried from the attributes table) recognises the code.
 */
function makeBulkAttribute(string $code, string $type = 'text'): void
{
    if (DB::table('attributes')->where('code', $code)->exists()) {
        return;
    }

    DB::table('attributes')->insert([
        'code'              => $code,
        'type'              => $type,
        'position'          => 1,
        'is_required'       => 0,
        'is_unique'         => 0,
        'value_per_locale'  => 0,
        'value_per_channel' => 0,
        'enable_wysiwyg'    => 0,
        'usable_in_grid'    => 0,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $common
 */
function makeBulkProduct(array $common, ?int $parentId = null): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = 'BULKTEST-'.uniqid();
    $product->type = $parentId ? 'simple' : 'configurable';
    $product->parent_id = $parentId;
    $product->status = 1;
    $product->values = ['common' => $common];
    $product->save();

    return $product;
}

beforeEach(function () {
    makeBulkAttribute('merk');
    makeBulkAttribute('levertijd_voorradig');
    makeBulkAttribute('beschrijving_k', 'textarea');

    $this->service = new BulkEditService();
});

it('applies a find & replace to a single value', function () {
    $op = ['target' => 'beschrijving_k', 'type' => 'replace', 'find' => '6 tot 8 weken', 'replace' => '3 tot 5 weken'];

    expect($this->service->applyOperation('levertijd 6 tot 8 weken totaal', $op))
        ->toBe('levertijd 3 tot 5 weken totaal');
});

it('sets and clears values', function () {
    expect($this->service->applyOperation('oud', ['type' => 'set', 'value' => 'nieuw']))->toBe('nieuw')
        ->and($this->service->applyOperation('iets', ['type' => 'clear']))->toBe('');
});

it('previews only the products that actually change under a replace', function () {
    $a = makeBulkProduct(['beschrijving_k' => 'De levertijd is 6 tot 8 weken.']);
    $b = makeBulkProduct(['beschrijving_k' => 'Ook hier 6 tot 8 weken tekst.']);
    $unaffected = makeBulkProduct(['beschrijving_k' => 'Geen levertijd vermeld.']);

    $preview = $this->service->preview(
        ['sku_prefix' => 'BULKTEST-'],
        ['target' => 'beschrijving_k', 'type' => 'replace', 'find' => '6 tot 8 weken', 'replace' => '3 tot 5 weken'],
    );

    expect($preview['count'])->toBe(2);

    $skus = collect($preview['samples'])->pluck('sku');
    expect($skus)->toContain($a->sku)
        ->and($skus)->toContain($b->sku)
        ->and($skus)->not->toContain($unaffected->sku);

    foreach ($preview['samples'] as $sample) {
        expect($sample['after'])->toContain('3 tot 5 weken')
            ->and($sample['after'])->not->toContain('6 tot 8 weken');
    }
});

it('excludes products already at the target value for a set operation', function () {
    $needsChange = makeBulkProduct(['levertijd_voorradig' => '2-3 weken']);
    $alreadySet = makeBulkProduct(['levertijd_voorradig' => '1-2 weken']);

    $affected = $this->service->affectedQuery(
        ['sku_prefix' => 'BULKTEST-'],
        ['target' => 'levertijd_voorradig', 'type' => 'set', 'value' => '1-2 weken'],
    )->pluck('sku');

    expect($affected)->toContain($needsChange->sku)
        ->and($affected)->not->toContain($alreadySet->sku);
});

it('resolves the brand filter through the parent product', function () {
    $eurogrosParent = makeBulkProduct(['merk' => 'Eurogros']);
    $eurogrosVariant = makeBulkProduct(['beschrijving_k' => 'levertijd 6 tot 8 weken'], $eurogrosParent->id);

    $munkParent = makeBulkProduct(['merk' => 'De Munk']);
    $munkVariant = makeBulkProduct(['beschrijving_k' => 'levertijd 6 tot 8 weken'], $munkParent->id);

    $affected = $this->service->affectedQuery(
        ['brand' => 'Eurogros', 'sku_prefix' => 'BULKTEST-'],
        ['target' => 'beschrijving_k', 'type' => 'replace', 'find' => '6 tot 8 weken', 'replace' => '3 tot 5 weken'],
    )->pluck('sku');

    expect($affected)->toContain($eurogrosVariant->sku)
        ->and($affected)->not->toContain($munkVariant->sku);
});

it('rejects attribute codes outside the editable whitelist', function () {
    $this->service->affectedQuery(
        ['sku_prefix' => 'BULKTEST-'],
        ['target' => 'id; DROP TABLE products', 'type' => 'clear'],
    );
})->throws(InvalidArgumentException::class);
