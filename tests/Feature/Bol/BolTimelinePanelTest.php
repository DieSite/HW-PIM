<?php

use App\Enums\BolSyncEventStatus;
use App\Enums\BolSyncState;
use App\Enums\BolSyncStep;
use App\Jobs\SyncProductWithBolComJob;
use App\Models\BolComCredential;
use App\Models\BolSyncEvent;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\User\Tests\Concerns\UserAssertions;

uses(UserAssertions::class);

beforeEach(function () {
    $familyId = DB::table('attribute_families')->first()?->id ?? DB::table('attribute_families')->insertGetId([
        'code'   => 'default_'.uniqid(),
        'status' => 1,
    ]);

    $this->credential = BolComCredential::create([
        'name'          => 'Test Account',
        'client_id'     => 'cid',
        'client_secret' => 'secret',
        'is_active'     => true,
    ]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = 'TIMELINE-'.uniqid();
    $product->type = 'simple';
    $product->values = ['common' => ['productnaam' => 'Timeline Product', 'ean' => '5414452716061']];
    $product->status = true;
    $product->bol_com_sync = true;
    $product->bol_sync_state = BolSyncState::Failed->value;
    $product->bol_sync_state_at = now();
    $product->additional = ['product_sync_error' => 'Test fout: EAN is ongeldig.'];
    $product->saveQuietly();
    $product->bolComCredentials()->attach($this->credential->id, ['delivery_code' => '1-8d']);

    BolSyncEvent::create([
        'product_id'            => $product->id,
        'bol_com_credential_id' => $this->credential->id,
        'step'                  => BolSyncStep::Validation,
        'status'                => BolSyncEventStatus::Failed,
        'customer_message'      => 'De EAN-code is ongeldig.',
        'payload'               => ['failures' => [['code' => 'ean_invalid']]],
    ]);

    $this->product = $product;
});

it('renders the timeline panel on the product edit page', function () {
    $this->loginAsAdmin();

    $response = $this->get(route('admin.catalog.products.edit', $this->product->id));

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('Bol.com synchronisatiestatus')
        ->and($body)->toContain('Synchronisatie mislukt')
        ->and($body)->toContain('De EAN-code is ongeldig.')
        ->and($body)->toContain('Sync opnieuw proberen');
});

it('retry route dispatches a SyncProductWithBolComJob and redirects', function () {
    Queue::fake();
    $this->loginAsAdmin();

    $response = $this->post(route('admin.custom.bolCom.product.retry', $this->product->id));

    $response->assertRedirect();
    Queue::assertPushed(SyncProductWithBolComJob::class);

    $product = $this->product->fresh();
    expect($product->bol_sync_state)->toBe(BolSyncState::Idle);
});

it('retry route refuses if no credentials linked', function () {
    $this->product->bolComCredentials()->detach();
    $this->loginAsAdmin();

    $response = $this->post(route('admin.custom.bolCom.product.retry', $this->product->id));

    $response->assertRedirect();
    expect(session('error'))->toContain('Geen Bol.com');
});
