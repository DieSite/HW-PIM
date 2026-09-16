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
 * Every AI field is always posted, blank unless given. The repository collects
 * the form in `static` variables inside recursiveArray(), which survive for the
 * whole test process, so a partial save would quietly re-save whatever an
 * earlier test posted.
 *
 * @param  array<string, string>  $style
 * @param  array<string, array<string, string>>  $blocks  Per text block, keyed by field code.
 */
function saveAiConfig(array $style = [], array $blocks = []): void
{
    $sections = ['style' => $style];

    foreach (array_keys((array) config('ai.fields')) as $field) {
        $sections[$field] = $blocks[$field] ?? [];
    }

    $payload = [];

    foreach ($sections as $section => $values) {
        $key = "general.ai_texts.{$section}";
        $names = collect(config('core'))->firstWhere('key', $key)['fields'] ?? [];

        foreach (array_column($names, 'name') as $name) {
            $payload['general']['ai_texts'][$section][$name] = $values[$name] ?? '';
        }
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
        ->assertSee('Verboden formuleringen')
        ->assertSee('Algemene schrijfstijl')
        ->assertSee('Beschrijving lang')
        ->assertSee('Beschrijving kort')
        ->assertSee('Meta beschrijving')
        ->assertSee('Opdracht')
        // The provider/model/API-key section was removed; config/ai.php owns it.
        ->assertDontSee('Aanbieder')
        ->assertDontSee('API-sleutel');
});

it('takes the mechanics from config/ai.php', function () {
    $settings = app(AiSettings::class);

    expect($settings->driver())->toBe('gemini')
        ->and($settings->enabled())->toBeTrue()
        ->and($settings->driverConfig()['model'])->toBe(config('ai.drivers.gemini.model'))
        ->and($settings->toneOfVoice())->toBeNull();
});

it('builds the driver config/ai.php selected', function () {
    config(['ai.driver' => 'openai']);

    expect(app(AiClientManager::class)->client())->toBeInstanceOf(OpenAiDriver::class);

    config(['ai.driver' => 'gemini']);
    app()->forgetInstance(AiClientManager::class);

    expect(app(AiClientManager::class)->client())->toBeInstanceOf(GeminiDriver::class);
});

it('switches the generate buttons off from config/ai.php', function () {
    config(['ai.enabled' => false]);

    expect(app(AiSettings::class)->enabled())->toBeFalse();
});

it('puts the admin tone of voice into the house style', function () {
    saveAiConfig(['tone_of_voice' => 'Kort en droog, geen bijvoeglijke naamwoorden.']);

    $instruction = app(App\Services\AI\ProductDescriptionGenerator::class)->systemInstruction();

    expect($instruction)->toContain('Kort en droog, geen bijvoeglijke naamwoorden.');
});

it('appends the admin banned phrases to the built-in list', function () {
    saveAiConfig(['banned_phrases' => "waanzinnig mooi\n\nabsolute topper"]);

    $phrases = app(AiSettings::class)->bannedPhrases();

    expect($phrases)->toContain('waanzinnig mooi')
        ->toContain('absolute topper')
        // The built-in list survives.
        ->toContain('ware blikvanger');
});

