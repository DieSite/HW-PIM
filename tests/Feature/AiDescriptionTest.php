<?php

use App\Jobs\ApplyAiDescriptionsJob;
use App\Jobs\GenerateProductDescriptionJob;
use App\Models\AiDescriptionDraft;
use App\Models\AiDescriptionRun;
use App\Models\Product;
use App\Services\AI\AiClientManager;
use App\Services\AI\AiDescriptionService;
use App\Services\AI\AiRequest;
use App\Services\AI\AiResponse;
use App\Services\AI\AiTextClient;
use App\Services\AI\ProductDescriptionGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Stands in for the model so the tests never make a network call, and so a test
 * can dictate exactly what came back.
 */
class FakeAiTextClient implements AiTextClient
{
    /** @var list<AiRequest> */
    public array $requests = [];

    /**
     * @param  list<string>  $responses  Returned in order; the last one repeats.
     */
    public function __construct(private array $responses) {}

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        $index = min(count($this->requests) - 1, count($this->responses) - 1);

        return new AiResponse($this->responses[$index], 'fake-model', 100, 50);
    }

    public function model(): string
    {
        return 'fake-model';
    }
}

function fakeAiTexts(?string $long = null, ?string $short = null, ?string $meta = null): string
{
    return json_encode([
        'beschrijving_l'    => $long ?? str_repeat('Een nuchtere zin over dit wollen kleed. ', 12),
        'beschrijving_k'    => $short ?? str_repeat('De standaardmaat is 170 cm x 240 cm. ', 9),
        'meta_beschrijving' => $meta ?? 'Vloerkleed Diamante 01 van De Munk met een beige gemeleerd dessin. Bekijk het online bij Huis en Wonen of kom langs in ons Experience Center in Gorinchem.',
    ]);
}

function useFakeAiClient(FakeAiTextClient $client): void
{
    app()->bind(AiClientManager::class, fn () => new class($client) extends AiClientManager
    {
        public function __construct(private FakeAiTextClient $fake)
        {
            parent::__construct(app(App\Services\AI\AiSettings::class));
        }

        public function client(?string $driver = null): AiTextClient
        {
            return $this->fake;
        }
    });
}

/**
 * @param  array<string, mixed>  $common
 */
function makeAiProduct(array $common, ?int $parentId = null): Product
{
    $product = new Product();
    $product->attribute_family_id = aiTestFamilyId();
    $product->sku = 'AITEST-'.uniqid();
    $product->type = $parentId ? 'simple' : 'configurable';
    $product->parent_id = $parentId;
    $product->status = 1;
    $product->values = ['common' => $common];
    $product->save();

    return $product;
}

/**
 * Create the attribute and hang it on the default family's first group, so the
 * product edit form actually renders a field for it — otherwise the per-field
 * button has nothing to attach to.
 */
