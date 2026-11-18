<?php

use App\Services\WooCommerce\WooCommerceAttributeSync;
use Illuminate\Support\Facades\DB;
use Webkul\WooCommerce\Models\Credential;
use Webkul\WooCommerce\Models\DataTransferMapping;
use Webkul\WooCommerce\Services\WooCommerceService;

const CUSHION_SHOP = 'https://shop.example/';

function cushionShopCredential(): array
{
    $credential = Credential::query()->create([
        'shopUrl'        => CUSHION_SHOP,
        'consumerKey'    => 'ck_test',
        'consumerSecret' => 'cs_test',
        'active'         => 1,
        'defaultSet'     => 1,
        'extras'         => ['custom_field' => ['maat', 'onderkleed']],
    ]);

    return $credential->toArray();
}

function cushionAttribute(string $code, string $name): void
{
    $id = DB::table('attributes')->insertGetId(['code' => $code, 'type' => 'select']);
    DB::table('attribute_translations')->insert(['attribute_id' => $id, 'locale' => 'nl_NL', 'name' => $name]);
}

it('creates a cushion attribute in WooCommerce, maps it and adds it to the custom fields', function () {
    $credential = cushionShopCredential();
    cushionAttribute('kussenmaat', 'Maat kussen');

    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn($credential);
    $connector->shouldReceive('requestApiAction')->with('getAttributes', [], ['credential' => $credential['id']])->once()->andReturn([['id' => 3, 'slug' => 'pa_maat']]);
    $connector->shouldReceive('requestApiAction')->with('addAttribute', ['name' => 'Maat kussen', 'slug' => 'kussenmaat', 'type' => 'select'], ['credential' => $credential['id']])->once()->andReturn(['id' => 41]);

    $sync = app(WooCommerceAttributeSync::class);

    expect($sync->ensure('kussenmaat'))->toBe(41)
        ->and($sync->ensure('kussenmaat'))->toBe(41)
        ->and(DataTransferMapping::query()->where(['entityType' => 'attribute', 'code' => 'kussenmaat', 'apiUrl' => CUSHION_SHOP])->value('externalId'))->toBe('41')
        ->and(Credential::query()->find($credential['id'])->extras['custom_field'])->toBe(['maat', 'onderkleed', 'kussenmaat']);
});

it('adopts a shop attribute with the same slug instead of creating a duplicate', function () {
    $credential = cushionShopCredential();

    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn($credential);
    $connector->shouldReceive('requestApiAction')->with('getAttributes', Mockery::any(), Mockery::any())->once()->andReturn([['id' => 27, 'slug' => 'pa_kleur']]);
    $connector->shouldNotReceive('requestApiAction')->with('addAttribute', Mockery::any(), Mockery::any());

    expect(app(WooCommerceAttributeSync::class)->ensure('kleur'))->toBe(27);
});

it('fails loudly when WooCommerce does not create the attribute', function () {
    $credential = cushionShopCredential();

    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn($credential);
    $connector->shouldReceive('requestApiAction')->with('getAttributes', Mockery::any(), Mockery::any())->andReturn([]);
    $connector->shouldReceive('requestApiAction')->with('addAttribute', Mockery::any(), Mockery::any())->andReturn(['code' => 'woocommerce_rest_cannot_create', 'message' => 'nope']);

    expect(fn () => app(WooCommerceAttributeSync::class)->ensure('vulling'))->toThrow(RuntimeException::class, 'did not create attribute vulling');

    expect(DataTransferMapping::query()->where('code', 'vulling')->exists())->toBeFalse();
});

it('sets up every cushion variation attribute through the command', function () {
    $credential = cushionShopCredential();

    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn($credential);
    $connector->shouldReceive('requestApiAction')->with('getAttributes', Mockery::any(), Mockery::any())->andReturn([]);
    $connector->shouldReceive('requestApiAction')->with('addAttribute', Mockery::any(), Mockery::any())->times(3)->andReturn(['id' => 50], ['id' => 51], ['id' => 52]);

    $this->artisan('woocommerce:setup-cushion-attributes')->assertSuccessful();

    expect(DataTransferMapping::query()->where('entityType', 'attribute')->whereIn('code', ['kussenmaat', 'kleur', 'vulling'])->pluck('externalId', 'code')->all())
        ->toEqual(['kussenmaat' => '50', 'kleur' => '51', 'vulling' => '52']);
});

it('sends nothing on a dry run', function () {
    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn(cushionShopCredential());
    $connector->shouldNotReceive('requestApiAction');

    $this->artisan('woocommerce:setup-cushion-attributes --dry-run')
        ->expectsOutputToContain('Would create kussenmaat')
        ->assertSuccessful();
});

it('does nothing without a shop credential', function () {
    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldReceive('getCredentialForQuickExport')->andReturn(null);
    $connector->shouldNotReceive('requestApiAction');

    expect(app(WooCommerceAttributeSync::class)->ensure('kleur'))->toBeNull();
    $this->artisan('woocommerce:setup-cushion-attributes')->assertFailed();
});

it('only calls the shop from the migration in production', function () {
    $connector = $this->mock(WooCommerceService::class);
    $connector->shouldNotReceive('getCredentialForQuickExport');
    $connector->shouldNotReceive('requestApiAction');

    (require database_path('migrations/2026_11_18_000002_setup_cushion_woocommerce_attributes.php'))->up();
});
