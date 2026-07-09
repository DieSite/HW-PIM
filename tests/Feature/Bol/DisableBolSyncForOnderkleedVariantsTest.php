<?php

use App\Enums\BolSyncState;
use App\Jobs\SyncProductWithBolComJob;
use App\Models\BolComCredential;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function makeOnderkleedProduct(array $common, bool $bolComSync = true): Product
{
    $familyId = DB::table('attribute_families')->value('id')
        ?? DB::table('attribute_families')->insertGetId(['code' => 'fam_'.uniqid(), 'status' => 1]);

    $product = new Product();
    $product->attribute_family_id = $familyId;
    $product->sku = 'ONDERKLEEDTEST-'.uniqid();
    $product->type = 'simple';
    $product->status = 1;
    $product->values = ['common' => $common];
    $product->bol_com_sync = $bolComSync;
    $product->bol_sync_state = BolSyncState::Live;
    $product->save();

    return $product;
}

it('disables and detaches only the non-"Zonder onderkleed" variants, without dispatching retire jobs', function () {
    Queue::fake();

    $credential = BolComCredential::create([
        'name'          => 'Testaccount',
        'client_id'     => 'client',
        'client_secret' => 'secret',
        'is_active'     => true,
    ]);

    $base = makeOnderkleedProduct(['ean' => '5414461168639', 'onderkleed' => 'Zonder onderkleed']);
    $base->bolComCredentials()->attach($credential->id, ['reference' => 'base-offer-ref']);

    $metOnderkleed = makeOnderkleedProduct(['ean' => '5414461168639', 'onderkleed' => 'Met onderkleed']);
    $metOnderkleed->bolComCredentials()->attach($credential->id, ['reference' => 'stale-offer-ref']);

    $noOnderkleed = makeOnderkleedProduct(['ean' => '5414461168701']);
    $disabled = makeOnderkleedProduct(['ean' => '5414461168718', 'onderkleed' => 'Met onderkleed'], bolComSync: false);

    $this->artisan('bolcom:disable-met-onderkleed', ['--force' => true])
        ->expectsOutputToContain($metOnderkleed->sku)
        ->assertSuccessful();

    $metOnderkleed->refresh();
    expect($metOnderkleed->bol_com_sync)->toBeFalsy()
        ->and($metOnderkleed->bol_sync_state)->toBe(BolSyncState::Idle)
        ->and($metOnderkleed->bolComCredentials()->count())->toBe(0);

    $base->refresh();
    expect($base->bol_com_sync)->toBeTruthy()
        ->and($base->bol_sync_state)->toBe(BolSyncState::Live)
        ->and($base->bolComCredentials()->first()?->pivot?->reference)->toBe('base-offer-ref');

    expect($noOnderkleed->refresh()->bol_com_sync)->toBeTruthy();

    Queue::assertNotPushed(SyncProductWithBolComJob::class);
});

it('catches enabled variants whose values column is double-encoded', function () {
    Queue::fake();

    $double = makeOnderkleedProduct(['ean' => '5414461168725', 'onderkleed' => 'Met onderkleed']);
    DB::table('products')->where('id', $double->id)->update([
        'values' => json_encode(json_encode(['common' => ['ean' => '5414461168725', 'onderkleed' => 'Met onderkleed']])),
    ]);

    $this->artisan('bolcom:disable-met-onderkleed', ['--force' => true])
        ->expectsOutputToContain($double->sku)
        ->assertSuccessful();

    $double->refresh();
    expect($double->bol_com_sync)->toBeFalsy()
        ->and($double->values)->toBeArray()
        ->and($double->values['common']['onderkleed'])->toBe('Met onderkleed');
});

it('changes nothing on a dry run', function () {
    $metOnderkleed = makeOnderkleedProduct(['ean' => '5414461168732', 'onderkleed' => 'Met onderkleed']);

    $this->artisan('bolcom:disable-met-onderkleed', ['--dry-run' => true])
        ->expectsOutputToContain($metOnderkleed->sku)
        ->assertSuccessful();

    expect($metOnderkleed->refresh()->bol_com_sync)->toBeTruthy();
});