function makeAiAttribute(string $code): void
{
    $attributeId = DB::table('attributes')->where('code', $code)->value('id');

    if (! $attributeId) {
        $attributeId = DB::table('attributes')->insertGetId([
            'code'              => $code,
            'type'              => 'textarea',
            'position'          => 1,
            'is_required'       => 0,
            'is_unique'         => 0,
            'value_per_locale'  => 0,
            'value_per_channel' => 0,
            'enable_wysiwyg'    => 1,
            'usable_in_grid'    => 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    $familyGroupId = DB::table('attribute_family_group_mappings')
        ->where('attribute_family_id', aiTestFamilyId())
        ->orderBy('position')
        ->value('id');

    if (! $familyGroupId) {
        return;
    }

    $exists = DB::table('attribute_group_mappings')
        ->where('attribute_id', $attributeId)
        ->where('attribute_family_group_id', $familyGroupId)
        ->exists();

    if (! $exists) {
        DB::table('attribute_group_mappings')->insert([
            'attribute_id'              => $attributeId,
            'attribute_family_group_id' => $familyGroupId,
            'position'                  => 99,
        ]);
    }
}

function aiTestFamilyId(): int
{
    return (int) DB::table('attribute_families')->orderBy('id')->value('id');
}

beforeEach(function () {
    foreach (array_keys((array) config('ai.fields')) as $code) {
        makeAiAttribute($code);
    }

    config()->set('ai.enabled', true);
});

it('generates the three texts for a product', function () {
    useFakeAiClient(new FakeAiTextClient([fakeAiTexts()]));

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk', 'collectie' => 'Modern']);
    makeAiProduct(['maat' => '170 cm x 240 cm', 'onderkleed' => 'Zonder onderkleed'], $parent->id);

    $result = app(ProductDescriptionGenerator::class)->generate($parent);

    expect($result['texts'])->toHaveKeys(['beschrijving_l', 'beschrijving_k', 'meta_beschrijving'])
        ->and($result['problems'])->toBe([])
        ->and($result['texts']['beschrijving_l'])->toStartWith('<p>');
});

it('feeds the product data and the requested fields into the prompt', function () {
    $client = new FakeAiTextClient([fakeAiTexts()]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk', 'kleuren' => 'Beige|Ecru']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    app(ProductDescriptionGenerator::class)->generate($parent);

    $prompt = $client->requests[0]->prompt;

    expect($prompt)->toContain('Diamante 01')
        ->toContain('De Munk')
        ->toContain('Beige')
        ->toContain('170 cm x 240 cm')
        ->toContain('INVALSHOEK');
});

it('always sends the same house style so a bulk run can cache it', function () {
    $client = new FakeAiTextClient([fakeAiTexts()]);
    useFakeAiClient($client);

    $first = makeAiProduct(['productnaam' => 'Kleed A', 'merk' => 'De Munk']);
    $second = makeAiProduct(['productnaam' => 'Kleed B', 'merk' => 'Karpi']);

    app(ProductDescriptionGenerator::class)->generate($first);
    app(ProductDescriptionGenerator::class)->generate($second);

    expect($client->requests[0]->systemInstruction)->toBe($client->requests[1]->systemInstruction);
});

it('gives neighbouring products a different angle', function () {
    $client = new FakeAiTextClient([fakeAiTexts()]);
    useFakeAiClient($client);

    $angles = collect(range(1, 12))
        ->map(function (int $index) use ($client) {
            $product = makeAiProduct(['productnaam' => "Kleed {$index}", 'merk' => 'De Munk']);
            app(ProductDescriptionGenerator::class)->generate($product);

            return str($client->requests[count($client->requests) - 1]->prompt)
                ->after('INVALSHOEK')
                ->trim()
                ->toString();
        })
        ->unique();

    expect($angles->count())->toBeGreaterThan(1);
});

it('retries once when the model invents a size, and keeps the problems if it does it again', function () {
    $invented = fakeAiTexts(short: str_repeat('De standaardmaat is 999 cm x 888 cm. ', 9));

    $client = new FakeAiTextClient([$invented]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $result = app(ProductDescriptionGenerator::class)->generate($parent);

    expect($result['attempts'])->toBe(2)
        ->and($client->requests[1]->prompt)->toContain('CORRECTIE')
        ->and(collect($result['problems'])->pluck('rule'))->toContain('invented_size');
});

it('re-asks only for the text that failed, not for the ones that passed', function () {
    $client = new FakeAiTextClient([
        // Only the meta description is out of bounds; the other two are fine.
        fakeAiTexts(meta: 'Te kort.'),
        json_encode(['meta_beschrijving' => 'Vloerkleed Diamante 01 van De Munk met een beige gemeleerd dessin. Bekijk het online bij Huis en Wonen of kom langs in ons Experience Center in Gorinchem.']),
    ]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $result = app(ProductDescriptionGenerator::class)->generate($parent);
    $retry = $client->requests[1];

    $originalLong = json_decode(fakeAiTexts(), true)['beschrijving_l'];

    expect($result['problems'])->toBe([])
        ->and($result['attempts'])->toBe(2)
        // The long and short texts survive the retry untouched.
        ->and($result['texts']['beschrijving_l'])->toContain(trim($originalLong))
        // The follow-up asks for the meta description alone.
        ->and(array_keys($retry->jsonSchema['properties']))->toBe(['meta_beschrijving'])
        ->and($retry->prompt)->toContain('schrijf alleen deze tekst(en) opnieuw: Meta beschrijving')
        ->and($retry->prompt)->toContain('al goedgekeurd');
});

it('leaves the photo out of a retry that does not need to look at the rug', function () {
    $client = new FakeAiTextClient([
        fakeAiTexts(meta: 'Te kort.'),
        json_encode(['meta_beschrijving' => 'Vloerkleed Diamante 01 van De Munk met een beige gemeleerd dessin. Bekijk het online bij Huis en Wonen of kom langs in ons Experience Center in Gorinchem.']),
    ]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    app(ProductDescriptionGenerator::class)->generate($parent);

    expect($client->requests[1]->images)->toBe([]);
});

it('keeps the photo when the long description has to be written again', function () {
    $client = new FakeAiTextClient([
        fakeAiTexts(long: '<p>Te kort.</p>'),
        fakeAiTexts(),
    ]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    app(ProductDescriptionGenerator::class)->generate($parent);

    $retry = $client->requests[1];

    expect(array_keys($retry->jsonSchema['properties']))->toBe(['beschrijving_l']);
});

it('counts the tokens of both attempts, not just the last one', function () {
    useFakeAiClient(new FakeAiTextClient([
        fakeAiTexts(meta: 'Te kort.'),
        json_encode(['meta_beschrijving' => 'Vloerkleed Diamante 01 van De Munk met een beige gemeleerd dessin. Bekijk het online bij Huis en Wonen of kom langs in ons Experience Center in Gorinchem.']),
    ]));

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $result = app(ProductDescriptionGenerator::class)->generate($parent);

    // The fake reports 100 in / 50 out per call.
    expect($result['input_tokens'])->toBe(200)
        ->and($result['output_tokens'])->toBe(100);
});

it('accepts the second attempt when the retry fixes the problem', function () {
    $client = new FakeAiTextClient([
        fakeAiTexts(short: str_repeat('De standaardmaat is 999 cm x 888 cm. ', 9)),
        fakeAiTexts(),
    ]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $result = app(ProductDescriptionGenerator::class)->generate($parent);

    expect($result['attempts'])->toBe(2)
        ->and($result['problems'])->toBe([]);
});

it('refuses to generate when the feature is switched off', function () {
    useFakeAiClient(new FakeAiTextClient([fakeAiTexts()]));
    config()->set('ai.enabled', false);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    expect(fn () => app(ProductDescriptionGenerator::class)->generate($parent))
        ->toThrow(RuntimeException::class, 'AI-teksten staan uit');
});

it('stores a draft without touching the product', function () {
    useFakeAiClient(new FakeAiTextClient([fakeAiTexts()]));

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk', 'beschrijving_l' => '<p>Oude tekst.</p>']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    (new GenerateProductDescriptionJob($parent->id, array_keys((array) config('ai.fields'))))
        ->handle(app(ProductDescriptionGenerator::class), app(AiDescriptionService::class));

    $draft = AiDescriptionDraft::where('product_id', $parent->id)->firstOrFail();

    expect($draft->status)->toBe(AiDescriptionDraft::STATUS_PENDING)
        ->and($draft->fields)->toHaveKey('beschrijving_l')
        ->and($draft->previous_values['beschrijving_l'])->toBe('<p>Oude tekst.</p>')
        ->and(Product::find($parent->id)->values['common']['beschrijving_l'])->toBe('<p>Oude tekst.</p>');
});

it('records a failed draft instead of blowing up the run', function () {
    app()->bind(AiClientManager::class, fn () => new class(app(App\Services\AI\AiSettings::class)) extends AiClientManager
    {
        public function client(?string $driver = null): AiTextClient
        {
            throw new RuntimeException('Gemini API-fout [429]: te veel verzoeken.');
        }
    });

    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    (new GenerateProductDescriptionJob($parent->id, ['beschrijving_l']))
        ->handle(app(ProductDescriptionGenerator::class), app(AiDescriptionService::class));

    $draft = AiDescriptionDraft::where('product_id', $parent->id)->firstOrFail();

    expect($draft->status)->toBe(AiDescriptionDraft::STATUS_FAILED)
        ->and($draft->error)->toContain('429');
});

it('publishes an approved draft onto the product and can undo it', function () {
    Queue::fake();

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'beschrijving_l' => '<p>Oude tekst.</p>']);

    $draft = AiDescriptionDraft::create([
        'product_id' => $parent->id,
        'status'     => AiDescriptionDraft::STATUS_APPROVED,
        'fields'     => ['beschrijving_l' => '<p>Nieuwe tekst.</p>'],
    ]);

    $service = app(AiDescriptionService::class);
    $service->publish($draft, syncWoo: false);

    expect(Product::find($parent->id)->values['common']['beschrijving_l'])->toBe('<p>Nieuwe tekst.</p>')
        ->and($draft->fresh()->status)->toBe(AiDescriptionDraft::STATUS_APPLIED)
        ->and($draft->fresh()->previous_values['beschrijving_l'])->toBe('<p>Oude tekst.</p>');

    $service->revert($draft->fresh(), syncWoo: false);

    expect(Product::find($parent->id)->values['common']['beschrijving_l'])->toBe('<p>Oude tekst.</p>')
        ->and($draft->fresh()->status)->toBe(AiDescriptionDraft::STATUS_REJECTED);
});

it('leaves drafts that were not approved alone when publishing', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'beschrijving_l' => '<p>Oude tekst.</p>']);

    $draft = AiDescriptionDraft::create([
        'product_id' => $parent->id,
        'status'     => AiDescriptionDraft::STATUS_PENDING,
        'fields'     => ['beschrijving_l' => '<p>Nieuwe tekst.</p>'],
    ]);

    (new ApplyAiDescriptionsJob([$draft->id], syncWoo: false))->handle(app(AiDescriptionService::class));

    expect(Product::find($parent->id)->values['common']['beschrijving_l'])->toBe('<p>Oude tekst.</p>')
        ->and($draft->fresh()->status)->toBe(AiDescriptionDraft::STATUS_PENDING);
});

it('only selects parent products for a run', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    $variant = makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $ids = app(AiDescriptionService::class)
        ->matchingQuery(['brand' => 'De Munk', 'scope' => 'all'])
        ->pluck('id');

    expect($ids)->toContain($parent->id)
        ->and($ids)->not->toContain($variant->id);
});

it('finds the products whose description is shared with another product', function () {
    $shared = '<p>'.uniqid('gedeeld-', true).'</p>';

    $first = makeAiProduct(['productnaam' => 'A', 'beschrijving_l' => $shared]);
    $second = makeAiProduct(['productnaam' => 'B', 'beschrijving_l' => $shared]);
    $unique = makeAiProduct(['productnaam' => 'C', 'beschrijving_l' => '<p>'.uniqid('uniek-', true).'</p>']);

    $ids = app(AiDescriptionService::class)
        ->matchingQuery(['scope' => 'duplicates'])
        ->pluck('id');

    expect($ids)->toContain($first->id, $second->id)
        ->and($ids)->not->toContain($unique->id);
});

it('generates from raw form values for a product that does not exist yet', function () {
    $client = new FakeAiTextClient([fakeAiTexts(short: str_repeat('Maatwerk is mogelijk in overleg. ', 9))]);
    useFakeAiClient($client);

    $result = app(ProductDescriptionGenerator::class)->generateFromValues([
        'sku'         => 'NIEUW-1',
        'productnaam' => 'Nieuw kleed',
        'merk'        => 'De Munk',
    ]);

    expect($result['texts'])->toHaveKey('beschrijving_l')
        ->and($client->requests[0]->prompt)->toContain('geen enkele concrete maat');
});

it('renders the bulk tool page', function () {
    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.tools.ai-descriptions.index'))
        ->assertOk()
        ->assertSee('AI-teksten');
});

it('renders the review page with a draft on it', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'beschrijving_l' => '<p>Oude tekst.</p>']);

    AiDescriptionDraft::create([
        'product_id' => $parent->id,
        'status'     => AiDescriptionDraft::STATUS_PENDING,
        'fields'     => ['beschrijving_l' => '<p>Nieuwe tekst.</p>'],
        'similarity' => 0.12,
    ]);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.tools.ai-descriptions.review'))
        ->assertOk()
        ->assertSee('Nieuwe tekst.', escape: false)
        ->assertSee('Oude tekst.', escape: false);
});

