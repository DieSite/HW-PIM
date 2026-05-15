<?php

use App\Enums\BolSyncEventStatus;
use App\Enums\BolSyncState;
use App\Enums\BolSyncStep;
use App\Mail\BolComSyncFailed;
use App\Mail\BolComSyncSuccess;
use App\Models\BolComCredential;
use App\Models\Product;
use App\Services\Bol\BolSyncStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Http::preventStrayRequests();

    // Stub the OAuth token endpoint
    Http::fake([
        'login.bol.com/token' => Http::response([
            'access_token' => 'fake-token',
            'expires_in'   => 3600,
        ], 200),
    ]);

    config()->set('bolcom.api_url', 'https://api.bol.com');
    config()->set('bolcom.email_recipients', ['ops@example.test']);

    $this->credential = BolComCredential::create([
        'name'          => 'Test Account',
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'is_active'     => true,
    ]);

    $this->parent = makeBolTestProduct([
        'sku'    => 'PARENT-'.uniqid(),
        'parent' => null,
        'values' => [
            'common' => [
                'beschrijving_l'         => 'Mooi tapijt',
                'kleuren'                => 'Blauw',
                'materiaal'              => 'Wol',
                'merk'                   => 'TestMerk',
                'poolhoogte'             => '10mm',
                'vorm'                   => 'rechthoek',
                'afbeelding'             => '11986,12013',
                'afbeelding_zonder_logo' => null,
            ],
        ],
    ]);

    $this->product = makeBolTestProduct([
        'sku'    => 'CHILD-'.uniqid(),
        'parent' => $this->parent,
        'values' => [
            'common' => [
                'ean'                          => '5414452716061',
                'productnaam'                  => 'Test Tapijt 80x150',
                'maat'                         => '80 cm x 150 cm',
                'prijs'                        => ['EUR' => 99],
                'voorraad_eurogros'            => 5,
                'voorraad_5_korting_handmatig' => 0,
                'voorraad_hw_5_korting'        => 0,
            ],
        ],
    ]);

    $this->product->bol_com_sync = true;
    $this->product->save();
    $this->product->bolComCredentials()->attach($this->credential->id, ['delivery_code' => '1-8d']);

    $this->stateMachine = app(BolSyncStateMachine::class);
});

function makeBolTestProduct(array $attrs): Product
{
    $familyId = DB::table('attribute_families')->first()?->id ?? DB::table('attribute_families')->insertGetId([
        'code'   => 'default_'.uniqid(),
        'status' => 1,
    ]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = $attrs['sku'];
    $product->type = $attrs['parent'] ? 'simple' : 'configurable';
    $product->parent_id = $attrs['parent']?->id;
    $product->values = $attrs['values'];
    $product->status = true;
    $product->saveQuietly();

    if ($attrs['parent']) {
        $product->setRelation('parent', $attrs['parent']);
    }

    return $product;
}

it('happy path: validates, submits content, polls, submits offer, polls, goes live', function () {
    Http::fake([
        'login.bol.com/token'                                      => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products'                    => Http::response(['processStatusId' => 'proc-content-1'], 202),
        'api.bol.com/shared/process-status/proc-content-1'         => Http::response(['processStatusId' => 'proc-content-1', 'status' => 'SUCCESS', 'entityId' => null, 'eventType' => 'CREATE_PRODUCT_CONTENT', 'description' => 'test', 'createTimestamp' => '2026-05-15T10:00:00+02:00', 'links' => []], 200),
        'api.bol.com/retailer/offers'                              => Http::response(['processStatusId' => 'proc-offer-1', 'entityId' => 'offer-uuid'], 202),
        'api.bol.com/shared/process-status/proc-offer-1'           => Http::response(['id' => 'proc-offer-1', 'status' => 'SUCCESS', 'entityId' => 'offer-uuid'], 200),
        'api.bol.com/retailer/offers/offer-uuid'                   => Http::response(['offerId' => 'offer-uuid', 'pricing' => ['bundlePrices' => [['unitPrice' => 99]]]], 200),
    ]);

    $advance = $this->stateMachine->start($this->product, $this->credential);
    expect($advance->isTerminal)->toBeFalse()
        ->and($advance->pollProcessId)->toBe('proc-content-1');

    $advance = $this->stateMachine->advance($this->product->fresh(), $this->credential, 'proc-content-1');
    expect($advance->pollProcessId)->toBe('proc-offer-1');

    $advance = $this->stateMachine->advance($this->product->fresh(), $this->credential, 'proc-offer-1');
    expect($advance->isTerminal)->toBeTrue();

    $product = $this->product->fresh();
    expect($product->bol_sync_state)->toBe(BolSyncState::Live);

    $steps = $product->bolSyncEvents()->orderBy('id')->pluck('step')->map->value->toArray();
    expect($steps)->toContain('validation', 'submit_content', 'poll_content', 'submit_offer', 'poll_offer');

    Mail::assertSent(BolComSyncSuccess::class);
});

it('validation failure: records customer message and does not call Bol', function () {
    $this->product->values = array_replace_recursive($this->product->values, ['common' => ['ean' => 'abc']]);
    $this->product->save();

    $advance = $this->stateMachine->start($this->product, $this->credential);

    expect($advance->isTerminal)->toBeTrue();
    $product = $this->product->fresh();
    expect($product->bol_sync_state)->toBe(BolSyncState::Failed)
        ->and($product->additional['product_sync_error'] ?? null)->toContain('EAN');

    $events = $product->bolSyncEvents;
    expect($events)->toHaveCount(1)
        ->and($events->first()->step)->toBe(BolSyncStep::Validation)
        ->and($events->first()->status)->toBe(BolSyncEventStatus::Failed);

    Http::assertNothingSent();
});

it('normalizes a 14-digit EAN before sending to Bol', function () {
    $this->product->values = array_replace_recursive($this->product->values, ['common' => ['ean' => '05715694000315']]);
    $this->product->save();

    Http::fake([
        'login.bol.com/token'                              => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products'            => Http::response(['processStatusId' => 'p1'], 202),
        'api.bol.com/shared/process-status/p1'             => Http::response(['id' => 'p1', 'status' => 'SUCCESS'], 200),
        'api.bol.com/retailer/offers'                      => Http::response(['processStatusId' => 'p2', 'entityId' => 'off-1'], 202),
    ]);

    $this->stateMachine->start($this->product, $this->credential);
    $this->stateMachine->advance($this->product->fresh(), $this->credential, 'p1');

    expect($this->product->fresh()->values['common']['ean'])->toBe('5715694000315');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/retailer/offers') || str_contains($request->url(), 'offers/')) {
            return false;
        }
        $body = json_decode($request->body(), true) ?: [];

        return ($body['ean'] ?? null) === '5715694000315';
    });
});

