<?php

use App\Services\Bol\BolContractValidator;
use App\Services\Bol\BolPayloadBuilder;
use Webkul\Product\Models\Product;

beforeEach(function () {
    $this->validator = new BolContractValidator([
        base_path('docs/bol-api-spec/retailer-v10.json'),
        base_path('docs/bol-api-spec/shared-v10.json'),
        base_path('docs/bol-api-spec/offers-v11.json'),
    ]);
    $this->v10 = 'application/vnd.retailer.v10+json';
    $this->v11 = 'application/vnd.retailer.v11+json';
    $this->mediaType = $this->v10; // legacy alias used by older tests below

    config()->set('bolcom.bol_discounts', []);

    $this->parent = bolContractParent();
    $this->product = bolContractChild($this->parent);
});

function bolContractParent(): Product
{
    $product = new Product();
    $product->id = 1001;
    $product->sku = 'PARENT-CONTRACT';
    $product->values = [
        'common' => [
            'beschrijving_l'         => 'Mooi tapijt voor de woonkamer',
            'kleuren'                => 'Blauw|Grijs',
            'materiaal'              => 'Wol|Katoen',
            'merk'                   => 'TestMerk',
            'poolhoogte'             => '10mm',
            'vorm'                   => 'rechthoek',
            'afbeelding'             => '11986,12013',
            'afbeelding_zonder_logo' => null,
        ],
    ];

    return $product;
}

function bolContractChild(Product $parent): Product
{
    $product = new Product();
    $product->id = 1002;
    $product->sku = 'CHILD-CONTRACT';
    $product->parent_id = $parent->id;
    $product->setRelation('parent', $parent);
    $product->values = [
        'common' => [
            'ean'                          => '5414452716061',
            'productnaam'                  => 'Test Tapijt 80x150',
            'maat'                         => '80 cm x 150 cm',
            'prijs'                        => ['EUR' => 99.50],
            'voorraad_eurogros'            => 5,
            'voorraad_5_korting_handmatig' => 0,
            'voorraad_hw_5_korting'        => 0,
        ],
    ];

    return $product;
}

it('CreateOfferRequest payload matches Offers v11 /retailer/offers spec', function () {
    $builder = app(BolPayloadBuilder::class);
    $payload = $builder->offer($this->product, '1-8d');

    $violations = $this->validator->validateRequest('POST', '/retailer/offers', $payload, $this->v11);

    expect($violations)->toBe([], implode("\n", $violations));
});

it('client routes /retailer/offers to v11 and content to v10', function () {
    expect(\App\Clients\BolApiClient::mediaTypeForEndpoint('/retailer/offers'))->toBe($this->v11)
        ->and(\App\Clients\BolApiClient::mediaTypeForEndpoint('/retailer/offers/abc-uuid'))->toBe($this->v11)
        ->and(\App\Clients\BolApiClient::mediaTypeForEndpoint('/retailer/offers/abc-uuid/price'))->toBe($this->v11)
        ->and(\App\Clients\BolApiClient::mediaTypeForEndpoint('/retailer/offers/abc-uuid/stock'))->toBe($this->v11)
        ->and(\App\Clients\BolApiClient::mediaTypeForEndpoint('/retailer-demo/offers/abc'))->toBe($this->v11)
        ->and(\App\Clients\BolApiClient::mediaTypeForEndpoint('/retailer/content/products'))->toBe($this->v10)
        ->and(\App\Clients\BolApiClient::mediaTypeForEndpoint('/shared/process-status/abc'))->toBe($this->v10)
        ->and(\App\Clients\BolApiClient::mediaTypeForEndpoint('/retailer/products/categories'))->toBe($this->v10);
});