it('renders the product edit page with the generate button', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.catalog.products.edit', ['id' => $parent->id]))
        ->assertOk()
        ->assertSee('Teksten genereren (AI)');
});

it('approves a draft through the review endpoint', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    $draft = AiDescriptionDraft::create([
        'product_id' => $parent->id,
        'status'     => AiDescriptionDraft::STATUS_PENDING,
        'fields'     => ['beschrijving_l' => '<p>Nieuwe tekst.</p>'],
    ]);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.tools.ai-descriptions.decide', ['draft' => $draft->id]), ['decision' => 'approve'])
        ->assertOk();

    expect($draft->fresh()->status)->toBe(AiDescriptionDraft::STATUS_APPROVED);
});

it('returns the generated texts from the product edit endpoint', function () {
    useFakeAiClient(new FakeAiTextClient([fakeAiTexts()]));

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.catalog.products.ai-description.generate'), ['product_id' => $parent->id])
        ->assertOk()
        ->assertJsonStructure(['texts' => ['beschrijving_l', 'beschrijving_k', 'meta_beschrijving'], 'problems', 'similarity']);
});

it('generates for the parent when the edit endpoint is given a variant', function () {
    $client = new FakeAiTextClient([fakeAiTexts()]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    $variant = makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.catalog.products.ai-description.generate'), ['product_id' => $variant->id])
        ->assertOk();

    expect($client->requests[0]->prompt)->toContain('Diamante 01');
});

it('reports a provider failure as a readable message instead of a 500', function () {
    app()->bind(AiClientManager::class, fn () => new class(app(App\Services\AI\AiSettings::class)) extends AiClientManager
    {
        public function client(?string $driver = null): AiTextClient
        {
            throw new RuntimeException('Geen Gemini API-sleutel ingesteld (GEMINI_API_KEY of admin Configuratie).');
        }
    });

    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.catalog.products.ai-description.generate'), ['product_id' => $parent->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Geen Gemini API-sleutel ingesteld (GEMINI_API_KEY of admin Configuratie).');
});

