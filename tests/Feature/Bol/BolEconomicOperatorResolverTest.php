<?php

use App\Models\BolComCredential;
use App\Models\BolEconomicOperator;
use App\Services\Bol\BolEconomicOperatorResolver;
use App\Services\Bol\BolPayloadBuilder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->credential = BolComCredential::create([
        'name'          => 'Operator Test',
        'client_id'     => 'cid',
        'client_secret' => 'secret',
        'is_active'     => true,
    ]);

    $familyId = DB::table('attribute_families')->first()?->id ?? DB::table('attribute_families')->insertGetId([
        'code'   => 'default_'.uniqid(),
        'status' => 1,
    ]);

    $this->parent = new \App\Models\Product();
    $this->parent->attribute_family_id = $familyId;
    $this->parent->sku = 'OP-PARENT-'.uniqid();
    $this->parent->type = 'configurable';
    $this->parent->values = ['common' => ['merk' => 'TestMerk', 'kleuren' => 'Blauw', 'materiaal' => 'Wol', 'vorm' => 'rechthoek']];
    $this->parent->status = true;
    $this->parent->saveQuietly();

    $this->product = new \App\Models\Product();
    $this->product->attribute_family_id = $familyId;
    $this->product->sku = 'OP-CHILD-'.uniqid();
    $this->product->type = 'simple';
    $this->product->parent_id = $this->parent->id;
    $this->product->values = ['common' => [
        'ean'         => '5414452716061',
        'productnaam' => 'Operator Tester',
        'maat'        => '80 cm x 150 cm',
        'prijs'       => ['EUR' => 99],
    ]];
    $this->product->status = true;
    $this->product->saveQuietly();
    $this->product->setRelation('parent', $this->parent);
});

it('returns null when no override and no operator name matches the brand', function () {
    expect(app(BolEconomicOperatorResolver::class)->resolve($this->product, $this->credential))->toBeNull();
});

it('uses the explicit product override when set', function () {
    $this->product->bol_economic_operator_id = 'uuid-product-override';
    $this->product->saveQuietly();

    expect(app(BolEconomicOperatorResolver::class)->resolve($this->product, $this->credential))->toBe('uuid-product-override');
});

it('matches an operator whose name equals the product brand', function () {
    BolEconomicOperator::create([
        'bol_com_credential_id' => $this->credential->id,
        'bol_operator_id'       => 'uuid-by-name',
        'name'                  => 'TestMerk',
    ]);

    expect(app(BolEconomicOperatorResolver::class)->resolve($this->product, $this->credential))->toBe('uuid-by-name');
});

it('matches operator name to brand case-insensitively', function () {
    BolEconomicOperator::create([
        'bol_com_credential_id' => $this->credential->id,
        'bol_operator_id'       => 'uuid-case',
        'name'                  => 'testmerk',
    ]);

    expect(app(BolEconomicOperatorResolver::class)->resolve($this->product, $this->credential))->toBe('uuid-case');
});

it('product override beats operator-name match', function () {
    BolEconomicOperator::create([
        'bol_com_credential_id' => $this->credential->id,
        'bol_operator_id'       => 'uuid-by-name',
        'name'                  => 'TestMerk',
    ]);
    $this->product->bol_economic_operator_id = 'uuid-product-override';
    $this->product->saveQuietly();

    expect(app(BolEconomicOperatorResolver::class)->resolve($this->product, $this->credential))->toBe('uuid-product-override');
});

it('builder embeds economicOperatorId in the offer payload when resolved', function () {
    BolEconomicOperator::create([
        'bol_com_credential_id' => $this->credential->id,
        'bol_operator_id'       => 'uuid-by-name',
        'name'                  => 'TestMerk',
    ]);

    $operatorId = app(BolEconomicOperatorResolver::class)->resolve($this->product, $this->credential);
    $payload = app(BolPayloadBuilder::class)->offer($this->product, '4-8d', $operatorId);

    expect($payload['economicOperatorId'] ?? null)->toBe('uuid-by-name');
});

it('builder omits economicOperatorId when none resolved', function () {
    $payload = app(BolPayloadBuilder::class)->offer($this->product, '4-8d', null);

    expect($payload)->not->toHaveKey('economicOperatorId');
});