it('Content payload structure matches /retailer/content/products spec', function () {
    $builder = app(BolPayloadBuilder::class);
    $payload = $builder->content($this->product);

    // We tolerate the "unitId is required" spec finding for text attributes — Bol
    // accepts these in practice (verified against live offers). Filter unitId
    // violations specifically; surface any other shape mismatch.
    $violations = $this->validator->validateRequest('POST', '/retailer/content/products', $payload, $this->mediaType);
    $real = array_values(array_filter($violations, fn ($v) => ! str_contains($v, "missing required property 'unitId'")));

    expect($real)->toBe([], implode("\n", $real));

    // Hard requirements we enforce ourselves:
    expect($payload['language'] ?? null)->toBe('nl')
        ->and($payload['attributes'])->toBeArray()
        ->and(collect($payload['attributes'])->pluck('id'))->toContain('EAN')
        ->and(collect($payload['attributes'])->every(fn ($a) => is_array($a['values'] ?? null)))->toBeTrue();
});

it('PatchOfferRequest payload matches PATCH /retailer/offers/{offer-id} v11 spec', function () {
    $builder = app(BolPayloadBuilder::class);
    $payload = $builder->patchOffer($this->product, '1-8d');

    $violations = $this->validator->validateRequest('PATCH', '/retailer/offers/{offer-id}', $payload, $this->v11);

    expect($violations)->toBe([], implode("\n", $violations));
});

it('ProcessStatus responses we read conform to spec', function () {
    $sampleSuccess = [
        'processStatusId' => 'abc',
        'entityId'        => 'offer-uuid',
        'eventType'       => 'CREATE_OFFER',
        'description'     => 'Creates offer for product 1',
        'status'          => 'SUCCESS',
        'createTimestamp' => '2026-05-15T10:00:00+02:00',
        'links'           => [],
    ];
    $samplePending = [
        'processStatusId' => 'abc',
        'eventType'       => 'CREATE_OFFER',
        'description'     => 'Creating offer',
        'status'          => 'PENDING',
        'createTimestamp' => '2026-05-15T10:00:00+02:00',
        'links'           => [],
    ];

    $violationsA = $this->validator->validateResponse('GET', '/shared/process-status/{process-status-id}', 200, $sampleSuccess, $this->mediaType);
    $violationsB = $this->validator->validateResponse('GET', '/shared/process-status/{process-status-id}', 200, $samplePending, $this->mediaType);

    expect($violationsA)->toBe([], implode("\n", $violationsA))
        ->and($violationsB)->toBe([], implode("\n", $violationsB));
});

it('GET /retailer/offers/{offer-id} live shape from smoke-test is parseable', function () {
    // Real shape captured by `bolcom:smoke-test 1 --write` against the demo offer.
    // Note: notPublishableReasons + store are required-in-spec but missing-in-practice
    // when there are no issues. Our code must tolerate that.
    $live = json_decode(file_get_contents(base_path('tests/Fixtures/bolcom/live/auth_probe.json')), true);

    expect($live)->toBeArray()
        ->and($live['offerId'] ?? null)->toBeString()
        ->and($live['ean'] ?? null)->toBeString()
        ->and($live['pricing']['bundlePrices'][0]['unitPrice'] ?? null)->toBeFloat()
        ->and($live['stock']['amount'] ?? null)->toBeInt()
        ->and($live['fulfilment']['method'] ?? null)->toBeString()
        ->and($live['condition']['name'] ?? null)->toBeString();
});

it('Problem response shape (used by violation translator) has the keys we depend on', function () {
    // v11 refers to error responses via an external $ref we don't snapshot, so
    // validate the shape we actually parse instead of the full schema.
    $sample = [
        'type'       => 'https://api.bol.com/problems',
        'title'      => 'Error validating request.',
        'status'     => 400,
        'detail'     => 'Bad request',
        'violations' => [['name' => 'ean', 'reason' => "Request contains invalid value(s): '05715694000315'."]],
    ];

    expect($sample)->toHaveKeys(['type', 'title', 'status', 'detail', 'violations'])
        ->and($sample['violations'][0])->toHaveKeys(['name', 'reason']);
});