it('writes only the requested field when the per-field button asks for one', function () {
    $client = new FakeAiTextClient([json_encode(['beschrijving_l' => str_repeat('Een nuchtere zin over dit wollen kleed. ', 12)])]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);

    $response = $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.catalog.products.ai-description.generate'), [
            'product_id' => $parent->id,
            'fields'     => ['beschrijving_l'],
        ])
        ->assertOk();

    expect(array_keys($response->json('texts')))->toBe(['beschrijving_l'])
        ->and(array_keys($client->requests[0]->jsonSchema['properties']))->toBe(['beschrijving_l'])
        // The brief for the other two texts is not paid for.
        ->and($client->requests[0]->prompt)->not->toContain('meta_beschrijving');
});

it('renders a per-field AI button under each generated text field', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    $html = $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.catalog.products.edit', ['id' => $parent->id]))
        ->assertOk()
        ->getContent();

    foreach (array_keys((array) config('ai.fields')) as $code) {
        expect($html)->toContain("generateAiTexts(this, ['{$code}'])");
    }
});

it('does not offer the per-field button on a variant', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);
    $variant = makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.catalog.products.edit', ['id' => $variant->id]))
        ->assertOk()
        ->assertDontSee("generateAiTexts(this, ['beschrijving_l'])", escape: false);
});

