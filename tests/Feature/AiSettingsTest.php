<?php

use App\Services\AI\AiClientManager;
use App\Services\AI\AiSettings;
use App\Services\AI\Drivers\GeminiDriver;
use App\Services\AI\Drivers\OpenAiDriver;

/**
 * Save through the real Configuration screen rather than straight into
 * core_config: CoreConfigRepository is a cached repository (see
 * config/repository.php), and the cache is only flushed by the Eloquent events
 * that the repository fires. A raw DB write would leave a stale read behind and
 * the test would be measuring the cache instead of the setting.
 *
 * @param  array<string, string>  $settings
 * @param  array<string, string>  $style
 */
function saveAiConfig(array $settings = [], array $style = []): void
{
    $payload = [];

    if ($settings !== []) {
        $payload['general']['ai_texts']['settings'] = $settings;
    }

    if ($style !== []) {
        $payload['general']['ai_texts']['style'] = $style;
    }

    test()
        ->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.configuration.store', ['slug' => 'general', 'slug2' => 'ai_texts']), $payload)
        ->assertRedirect();

    app()->forgetInstance('core');
}

it('is reachable from the Configuratie menu', function () {
    $entry = collect(config('menu.admin'))->firstWhere('key', 'configuration.ai-texts');

    expect($entry)->not->toBeNull()
        ->and($entry['route'])->toBe('admin.configuration.edit')
        ->and($entry['params'])->toBe(['general', 'ai_texts']);
});

it('deliberately has no ACL entry of its own', function () {
    /**
     * Bouncer maps a single permission key per route name
     * ($acl->roles[Route::currentRouteName()]), and every configuration section
     * shares admin.configuration.edit. Adding an entry here would silently
     * change which permission guards Kortingen, Magic AI and Hoofdafbeelding —
     * whichever key is listed last wins. Menu entry only, the same as
     * general.afwerkingen and general.pricing, until that collision is fixed.
     */
    expect(collect(config('acl'))->firstWhere('key', 'configuration.ai-texts'))->toBeNull();

    $sharingTheRoute = collect(config('acl'))
        ->filter(fn (array $item): bool => ($item['route'] ?? null) === 'admin.configuration.edit')
        ->pluck('key');

    expect($sharingTheRoute)->not->toContain('configuration.ai-texts');
});

it('renders the settings screen', function () {
    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.configuration.edit', ['slug' => 'general', 'slug2' => 'ai_texts']))
        ->assertOk()
        ->assertSee('AI-teksten')
        ->assertSee('Tone of voice')
        ->assertSee('Verboden formuleringen');
});

it('falls back to config/ai.php when nothing is saved', function () {
    $settings = app(AiSettings::class);

    expect($settings->driver())->toBe('gemini')
        ->and($settings->enabled())->toBeTrue()
        ->and($settings->toneOfVoice())->toBeNull();
});

it('lets the admin screen override the driver and model', function () {
    saveAiConfig(['driver' => 'openai', 'model' => 'gpt-5-mini', 'api_key' => 'sleutel-uit-de-admin']);

    $settings = app(AiSettings::class);

    expect($settings->driver())->toBe('openai')
        ->and($settings->driverConfig()['model'])->toBe('gpt-5-mini')
        ->and($settings->driverConfig()['api_key'])->toBe('sleutel-uit-de-admin');
});

it('builds the driver the admin screen selected', function () {
    saveAiConfig(['driver' => 'openai']);

    expect(app(AiClientManager::class)->client())->toBeInstanceOf(OpenAiDriver::class);

    saveAiConfig(['driver' => 'gemini']);
    app()->forgetInstance(AiClientManager::class);

    expect(app(AiClientManager::class)->client())->toBeInstanceOf(GeminiDriver::class);
});

it('keeps the deployed default when a field is saved empty', function () {
    saveAiConfig(['model' => '']);

    expect(app(AiSettings::class)->driverConfig()['model'])->toBe(config('ai.drivers.gemini.model'));
});

it('switches the generate buttons off from the admin screen', function () {
    saveAiConfig(['enabled' => '0']);

    expect(app(AiSettings::class)->enabled())->toBeFalse();
});

it('puts the admin tone of voice into the house style', function () {
    saveAiConfig(style: ['tone_of_voice' => 'Kort en droog, geen bijvoeglijke naamwoorden.']);

    $instruction = app(App\Services\AI\ProductDescriptionGenerator::class)->systemInstruction();

    expect($instruction)->toContain('Kort en droog, geen bijvoeglijke naamwoorden.');
});

it('appends the admin banned phrases to the built-in list', function () {
    saveAiConfig(style: ['banned_phrases' => "waanzinnig mooi\n\nabsolute topper"]);

    $phrases = app(AiSettings::class)->bannedPhrases();

    expect($phrases)->toContain('waanzinnig mooi')
        ->toContain('absolute topper')
        // The built-in list survives.
        ->toContain('ware blikvanger');
});

it('adds the admin extra instructions to the house style', function () {
    saveAiConfig(style: ['extra_instructions' => 'Noem altijd de gratis bezorging boven 500 euro.']);

    expect(app(App\Services\AI\ProductDescriptionGenerator::class)->systemInstruction())
        ->toContain('EXTRA INSTRUCTIES')
        ->toContain('Noem altijd de gratis bezorging boven 500 euro.');
});

it('hides the generate buttons on the product page when the setting is off', function () {
    $familyId = (int) Illuminate\Support\Facades\DB::table('attribute_families')->orderBy('id')->value('id');

    $product = new App\Models\Product();
    $product->attribute_family_id = $familyId;
    $product->sku = 'AISET-'.uniqid();
    $product->type = 'configurable';
    $product->status = 1;
    $product->values = ['common' => ['productnaam' => 'Diamante 01']];
    $product->save();

    $admin = Webkul\User\Models\Admin::query()->firstOrFail();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.catalog.products.edit', ['id' => $product->id]))
        ->assertOk()
        ->assertSee('Teksten genereren (AI)');

    saveAiConfig(['enabled' => '0']);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.catalog.products.edit', ['id' => $product->id]))
        ->assertOk()
        ->assertDontSee('Teksten genereren (AI)');
});

it('refuses to call the model when the setting is off', function () {
    saveAiConfig(['enabled' => '0']);

    $familyId = (int) Illuminate\Support\Facades\DB::table('attribute_families')->orderBy('id')->value('id');

    $product = new App\Models\Product();
    $product->attribute_family_id = $familyId;
    $product->sku = 'AISET-'.uniqid();
    $product->type = 'configurable';
    $product->status = 1;
    $product->values = ['common' => ['productnaam' => 'Diamante 01']];
    $product->save();

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.catalog.products.ai-description.generate'), ['product_id' => $product->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'AI-teksten staan uit in de configuratie.');
});

it('fills the provider dropdown with the available drivers', function () {
    $html = $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.configuration.edit', ['slug' => 'general', 'slug2' => 'ai_texts']))
        ->assertOk()
        ->getContent();

    /**
     * type="select" renders a Vue component that reads an :options prop; the
     * <option> tags the configuration blade used to emit were dropped on the
     * floor and the dropdown showed "List is empty".
     */
    expect($html)->toContain('Google Gemini (standaard)')
        ->toContain('OpenAI')
        ->toContain('track-by="value"');
});