it('adds the admin extra instructions to the house style', function () {
    saveAiConfig(['extra_instructions' => 'Noem altijd de gratis bezorging boven 500 euro.']);

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

    config(['ai.enabled' => false]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.catalog.products.edit', ['id' => $product->id]))
        ->assertOk()
        ->assertDontSee('Teksten genereren (AI)');
});

it('refuses to call the model when the setting is off', function () {
    config(['ai.enabled' => false]);

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

/**
 * Generates texts through a stub model and hands back what it was sent, so a
 * test can see where the block settings ended up.
 *
 * @param  list<string>|null  $fields
 * @param  array<string, mixed>  $values  Create-form values; sku and productnaam are filled in.
 * @param  list<array<string, string>>  $responses  Per call, merged over valid texts; the last one repeats.
 * @return array{prompt: string, system: string, problems: list<array{field:string, rule:string, message:string}>, texts: array<string, string>, requests: list<App\Services\AI\AiRequest>}
 */
function generateWithBlockSettings(?array $fields = null, array $values = [], array $responses = [[]]): array
{
    $client = new class($responses) implements App\Services\AI\AiTextClient
    {
        /** @var list<App\Services\AI\AiRequest> */
        public array $requests = [];

        public function __construct(private array $responses) {}

        public function complete(App\Services\AI\AiRequest $request): App\Services\AI\AiResponse
        {
            $this->requests[] = $request;

            $override = $this->responses[min(count($this->requests), count($this->responses)) - 1];

            return new App\Services\AI\AiResponse(json_encode([
                'beschrijving_l'    => str_repeat('Een nuchtere zin over dit wollen kleed. ', 12),
                'beschrijving_k'    => str_repeat('Dit kleed is verkrijgbaar in meerdere maten. ', 8),
                'meta_beschrijving' => 'Vloerkleed Diamante 01 met een beige gemeleerd dessin. Bekijk het online bij Huis en Wonen of kom langs in Gorinchem vandaag.',
                ...$override,
            ]), 'fake-model', 100, 50);
        }

        public function model(): string
        {
            return 'fake-model';
        }
    };

    app()->bind(AiClientManager::class, fn () => new class($client) extends AiClientManager
    {
        public function __construct(private App\Services\AI\AiTextClient $fake)
        {
            parent::__construct(app(AiSettings::class));
        }

        public function client(?string $driver = null): App\Services\AI\AiTextClient
        {
            return $this->fake;
        }
    });

    $result = app(App\Services\AI\ProductDescriptionGenerator::class)
        ->generateFromValues(['sku' => 'AIBLOK-1', 'productnaam' => 'Diamante 01', ...$values], $fields);

    return [
        'prompt'   => $client->requests[0]->prompt,
        'system'   => $client->requests[0]->systemInstruction,
        'problems' => $result['problems'],
        'texts'    => $result['texts'],
        'requests' => $client->requests,
    ];
}

it('uses the built-in brief for a block that was left empty', function () {
    $sent = generateWithBlockSettings();

    expect($sent['prompt'])->toContain(App\Services\AI\ProductDescriptionGenerator::FIELD_BRIEFS['beschrijving_l'])
        ->not->toContain('Toon voor deze tekst')
        ->not->toContain('Extra instructies voor deze tekst');
});

it('replaces the brief of one block only', function () {
    saveAiConfig(blocks: ['beschrijving_k' => ['instruction' => 'Alleen de maten, als doorlopende zin.']]);

    $prompt = generateWithBlockSettings()['prompt'];

    expect($prompt)->toContain('beschrijving_k (Beschrijving kort, 250-800 tekens platte tekst): Alleen de maten, als doorlopende zin.')
        ->not->toContain(App\Services\AI\ProductDescriptionGenerator::FIELD_BRIEFS['beschrijving_k'])
        ->toContain(App\Services\AI\ProductDescriptionGenerator::FIELD_BRIEFS['beschrijving_l']);
});

it('puts block tone and extra instructions under that block, and keeps the global style shared', function () {
    saveAiConfig(
        style: ['tone_of_voice' => 'Algemene nuchtere toon.', 'extra_instructions' => 'Algemene extra regel.'],
        blocks: ['meta_beschrijving' => [
            'tone_of_voice'      => 'Wervend en kort.',
            'extra_instructions' => 'Eindig met de merknaam.',
        ]],
    );

    $sent = generateWithBlockSettings();

    $metaBlock = Illuminate\Support\Str::after($sent['prompt'], '- meta_beschrijving');

    expect($metaBlock)->toContain('Toon voor deze tekst (gaat voor de algemene toon): Wervend en kort.')
        ->toContain('Extra instructies voor deze tekst: Eindig met de merknaam.')
        ->and(Illuminate\Support\Str::before($sent['prompt'], '- meta_beschrijving'))->not->toContain('Wervend en kort.')
        ->and($sent['system'])->toContain('Algemene nuchtere toon.')
        ->toContain('Algemene extra regel.')
        ->not->toContain('Wervend en kort.');
});

it('bans a block phrase in that block only', function () {
    saveAiConfig(blocks: ['beschrijving_l' => ['banned_phrases' => "nuchtere zin\n\nware blikvanger"]]);

    expect(app(AiSettings::class)->fieldBannedPhrases('beschrijving_l'))
        // Already banned globally, so not repeated per block.
        ->toBe(['nuchtere zin'])
        ->and(app(AiSettings::class)->fieldBannedPhrases('beschrijving_k'))->toBe([])
        ->and(app(AiSettings::class)->bannedPhrases())->not->toContain('nuchtere zin');

    $sent = generateWithBlockSettings();

    expect($sent['prompt'])->toContain('Vermijd in deze tekst ook: nuchtere zin');

    $banned = collect($sent['problems'])->where('rule', 'banned_phrase');

    expect($banned->pluck('field')->unique()->all())->toBe(['beschrijving_l']);
});

it('detects a reference to another block by its field code', function () {
    saveAiConfig(blocks: [
        'beschrijving_k'    => ['instruction' => 'Een samenvatting van beschrijving_l in twee zinnen.'],
        'meta_beschrijving' => ['extra_instructions' => 'Niet te verwarren met beschrijving_lang of beschrijving_kort.'],
    ]);

    $settings = app(AiSettings::class);

    expect($settings->fieldReferences('beschrijving_k'))->toBe(['beschrijving_l'])
        ->and($settings->fieldReferences('meta_beschrijving'))->toBe([])
        ->and($settings->fieldReferences('beschrijving_l'))->toBe([]);
});

it('writes the referenced block first when both are generated together', function () {
    saveAiConfig(blocks: ['beschrijving_l' => ['instruction' => 'Een uitbreiding van meta_beschrijving.']]);

    $sent = generateWithBlockSettings();
    $schema = $sent['requests'][0]->jsonSchema;

    expect($schema['propertyOrdering'])->toBe(['meta_beschrijving', 'beschrijving_l', 'beschrijving_k'])
        ->and($schema['required'])->toBe(['meta_beschrijving', 'beschrijving_l', 'beschrijving_k'])
        ->and($sent['prompt'])->toContain('VERWIJZINGEN TUSSEN TEKSTEN')
        ->toContain('- beschrijving_l bouwt voort op meta_beschrijving: schrijf eerst meta_beschrijving')
        ->not->toContain('HUIDIGE TEKST VAN');
});

it('hands over the current text of a referenced block that is not rewritten', function () {
    saveAiConfig(blocks: ['beschrijving_k' => ['instruction' => 'Een samenvatting van beschrijving_l.']]);

    $sent = generateWithBlockSettings(
        fields: ['beschrijving_k'],
        values: ['beschrijving_l' => '<p>Een handgeweven kleed in zandtinten met een zachte hoogpool.</p>'],
    );

    expect($sent['requests'][0]->jsonSchema['required'])->toBe(['beschrijving_k'])
        ->and($sent['prompt'])->toContain('- beschrijving_k bouwt voort op de huidige tekst van beschrijving_l, hieronder.')
        ->toContain("HUIDIGE TEKST VAN beschrijving_l (Beschrijving lang)\nEen handgeweven kleed in zandtinten met een zachte hoogpool.")
        ->and(array_keys($sent['texts']))->toBe(['beschrijving_k']);
});

it('also writes a referenced block that is still empty', function () {
    saveAiConfig(blocks: ['beschrijving_k' => ['instruction' => 'Een samenvatting van beschrijving_l.']]);

    $sent = generateWithBlockSettings(fields: ['beschrijving_k'], values: ['beschrijving_l' => 'null']);

    expect($sent['requests'][0]->jsonSchema['propertyOrdering'])->toBe(['beschrijving_l', 'beschrijving_k'])
        ->and(array_keys($sent['texts']))->toBe(['beschrijving_l', 'beschrijving_k'])
        ->and($sent['prompt'])->toContain('schrijf eerst beschrijving_l');
});

it('rewrites a dependent text when its source is rewritten', function () {
    saveAiConfig(blocks: ['beschrijving_k' => ['instruction' => 'Een samenvatting van beschrijving_l.']]);

    $sent = generateWithBlockSettings(responses: [
        ['beschrijving_l' => '<p>Te kort.</p>'],
        [],
    ]);

    expect($sent['requests'])->toHaveCount(2)
        ->and($sent['requests'][1]->jsonSchema['required'])->toBe(['beschrijving_l', 'beschrijving_k'])
        ->and($sent['problems'])->toBe([]);
});

it('does not send the ordering hint to OpenAI, whose strict schema rejects it', function () {
    Illuminate\Support\Facades\Http::fake([
        '*' => Illuminate\Support\Facades\Http::response([
            'model'   => 'gpt-5',
            'choices' => [['message' => ['content' => '{"beschrijving_l":"x"}']]],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]),
    ]);

    (new OpenAiDriver(
        ['api_key' => 'test', 'model' => 'gpt-5'],
        ['timeout' => 5, 'max_tokens' => 100, 'temperature' => 1.0, 'retries' => 1],
    ))->complete(new App\Services\AI\AiRequest(
        systemInstruction: 'systeem',
        prompt: 'opdracht',
        jsonSchema: ['type' => 'object', 'properties' => ['beschrijving_l' => ['type' => 'string']], 'required' => ['beschrijving_l'], 'propertyOrdering' => ['beschrijving_l'], 'additionalProperties' => false],
    ));

    Illuminate\Support\Facades\Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
        $schema = $request['response_format']['json_schema']['schema'];

        return ! array_key_exists('propertyOrdering', $schema) && $schema['required'] === ['beschrijving_l'];
    });
});