it('content submission failure: records failure with translated message', function () {
    Http::fake([
        'login.bol.com/token'                   => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products' => Http::response([
            'type'       => 'https://api.bol.com/problems',
            'title'      => 'Error validating request',
            'status'     => 400,
            'violations' => [['name' => 'ean', 'reason' => "Request contains invalid value(s): '5414452716061'."]],
        ], 400),
    ]);

    $advance = $this->stateMachine->start($this->product, $this->credential);

    expect($advance->isTerminal)->toBeTrue();

    $product = $this->product->fresh();
    expect($product->bol_sync_state)->toBe(BolSyncState::Failed);

    $failedEvent = $product->bolSyncEvents()->where('status', BolSyncEventStatus::Failed->value)->first();
    expect($failedEvent->customer_message)->toContain('EAN-code')
        ->and($failedEvent->payload['response_status'] ?? null)->toBe(400);

    Mail::assertQueued(BolComSyncFailed::class);
});

it('content polling returns PENDING: schedules another poll', function () {
    Http::fake([
        'login.bol.com/token'                            => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products'          => Http::response(['processStatusId' => 'proc-1'], 202),
        'api.bol.com/shared/process-status/proc-1'       => Http::response(['id' => 'proc-1', 'status' => 'PENDING'], 200),
    ]);

    $advance = $this->stateMachine->start($this->product, $this->credential);
    expect($advance->pollProcessId)->toBe('proc-1');

    $advance = $this->stateMachine->advance($this->product->fresh(), $this->credential, 'proc-1');
    expect($advance->isTerminal)->toBeFalse()
        ->and($advance->pollProcessId)->toBe('proc-1');

    $pending = $this->product->fresh()->bolSyncEvents()->where('status', BolSyncEventStatus::Pending->value)->first();
    expect($pending->step)->toBe(BolSyncStep::PollContent)
        ->and($pending->customer_message)->toContain('verwerkt');
});

it('content polling returns FAILURE: records customer-friendly error', function () {
    Http::fake([
        'login.bol.com/token'                       => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products'     => Http::response(['processStatusId' => 'proc-1'], 202),
        'api.bol.com/shared/process-status/proc-1'  => Http::response(['id' => 'proc-1', 'status' => 'FAILURE', 'errorMessage' => 'Catalog category mismatch'], 200),
    ]);

    $this->stateMachine->start($this->product, $this->credential);
    $advance = $this->stateMachine->advance($this->product->fresh(), $this->credential, 'proc-1');

    expect($advance->isTerminal)->toBeTrue();
    expect($this->product->fresh()->bol_sync_state)->toBe(BolSyncState::Failed);

    $failed = $this->product->fresh()->bolSyncEvents()->where('status', BolSyncEventStatus::Failed->value)->first();
    expect($failed->customer_message)->toContain('Catalog category mismatch');
});

it('retire path: deletes offer and detaches credential', function () {
    DB::table('product_bol_com_credentials')
        ->where('product_id', $this->product->id)
        ->where('bol_com_credential_id', $this->credential->id)
        ->update(['reference' => 'offer-existing']);

    $this->product->bol_com_sync = false;
    $this->product->save();

    Http::fake([
        'login.bol.com/token'                          => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/offers/offer-existing'   => Http::response([], 204),
    ]);

    $advance = $this->stateMachine->start($this->product, $this->credential, previouslyLinked: true);
    expect($advance->isTerminal)->toBeTrue();

    $product = $this->product->fresh();
    expect($product->bol_sync_state)->toBe(BolSyncState::Retired)
        ->and($product->bolComCredentials()->where('bol_com_credentials.id', $this->credential->id)->exists())->toBeFalse();
});

it('images field stored as array still produces a valid content payload', function () {
    $this->parent->values = array_replace_recursive($this->parent->values, [
        'common' => ['afbeelding' => ['11986', '12013', '12036']],
    ]);
    $this->parent->save();
    $this->product->setRelation('parent', $this->parent->fresh());

    Http::fake([
        'login.bol.com/token'                   => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products' => Http::response(['processStatusId' => 'p1'], 202),
    ]);

    $this->stateMachine->start($this->product, $this->credential);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/retailer/content/products')) {
            return false;
        }
        $body = json_decode($request->body(), true) ?: [];
        $assets = $body['assets'] ?? [];

        return is_array($assets) && count($assets) >= 1;
    });
});
