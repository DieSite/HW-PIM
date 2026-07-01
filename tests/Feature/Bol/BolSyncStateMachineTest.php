<?php

use App\Enums\BolSyncEventStatus;
use App\Enums\BolSyncState;
use App\Enums\BolSyncStep;
use App\Exceptions\BolTransientSyncException;
use App\Jobs\SyncProductWithBolComJob;
use App\Mail\BolComSyncFailed;
use App\Mail\BolComSyncSuccess;
use App\Models\BolComCredential;
use App\Models\Product;
use App\Services\Bol\BolSyncStateMachine;
use Illuminate\Contracts\Queue\Job as QueueJob;
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

it('transient 5xx on content submission is not terminal: throws for retry, records no failure, sends no mail', function () {
    Http::fake([
        'login.bol.com/token'                   => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products' => Http::response([
            'type'   => 'https://api.bol.com/problems',
            'title'  => 'Gateway Timeout',
            'status' => 504,
            'detail' => 'The upstream service is currently unable to process this request.',
        ], 504),
    ]);

    expect(fn () => $this->stateMachine->start($this->product, $this->credential))
        ->toThrow(BolTransientSyncException::class);

    $product = $this->product->fresh();
    expect($product->bol_sync_state)->not->toBe(BolSyncState::Failed)
        ->and($product->additional['product_sync_error'] ?? null)->toBeNull()
        ->and($product->bolSyncEvents()->where('status', BolSyncEventStatus::Failed->value)->exists())->toBeFalse();

    Mail::assertNotQueued(BolComSyncFailed::class);
});

it('job: transient failure on a non-final attempt rethrows for retry and stays silent', function () {
    Http::fake([
        'login.bol.com/token'                   => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products' => Http::response(['title' => 'Gateway Timeout', 'status' => 504], 504),
    ]);

    $job = new SyncProductWithBolComJob($this->product, $this->credential);
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    expect(fn () => $job->handle($this->stateMachine))->toThrow(Exception::class);

    Mail::assertNotQueued(BolComSyncFailed::class);
    expect($this->product->fresh()->bolSyncEvents()->where('status', BolSyncEventStatus::Failed->value)->exists())->toBeFalse();
});

it('job: transient failure on the final attempt records the failure and emails once', function () {
    Http::fake([
        'login.bol.com/token'                   => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products' => Http::response(['title' => 'Gateway Timeout', 'status' => 504], 504),
    ]);

    $job = new SyncProductWithBolComJob($this->product, $this->credential);
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($job->tries);
    $job->setJob($queueJob);

    $job->handle($this->stateMachine);

    $product = $this->product->fresh();
    expect($product->bol_sync_state)->toBe(BolSyncState::Failed);

    $failed = $product->bolSyncEvents()->where('status', BolSyncEventStatus::Failed->value)->first();
    expect($failed)->not->toBeNull()
        ->and($failed->customer_message)->toContain('tijdelijk niet bereikbaar');

    Mail::assertQueued(BolComSyncFailed::class, 1);
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

it('self-heals: PATCH update on a stale reference 404s, clears it, and falls back to create', function () {
    DB::table('product_bol_com_credentials')
        ->where('product_id', $this->product->id)
        ->where('bol_com_credential_id', $this->credential->id)
        ->update(['reference' => 'stale-uuid']);

    Http::fake([
        'login.bol.com/token'                            => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/offers/stale-uuid'         => Http::response([
            'type' => 'https://api.bol.com/problems', 'title' => 'Not Found', 'status' => 404,
        ], 404),
        'api.bol.com/retailer/content/products'          => Http::response(['processStatusId' => 'new-proc'], 202),
    ]);

    $advance = $this->stateMachine->start($this->product, $this->credential, previouslyLinked: true);

    expect($advance->pollProcessId)->toBe('new-proc');

    $product = $this->product->fresh();
    $pivot = $product->bolComCredentials->first()->pivot;
    expect($pivot->reference)->toBeNull();

    $skipped = $product->bolSyncEvents()->where('status', BolSyncEventStatus::Skipped->value)->first();
    expect($skipped)->not->toBeNull()
        ->and($skipped->customer_message)->toContain('opnieuw aan');
});

it('self-heals: POST offer 409 "offer-exists" adopts the existing OfferUid and runs an update', function () {
    Http::fake([
        'login.bol.com/token'                              => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/content/products'            => Http::response(['processStatusId' => 'proc-1'], 202),
        'api.bol.com/shared/process-status/proc-1'         => Http::response(['id' => 'proc-1', 'status' => 'SUCCESS'], 200),
        'api.bol.com/retailer/offers'                      => Http::response([
            'type'   => '/problems/offer-exists',
            'title'  => 'Offer already exists',
            'status' => 409,
            'detail' => 'Offer with EAN 6154150433493 and condition NEW already exists for retailer 1736138 with OfferUid 1435cf0d-0fef-4125-8c25-3599f92a2338.',
        ], 409),
        'api.bol.com/retailer/offers/1435cf0d-0fef-4125-8c25-3599f92a2338' => Http::response([], 202),
    ]);

    $this->stateMachine->start($this->product, $this->credential);
    $this->stateMachine->advance($this->product->fresh(), $this->credential, 'proc-1');

    $product = $this->product->fresh();
    $pivot = $product->bolComCredentials()->where('bol_com_credentials.id', $this->credential->id)->first()->pivot;

    expect($pivot->reference)->toBe('1435cf0d-0fef-4125-8c25-3599f92a2338');

    $skipped = $product->bolSyncEvents()->where('status', BolSyncEventStatus::Skipped->value)->first();
    expect($skipped)->not->toBeNull()
        ->and($skipped->customer_message)->toContain('bestond al');

    expect($product->bol_sync_state)->toBe(BolSyncState::Live);
});

it('self-heals: DELETE on a 404 reference still marks the product retired locally', function () {
    DB::table('product_bol_com_credentials')
        ->where('product_id', $this->product->id)
        ->where('bol_com_credential_id', $this->credential->id)
        ->update(['reference' => 'already-gone']);

    $this->product->bol_com_sync = false;
    $this->product->save();

    Http::fake([
        'login.bol.com/token'                          => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
        'api.bol.com/retailer/offers/already-gone'     => Http::response([
            'type' => 'https://api.bol.com/problems', 'title' => 'Not Found', 'status' => 404,
        ], 404),
    ]);

    $advance = $this->stateMachine->start($this->product, $this->credential, previouslyLinked: true);

    expect($advance->isTerminal)->toBeTrue();
    $product = $this->product->fresh();
    expect($product->bol_sync_state)->toBe(BolSyncState::Retired)
        ->and($product->bolComCredentials()->where('bol_com_credentials.id', $this->credential->id)->exists())->toBeFalse();
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