it('prefers what is in the form over what is stored', function () {
    $client = new FakeAiTextClient([fakeAiTexts()]);
    useFakeAiClient($client);

    $parent = makeAiProduct([
        'productnaam' => 'Diamante 01',
        'merk'        => 'De Munk',
        'kleuren'     => 'Beige',
    ]);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.catalog.products.ai-description.generate'), [
            'product_id' => $parent->id,
            // The editor changed the colour but has not saved yet.
            'values'     => ['kleuren' => 'Groen|Olijf'],
        ])
        ->assertOk();

    expect($client->requests[0]->prompt)->toContain('Groen')
        ->toContain('Olijf')
        ->not->toContain('Beige')
        // Everything the form did not touch still comes from the database.
        ->toContain('De Munk')
        ->toContain('170 cm x 240 cm');
});

it('describes a product whose fields are typed but not yet saved', function () {
    $client = new FakeAiTextClient([fakeAiTexts(short: str_repeat('Maatwerk is mogelijk in overleg. ', 9))]);
    useFakeAiClient($client);

    // A product straight out of the create modal: a SKU and nothing else.
    $parent = makeAiProduct([]);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.catalog.products.ai-description.generate'), [
            'product_id' => $parent->id,
            'values'     => [
                'productnaam' => 'Nieuw Kleed 42',
                'merk'        => 'Karpi',
                'materiaal'   => 'Wol|Katoen',
            ],
        ])
        ->assertOk();

    expect($client->requests[0]->prompt)->toContain('Nieuw Kleed 42')
        ->toContain('Karpi')
        ->toContain('Wol');
});

it('ignores empty form fields rather than blanking stored values', function () {
    $client = new FakeAiTextClient([fakeAiTexts()]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01', 'merk' => 'De Munk']);
    makeAiProduct(['maat' => '170 cm x 240 cm'], $parent->id);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->postJson(route('admin.catalog.products.ai-description.generate'), [
            'product_id' => $parent->id,
            'values'     => ['merk' => '', 'kleuren' => 'Grijs'],
        ])
        ->assertOk();

    expect($client->requests[0]->prompt)->toContain('De Munk')
        ->toContain('Grijs');
});

it('sends the live form values from the edit page, not just the product id', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    $html = $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.catalog.products.edit', ['id' => $parent->id]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('values: readProductFormValues()')
        ->toContain('function readProductFormValues()');
});

it('repairs mangled accents before the text becomes a draft', function () {
    /**
     * Gemini writes "gemêleerd" correctly most of the time, but drops a stray
     * byte in its place in roughly one call in twenty. Nothing in our pipeline
     * touches the bytes, so the repair happens where model text becomes stored
     * text.
     */
    useFakeAiClient(new FakeAiTextClient([fakeAiTexts(
        long: str_repeat('Het gem#leerde vlak toont een fijn reli#f. ', 12),
        meta: 'Vloerkleed Diamante 01 van De Munk met een gem&ecirc;leerd dessin. Bekijk het online bij Huis en Wonen in Gorinchem.',
    )]));

    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    (new GenerateProductDescriptionJob($parent->id, array_keys((array) config('ai.fields'))))
        ->handle(app(ProductDescriptionGenerator::class), app(AiDescriptionService::class));

    $draft = AiDescriptionDraft::where('product_id', $parent->id)->firstOrFail();

    expect($draft->fields['beschrijving_l'])->toContain('gemêleerde')
        ->not->toContain('gem#leerde')
        ->and($draft->fields['beschrijving_l'])->toContain('reliëf')
        ->and($draft->fields['meta_beschrijving'])->toContain('gemêleerd')
        ->not->toContain('&ecirc;')
        ->and(collect($draft->problems ?? [])->pluck('rule'))->not->toContain('garbled_text');
});

it('asks the model again when a mangled word is not in the repair list', function () {
    $mangled = str_repeat('Een fraai dess#in over de hele breedte. ', 12);

    $client = new FakeAiTextClient([fakeAiTexts(long: $mangled), fakeAiTexts()]);
    useFakeAiClient($client);

    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    $result = app(ProductDescriptionGenerator::class)->generate($parent, ['beschrijving_l']);

    expect($client->requests)->toHaveCount(2)
        ->and($result['problems'])->toBe([])
        ->and($result['texts']['beschrijving_l'])->not->toContain('dess#in');
});

it('keeps a mangled text it cannot repair, flagged for the reviewer', function () {
    $mangled = str_repeat('Een fraai dess#in over de hele breedte. ', 12);

    useFakeAiClient(new FakeAiTextClient([fakeAiTexts(long: $mangled)]));

    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    $result = app(ProductDescriptionGenerator::class)->generate($parent, ['beschrijving_l']);

    expect(collect($result['problems'])->pluck('rule'))->toContain('garbled_text')
        ->and(collect($result['problems'])->firstWhere('rule', 'garbled_text')['message'])->toContain('dess#in');
});

it('offers a rewrite-all button on the review page', function () {
    $parent = makeAiProduct(['productnaam' => 'Diamante 01']);

    AiDescriptionDraft::create([
        'product_id' => $parent->id,
        'status'     => AiDescriptionDraft::STATUS_PENDING,
        'fields'     => ['beschrijving_l' => '<p>Nieuwe tekst.</p>'],
    ]);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.tools.ai-descriptions.review'))
        ->assertOk()
        ->assertSee('Alles opnieuw schrijven (1)');
});

it('rewrites every draft in the current view but leaves signed-off drafts alone', function () {
    Queue::fake();

    $run = AiDescriptionRun::create(['filters' => [], 'fields' => ['beschrijving_k'], 'status' => 'completed']);
    $otherRun = AiDescriptionRun::create(['filters' => [], 'fields' => ['beschrijving_l'], 'status' => 'completed']);

    $drafts = collect([
        'pending'  => [AiDescriptionDraft::STATUS_PENDING, $run->id, ['meta_beschrijving' => 'x']],
        'rejected' => [AiDescriptionDraft::STATUS_REJECTED, $run->id, ['beschrijving_l' => 'x']],
        'failed'   => [AiDescriptionDraft::STATUS_FAILED, $run->id, null],
        'approved' => [AiDescriptionDraft::STATUS_APPROVED, $run->id, ['beschrijving_l' => 'x']],
        'applied'  => [AiDescriptionDraft::STATUS_APPLIED, $run->id, ['beschrijving_l' => 'x']],
        'other'    => [AiDescriptionDraft::STATUS_PENDING, $otherRun->id, ['beschrijving_l' => 'x']],
    ])->map(fn (array $draft, string $name) => AiDescriptionDraft::create([
        'product_id' => makeAiProduct(['productnaam' => $name])->id,
        'status'     => $draft[0],
        'run_id'     => $draft[1],
        'fields'     => $draft[2],
    ]));

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.tools.ai-descriptions.regenerate-all'), ['run' => $run->id, 'status' => 'all'])
        ->assertRedirect()
        ->assertSessionHas('success', '3 teksten worden opnieuw geschreven. Ververs de pagina over een paar minuten.');

    Queue::assertPushed(GenerateProductDescriptionJob::class, 3);

    $pushed = collect(Queue::pushed(GenerateProductDescriptionJob::class))
        ->mapWithKeys(fn (GenerateProductDescriptionJob $job) => [$job->productId => $job]);

    expect($pushed->keys()->sort()->values()->all())->toBe(
        $drafts->only(['pending', 'rejected', 'failed'])->pluck('product_id')->sort()->values()->all()
    )
        ->and($pushed[$drafts['pending']->product_id]->fields)->toBe(['meta_beschrijving'])
        ->and($pushed[$drafts['failed']->product_id]->fields)->toBe(['beschrijving_k'])
        ->and($pushed[$drafts['pending']->product_id]->runId)->toBe($run->id);
});

it('only rewrites the drafts of the selected status', function () {
    Queue::fake();

    foreach ([AiDescriptionDraft::STATUS_PENDING, AiDescriptionDraft::STATUS_FAILED] as $status) {
        AiDescriptionDraft::create([
            'product_id' => makeAiProduct(['productnaam' => $status])->id,
            'status'     => $status,
        ]);
    }

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.tools.ai-descriptions.regenerate-all'), ['status' => 'failed'])
        ->assertRedirect();

    Queue::assertPushed(GenerateProductDescriptionJob::class, 1);
});

it('refuses to rewrite the approved view', function () {
    Queue::fake();

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.tools.ai-descriptions.regenerate-all'), ['status' => 'approved'])
        ->assertSessionHasErrors('status');

    Queue::assertNothingPushed();
});

it('offers an approve-and-publish-all button on the review page', function () {
    AiDescriptionDraft::create([
        'product_id' => makeAiProduct(['productnaam' => 'Diamante 01'])->id,
        'status'     => AiDescriptionDraft::STATUS_PENDING,
        'fields'     => ['beschrijving_l' => '<p>Nieuwe tekst.</p>'],
        'problems'   => [['message' => 'Te lang.']],
    ]);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.tools.ai-descriptions.review'))
        ->assertOk()
        ->assertSee('Alles goedkeuren en doorsturen (1)')
        ->assertSee('1 daarvan hebben nog opmerkingen');
});

it('approves every pending draft of the run and publishes it with the approved ones', function () {
    Queue::fake();

    $run = AiDescriptionRun::create(['filters' => [], 'fields' => ['beschrijving_l'], 'status' => 'completed']);
    $otherRun = AiDescriptionRun::create(['filters' => [], 'fields' => ['beschrijving_l'], 'status' => 'completed']);

    $drafts = collect([
        'pending'  => [AiDescriptionDraft::STATUS_PENDING, $run->id, ['beschrijving_l' => 'x']],
        'approved' => [AiDescriptionDraft::STATUS_APPROVED, $run->id, ['beschrijving_l' => 'x']],
        'rejected' => [AiDescriptionDraft::STATUS_REJECTED, $run->id, ['beschrijving_l' => 'x']],
        'failed'   => [AiDescriptionDraft::STATUS_FAILED, $run->id, null],
        'applied'  => [AiDescriptionDraft::STATUS_APPLIED, $run->id, ['beschrijving_l' => 'x']],
        'other'    => [AiDescriptionDraft::STATUS_PENDING, $otherRun->id, ['beschrijving_l' => 'x']],
    ])->map(fn (array $draft, string $name) => AiDescriptionDraft::create([
        'product_id' => makeAiProduct(['productnaam' => $name])->id,
        'status'     => $draft[0],
        'run_id'     => $draft[1],
        'fields'     => $draft[2],
    ]));

    $admin = Webkul\User\Models\Admin::query()->firstOrFail();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.tools.ai-descriptions.approve-and-apply-all'), ['run' => $run->id])
        ->assertRedirect()
        ->assertSessionHas('success', '2 teksten zijn goedgekeurd en worden gepubliceerd en naar de webshop gestuurd.');

    $pending = $drafts['pending']->fresh();

    expect($pending->status)->toBe(AiDescriptionDraft::STATUS_APPROVED)
        ->and($pending->reviewed_by)->toBe($admin->id)
        ->and($pending->reviewed_at)->not->toBeNull()
        ->and($drafts['rejected']->fresh()->status)->toBe(AiDescriptionDraft::STATUS_REJECTED)
        ->and($drafts['other']->fresh()->status)->toBe(AiDescriptionDraft::STATUS_PENDING);

    Queue::assertPushed(ApplyAiDescriptionsJob::class, fn (ApplyAiDescriptionsJob $job): bool => $job->syncWoo
        && collect($job->draftIds)->sort()->values()->all() === collect([$drafts['pending']->id, $drafts['approved']->id])->sort()->values()->all());
});

it('warns instead of publishing when nothing is ready', function () {
    Queue::fake();

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.tools.ai-descriptions.approve-and-apply-all'))
        ->assertRedirect()
        ->assertSessionHas('warning');

    Queue::assertNothingPushed();
});

it('offers a discard-all button on the review page', function () {
    AiDescriptionDraft::create([
        'product_id' => makeAiProduct(['productnaam' => 'Diamante 01'])->id,
        'status'     => AiDescriptionDraft::STATUS_PENDING,
        'fields'     => ['beschrijving_l' => '<p>Nieuwe tekst.</p>'],
    ]);

    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->get(route('admin.tools.ai-descriptions.review'))
        ->assertOk()
        ->assertSee('Alle concepten weggooien (1)');
});

it('discards every unpublished draft of the run in the current view', function () {
    $run = AiDescriptionRun::create(['filters' => [], 'fields' => ['beschrijving_l'], 'status' => 'completed']);
    $otherRun = AiDescriptionRun::create(['filters' => [], 'fields' => ['beschrijving_l'], 'status' => 'completed']);

    $drafts = collect([
        'pending'  => [AiDescriptionDraft::STATUS_PENDING, $run->id],
        'approved' => [AiDescriptionDraft::STATUS_APPROVED, $run->id],
        'rejected' => [AiDescriptionDraft::STATUS_REJECTED, $run->id],
        'failed'   => [AiDescriptionDraft::STATUS_FAILED, $run->id],
        'applied'  => [AiDescriptionDraft::STATUS_APPLIED, $run->id],
        'other'    => [AiDescriptionDraft::STATUS_PENDING, $otherRun->id],
    ])->map(fn (array $draft, string $name) => AiDescriptionDraft::create([
        'product_id' => makeAiProduct(['productnaam' => $name])->id,
        'status'     => $draft[0],
        'run_id'     => $draft[1],
        'fields'     => ['beschrijving_l' => 'x'],
    ]));

    $admin = Webkul\User\Models\Admin::query()->firstOrFail();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.tools.ai-descriptions.discard-all'), ['run' => $run->id, 'status' => 'pending'])
        ->assertRedirect()
        ->assertSessionHas('success', '1 concepten zijn weggegooid.');

    expect($drafts['pending']->fresh())->toBeNull()
        ->and($drafts['approved']->fresh())->not->toBeNull()
        ->and($drafts['other']->fresh())->not->toBeNull();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.tools.ai-descriptions.discard-all'), ['run' => $run->id, 'status' => 'all'])
        ->assertRedirect()
        ->assertSessionHas('success', '3 concepten zijn weggegooid.');

    expect($drafts['approved']->fresh())->toBeNull()
        ->and($drafts['rejected']->fresh())->toBeNull()
        ->and($drafts['failed']->fresh())->toBeNull()
        ->and($drafts['applied']->fresh())->not->toBeNull()
        ->and($drafts['other']->fresh())->not->toBeNull();
});

it('warns instead of discarding when the view holds no drafts', function () {
    $this->actingAs(Webkul\User\Models\Admin::query()->firstOrFail(), 'admin')
        ->post(route('admin.tools.ai-descriptions.discard-all'), ['status' => 'pending'])
        ->assertRedirect()
        ->assertSessionHas('warning');
});
