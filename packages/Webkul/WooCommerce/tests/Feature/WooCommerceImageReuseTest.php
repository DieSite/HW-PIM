<?php

use App\Enums\WooCommerceAssumption;
use App\Enums\WooCommerceSyncEventStatus;
use App\Exceptions\WoocommerceTimeoutException;
use App\Models\WooCommerceSyncEvent;
use App\Services\WooCommerce\WooCommerceAssumptionMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\DTO\ProductBatch;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;
use Webkul\WooCommerce\Listeners\ProcessProductsToWooCommerce;
use Webkul\WooCommerce\Models\DataTransferMapping;
use Webkul\WooCommerce\Repositories\DataTransferMappingRepository;
use Webkul\WooCommerce\Services\WooCommerceService;

/**
 * An in-memory stand-in for the WooCommerce REST API, so the sync can be run
 * end to end without a shop. It behaves like WooCommerce where it matters
 * here: an image sent as {src} is downloaded into a new attachment, one sent
 * as {id} must exist, the answer lists the images in the order they were
 * sent, a download is stored under a sanitised file name with "-1", "-2"…
 * once that name is taken, and attachment titles come back through
 * wptexturize. Each way the shop could break an assumption of the sync can
 * be switched on.
 */
class SimulatedWooCommerceShop
{
    /** @var array<int, array<string, mixed>> */
    public array $products = [];

    /** @var array<int, string> Attachment id => URL it was downloaded from */
    public array $attachments = [];

    /** @var array<int, string> Attachment id => URL of the file WordPress stored */
    public array $storedFiles = [];

    /** @var array<int, string> Every URL WordPress had to download */
    public array $downloads = [];

    /** @var array<int, array{action: string, payload: mixed, params: array}> */
    public array $requests = [];

    /** @var array<int, string> URLs WordPress stores but leaves out of its answer */
    public array $unlistedUrls = [];

    /** @var array<int, string> URLs whose attachment comes back without an id */
    public array $idlessUrls = [];

    public bool $timesOut = false;

    public ?string $rejectWith = null;

    public bool $reversesImages = false;

    public ?string $savesSkuAs = null;

    public ?string $rejectsUnknownImageWith = null;

    public ?WooCommerceAssumptionMonitor $monitor = null;

    private int $nextId = 5000;

    public function handle(string $action, mixed $payload, array $params): array
    {
        $this->requests[] = ['action' => $action, 'payload' => $payload, 'params' => $params];

        if ($this->timesOut) {
            return ['code' => 0, 'error' => 'Operation timed out after 60000 milliseconds with 0 bytes received'];
        }

        if ($this->rejectWith !== null && in_array($action, ['addProduct', 'updateProduct'], true)) {
            return ['code' => 400, 'message' => $this->rejectWith, 'data' => ['status' => 400]];
        }

        return match ($action) {
            'getProductWithSku' => $this->findBySku($params['sku']),
            'getProduct'        => isset($this->products[(int) $params['id']])
                ? $this->products[(int) $params['id']] + ['code' => 200]
                : ['code' => 404, 'message' => 'Ongeldige ID.'],
            'addProduct'    => $this->saveProduct($payload, null, 201),
            'updateProduct' => $this->saveProduct($payload, (int) $params['id'], 200),
            'getVariation'  => ['code' => 200],
            'addVariation'  => ['id' => $this->nextId++, 'sku' => $this->savesSkuAs ?? $payload['sku'], 'image' => null, 'code' => 201],
            default         => throw new RuntimeException("The simulated shop does not handle [{$action}]."),
        };
    }

    public function createProduct(string $sku): int
    {
        $id = $this->nextId++;
        $this->products[$id] = ['id' => $id, 'sku' => $sku, 'parent_id' => 0, 'status' => 'publish', 'images' => []];

        return $id;
    }

    public function deleteAttachment(int $attachmentId): void
    {
        unset($this->attachments[$attachmentId], $this->storedFiles[$attachmentId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function writes(): array
    {
        return array_values(array_filter($this->requests, fn (array $request) => in_array($request['action'], ['addProduct', 'updateProduct'], true)));
    }

    private function findBySku(string $sku): array
    {
        $product = collect($this->products)->firstWhere('sku', $sku);

        return $product ? [$product, 'code' => 200] : ['code' => 200];
    }

    private function saveProduct(array $payload, ?int $id, int $code): array
    {
        if ($id !== null && ! isset($this->products[$id])) {
            return ['code' => 404, 'message' => 'Ongeldige ID.'];
        }

        $images = [];

        foreach ($payload['images'] ?? [] as $image) {
            if (! empty($image['id'])) {
                if (! isset($this->attachments[(int) $image['id']])) {
                    $message = $this->rejectsUnknownImageWith ?? "#{$image['id']} is een ongeldige afbeelding-ID.";

                    return ['code' => 400, 'message' => $message, 'data' => ['status' => 400]];
                }

                $attachmentId = (int) $image['id'];
            } else {
                $attachmentId = $this->nextId++;
                $this->attachments[$attachmentId] = $image['src'];
                $this->storedFiles[$attachmentId] = $this->storeFile($image['src']);
                $this->downloads[] = $image['src'];
            }

            $url = $this->attachments[$attachmentId];

            if (in_array($url, $this->unlistedUrls, true)) {
                continue;
            }

            $images[] = [
                'id'   => in_array($url, $this->idlessUrls, true) ? 0 : $attachmentId,
                'src'  => $this->storedFiles[$attachmentId],
                'name' => preg_replace('/(\d)x(\d)/', '$1×$2', pathinfo($url, PATHINFO_FILENAME)),
            ];
        }

        if ($this->reversesImages) {
            $images = array_reverse($images);
        }

        $id ??= $this->nextId++;

        $this->products[$id] = [
            'id'        => $id,
            'sku'       => $this->savesSkuAs ?? $payload['sku'],
            'parent_id' => 0,
            'status'    => 'publish',
            'images'    => array_key_exists('images', $payload) ? $images : ($this->products[$id]['images'] ?? []),
        ];

        return $this->products[$id] + ['code' => $code];
    }

    /**
     * Where WordPress puts a download: the file name through
     * sanitize_file_name() (special characters such as "%" dropped, spaces
     * turned into dashes, accents kept), with "-1", "-2"… appended while that
     * name is taken in the uploads folder.
     */
    private function storeFile(string $sourceUrl): string
    {
        $extension = pathinfo($sourceUrl, PATHINFO_EXTENSION);
        $specialCharacters = ['?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+'];
        $name = str_replace($specialCharacters, '', pathinfo(rawurldecode($sourceUrl), PATHINFO_FILENAME));
        $name = preg_replace('/[\r\n\t -]+/', '-', $name);
        $candidate = $name;

        for ($suffix = 1; in_array("{$candidate}.{$extension}", array_map('basename', $this->storedFiles), true); $suffix++) {
            $candidate = "{$name}-{$suffix}";
        }

        return "https://shop.test/wp-content/uploads/2026/10/{$candidate}.{$extension}";
    }
}

/**
 * Point the sync at a simulated shop and at the real exporter image handling
 * — the lookup that decides between {src} and {id} — on the real mapping
 * table. The rest of the payload formatting is irrelevant here and left out.
 */
function openSimulatedShop(string $shopUrl = 'https://shop.test'): SimulatedWooCommerceShop
{
    Cache::put('wc_default_credential', [
        'id'      => 1,
        'shopUrl' => $shopUrl,
        'extras'  => [
            'quicksettings' => [
                'auto_sync'      => 1,
                'quick_channel'  => 'default',
                'quick_locale'   => 'en_US',
                'quick_currency' => 'EUR',
            ],
        ],
    ], 300);

    $exporter = Mockery::mock(Exporter::class)->makePartial();
    (new ReflectionProperty(Exporter::class, 'dataTransferMappingRepository'))->setValue($exporter, app(DataTransferMappingRepository::class));
    $exporter->shouldReceive('initMappingsAndAttribute');
    $exporter->shouldReceive('formatData')->andReturnUsing(function (ProductBatch $batch) use ($exporter) {
        $item = $batch->toArray();
        $formatted = ['sku' => $item['code'], 'type' => $item['parent_id'] ? 'variation' : 'variable'];
        $images = $item['values']['common']['afbeelding'] ?? '';

        if ($images !== '') {
            $formatted['images'] = (fn () => $this->formatImageData('afbeelding', $images, ['afbeelding'], $item['code']))->call($exporter);
        }

        if ($item['parent_id']) {
            $formatted['parent_id'] = $item['parent_id'];
        }

        return $formatted;
    });
    app()->instance(Exporter::class, $exporter);

    $shop = new SimulatedWooCommerceShop();
    $shop->monitor = test()->spy(WooCommerceAssumptionMonitor::class);
    $connector = Mockery::mock(WooCommerceService::class);
    $connector->shouldReceive('requestApiAction')->andReturnUsing(fn (...$arguments) => $shop->handle(...$arguments));
    app()->instance(WooCommerceService::class, $connector);

    return $shop;
}

/**
 * @param  array<int, string>  $images  Storage paths, the way the afbeelding attribute holds them
 */
function rugWithImages(string $sku, array $images, ?Product $parent = null): Product
{
    return Product::factory()->{$parent ? 'simple' : 'configurable'}()->create([
        'sku'       => $sku,
        'parent_id' => $parent?->id,
        'values'    => ['common' => ['afbeelding' => implode(',', $images)]],
    ]);
}

function syncRug(Product $product): void
{
    ProcessProductsToWooCommerce::dispatchSync(ProductBatch::fromProductArray($product->fresh()->toArray()));
}

function imageUrl(string $path): string
{
    return Storage::disk('private')->url($path);
}

/**
 * @return array<int, array<string, mixed>>
 */
function sentImages(SimulatedWooCommerceShop $shop): array
{
    return end($shop->requests)['payload']['images'] ?? [];
}

beforeEach(function () {
    Storage::fake('private');
});

it('uploads the images of a new rug once and sends their attachment ids from then on', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0362', ['products/ERG0362-200x290.jpg', 'products/ERG0362-sfeer.jpg']);

    // Act
    syncRug($rug);
    syncRug($rug);

    // Assert
    $first = $shop->writes()[0];
    $second = $shop->writes()[1];

    expect($first['action'])->toBe('addProduct')
        ->and(array_column($first['payload']['images'], 'src'))->toBe([imageUrl('products/ERG0362-200x290.jpg'), imageUrl('products/ERG0362-sfeer.jpg')])
        ->and($second['action'])->toBe('updateProduct')
        ->and(array_column($second['payload']['images'], 'id'))->toBe(array_map('strval', array_keys($shop->attachments)))
        ->and(array_column($second['payload']['images'], 'src'))->toBeEmpty()
        ->and($shop->downloads)->toHaveCount(2, 'WordPress may only download each image once.');

    $shop->monitor->shouldNotHaveReceived('violated');
});

it('remembers the images uploaded by updating a rug that already exists in the shop', function () {
    // Arrange
    $shop = openSimulatedShop();
    $shop->createProduct('ERG0363');
    $rug = rugWithImages('ERG0363', ['products/ERG0363-1.jpg']);

    // Act
    syncRug($rug);
    syncRug($rug);

    // Assert
    expect(array_column($shop->writes(), 'action'))->toBe(['updateProduct', 'updateProduct'])
        ->and($shop->downloads)->toBe([imageUrl('products/ERG0363-1.jpg')])
        ->and(sentImages($shop))->toBe([['id' => (string) array_key_first($shop->attachments), 'position' => 0]]);
});

it('keeps one mapping per image however often a rug is synced', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0364', ['products/ERG0364-1.jpg', 'products/ERG0364-2.jpg']);

    // Act
    foreach (range(1, 3) as $sync) {
        syncRug($rug);
    }

    // Assert
    expect(DataTransferMapping::query()->where('entityType', 'image')->pluck('externalId', 'code')->all())->toEqual([
        'ERG0364-ERG0364-1.jpg' => array_keys($shop->attachments)[0],
        'ERG0364-ERG0364-2.jpg' => array_keys($shop->attachments)[1],
    ]);
});

it('uploads only the image added to the gallery since the last sync', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0365', ['products/ERG0365-1.jpg']);
    syncRug($rug);

    // Act
    $rug->values = ['common' => ['afbeelding' => 'products/ERG0365-1.jpg,products/ERG0365-detail.jpg']];
    $rug->save();
    syncRug($rug);

    // Assert
    expect($shop->downloads)->toBe([imageUrl('products/ERG0365-1.jpg'), imageUrl('products/ERG0365-detail.jpg')])
        ->and(array_keys(sentImages($shop)[0]))->toBe(['id', 'position'])
        ->and(array_keys(sentImages($shop)[1]))->toBe(['src', 'name', 'position']);
});

it('does not guess which attachment is which when WordPress leaves an image out of its answer', function () {
    // Arrange
    $shop = openSimulatedShop();
    $shop->unlistedUrls = [imageUrl('products/ERG0366-1.jpg')];
    $rug = rugWithImages('ERG0366', ['products/ERG0366-1.jpg', 'products/ERG0366-2.jpg']);

    // Act
    syncRug($rug);
    syncRug($rug);

    // Assert — matching by position would pair ERG0366-1 with the attachment of ERG0366-2
    expect(DataTransferMapping::query()->where('entityType', 'image')->count())->toBe(0)
        ->and(array_column(sentImages($shop), 'src'))->toHaveCount(2, 'Without a mapping the images are sent by URL again, which is slow but never wrong.');

    $shop->monitor->shouldHaveReceived('violated')->with(WooCommerceAssumption::EveryImageListed, Mockery::pattern('/ERG0366 was sent 2 image\(s\), WooCommerce listed 1/'), Mockery::any())->twice();
});

it('skips an image WooCommerce answered without an attachment id and remembers the rest', function () {
    // Arrange
    $shop = openSimulatedShop();
    $shop->idlessUrls = [imageUrl('products/ERG0367-1.jpg')];
    $rug = rugWithImages('ERG0367', ['products/ERG0367-1.jpg', 'products/ERG0367-2.jpg']);

    // Act
    syncRug($rug);

    // Assert
    expect(DataTransferMapping::query()->where('entityType', 'image')->pluck('code')->all())->toBe(['ERG0367-ERG0367-2.jpg']);
});

/**
 * A remembered attachment can be deleted from the WordPress media library.
 * WooCommerce then rejects the whole product, and without recovering from it
 * that rug would fail on every sync from then on.
 */
it('uploads an image again when it was deleted from the WordPress media library', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0368', ['products/ERG0368-1.jpg', 'products/ERG0368-2.jpg']);
    syncRug($rug);
    [$deleted, $kept] = array_keys($shop->attachments);
    $shop->deleteAttachment($deleted);

    // Act
    syncRug($rug);

    // Assert
    $retry = sentImages($shop);
    $rug->refresh();

    expect($retry[0])->toHaveKey('src', imageUrl('products/ERG0368-1.jpg'))
        ->and($retry[1])->toBe(['id' => (string) $kept, 'position' => 0])
        ->and(DataTransferMapping::query()->where('code', 'ERG0368-ERG0368-1.jpg')->value('externalId'))->not->toBe((string) $deleted)
        ->and(DataTransferMapping::query()->where('externalId', $deleted)->exists())->toBeFalse()
        ->and($rug->additional['product_sync_error'] ?? null)->toBeNull()
        ->and(WooCommerceSyncEvent::whereProductId($rug->id)->latest('id')->first()->status)->toBe(WooCommerceSyncEventStatus::Success);
});

it('records the failure when the shop still rejects the rug after uploading its images again', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0369', ['products/ERG0369-1.jpg']);
    syncRug($rug);
    $attachmentId = array_key_first($shop->attachments);
    $shop->rejectWith = "#{$attachmentId} is een ongeldige afbeelding-ID.";

    // Act
    syncRug($rug);

    // Assert
    $rug->refresh();

    expect($rug->additional['product_sync_error'])->toContain("#{$attachmentId}")
        ->and(WooCommerceSyncEvent::whereProductId($rug->id)->latest('id')->first()->status)->toBe(WooCommerceSyncEventStatus::Failed);
});

it('keeps its image mappings when WooCommerce rejects an id it was never sent', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0370', ['products/ERG0370-1.jpg']);
    syncRug($rug);
    $shop->rejectWith = '#999999 is een ongeldige categorie-ID.';

    // Act
    expect(fn () => syncRug($rug))->toThrow(Exception::class, 'Error occurred (400)');

    // Assert
    expect(DataTransferMapping::query()->where('entityType', 'image')->count())->toBe(1);
});

it('only forgets the mappings of the shop that rejected the image', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0371', ['products/ERG0371-1.jpg']);
    syncRug($rug);
    $attachmentId = array_key_first($shop->attachments);
    DataTransferMapping::query()->create(['entityType' => 'image', 'code' => 'ERG0371-ERG0371-1.jpg', 'externalId' => $attachmentId, 'apiUrl' => 'https://other-shop.test']);
    $shop->deleteAttachment($attachmentId);

    // Act
    syncRug($rug);

    // Assert
    expect(DataTransferMapping::query()->where('apiUrl', 'https://other-shop.test')->value('externalId'))->toEqual($attachmentId);
});

/**
 * The variation endpoint only reads `image`, so the `images` a variant is
 * formatted with never reach the shop and nothing is uploaded for it.
 */
it('remembers no images and reports nothing for a variant, whose images WooCommerce ignores', function () {
    // Arrange
    $shop = openSimulatedShop();
    $parent = rugWithImages('ERG0372', []);
    $shop->createProduct('ERG0372');
    $variant = rugWithImages('ERG0372.1', ['products/ERG0372-1.jpg'], $parent);

    // Act
    syncRug($variant);

    // Assert
    expect(end($shop->requests)['action'])->toBe('addVariation')
        ->and(DataTransferMapping::query()->where('entityType', 'image')->count())->toBe(0)
        ->and(WooCommerceSyncEvent::whereProductId($variant->id)->latest('id')->first()->status)->toBe(WooCommerceSyncEventStatus::Success);

    $shop->monitor->shouldNotHaveReceived('violated');
});

/**
 * The job has to throw on a timeout: only a thrown exception makes the queue
 * apply the backoff and try again, instead of treating the sync as done.
 */
it('throws when the shop times out so the queue retries the sync after its backoff', function () {
    // Arrange
    $shop = openSimulatedShop();
    $shop->timesOut = true;
    $rug = rugWithImages('ERG0373', ['products/ERG0373-1.jpg']);

    // Act & Assert
    expect(fn () => syncRug($rug))->toThrow(WoocommerceTimeoutException::class);
    expect($rug->fresh()->additional['product_sync_error'])->toContain('timed out')
        ->and(DataTransferMapping::query()->where('entityType', 'image')->count())->toBe(0);
});

/**
 * A file name WordPress has already stored gets "-1", "-2"… appended. Rugs
 * sharing a photo, or a photo uploaded again after it was deleted, come back
 * under such a name, and that is still the image that was sent.
 */
it('recognises an image WordPress stored under a taken file name with a suffix', function () {
    // Arrange
    $shop = openSimulatedShop();
    syncRug(rugWithImages('ERG0374', ['products/sfeer-woonkamer.jpg']));
    $sharing = rugWithImages('ERG0375', ['products/sfeer-woonkamer.jpg']);

    // Act
    syncRug($sharing);

    // Assert
    expect(end($shop->storedFiles))->toEndWith('/sfeer-woonkamer-1.jpg')
        ->and(DataTransferMapping::query()->where('code', 'ERG0375-sfeer-woonkamer.jpg')->value('externalId'))->toEqual(array_key_last($shop->attachments));

    $shop->monitor->shouldNotHaveReceived('violated');
});

it('recognises an image whose file name WordPress sanitised', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0376', ['products/Café 50% korting (sfeer).jpg']);

    // Act
    syncRug($rug);

    // Assert
    expect(end($shop->storedFiles))->toEndWith('/Café-50-korting-sfeer.jpg')
        ->and(DataTransferMapping::query()->where('entityType', 'image')->count())->toBe(1);

    $shop->monitor->shouldNotHaveReceived('violated');
});

it('remembers nothing and reports it when WooCommerce lists the images in another order', function () {
    // Arrange
    $shop = openSimulatedShop();
    $shop->reversesImages = true;
    $rug = rugWithImages('ERG0377', ['products/ERG0377-1.jpg', 'products/ERG0377-2.jpg']);

    // Act
    syncRug($rug);

    // Assert — the product itself was saved, only the image mapping is withheld
    expect(DataTransferMapping::query()->where('entityType', 'image')->count())->toBe(0)
        ->and(WooCommerceSyncEvent::whereProductId($rug->id)->latest('id')->first()->status)->toBe(WooCommerceSyncEventStatus::Success);

    $shop->monitor->shouldHaveReceived('violated')->with(WooCommerceAssumption::ImagesInSentOrder, Mockery::pattern('/ERG0377: the image\(s\) at position 0, 1 came back different/'), Mockery::any())->once();
});

it('reports a shuffled answer even when every image was sent by attachment id', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0378', ['products/ERG0378-1.jpg', 'products/ERG0378-2.jpg']);
    syncRug($rug);
    $mappings = DataTransferMapping::query()->where('entityType', 'image')->pluck('externalId', 'code')->all();
    $shop->reversesImages = true;

    // Act
    syncRug($rug);

    // Assert
    expect(DataTransferMapping::query()->where('entityType', 'image')->pluck('externalId', 'code')->all())->toBe($mappings);

    $shop->monitor->shouldHaveReceived('violated')->with(WooCommerceAssumption::ImagesInSentOrder, Mockery::any(), Mockery::any())->once();
});

it('reports a product WooCommerce saved under another SKU', function () {
    // Arrange
    $shop = openSimulatedShop();
    $shop->savesSkuAs = 'erg0379';
    $rug = rugWithImages('ERG0379', []);

    // Act
    syncRug($rug);

    // Assert
    $shop->monitor->shouldHaveReceived('violated')->with(WooCommerceAssumption::SkuSavedAsSent, 'Sent ERG0379, WooCommerce saved "erg0379".', Mockery::any())->once();
});

it('reports a variation WooCommerce saved under another SKU', function () {
    // Arrange
    $shop = openSimulatedShop();
    $parent = rugWithImages('ERG0380', []);
    $shop->createProduct('ERG0380');
    $shop->savesSkuAs = 'ERG0380-1';

    // Act
    syncRug(rugWithImages('ERG0380.1', [], $parent));

    // Assert
    $shop->monitor->shouldHaveReceived('violated')->with(WooCommerceAssumption::SkuSavedAsSent, 'Sent ERG0380.1, WooCommerce saved "ERG0380-1".', Mockery::any())->once();
});

/**
 * Recovering from a deleted attachment hinges on WooCommerce naming its id.
 * When the wording changes the sync still fails visibly, but the automatic
 * recovery silently stops working — that has to be reported.
 */
it('reports an image rejection that does not name the attachment id', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0381', ['products/ERG0381-1.jpg']);
    syncRug($rug);
    $shop->deleteAttachment(array_key_first($shop->attachments));
    $shop->rejectsUnknownImageWith = 'De afbeelding bestaat niet.';

    // Act
    expect(fn () => syncRug($rug))->toThrow(Exception::class, 'Error occurred (400)');

    // Assert
    $shop->monitor->shouldHaveReceived('violated')->with(WooCommerceAssumption::ImageRejectionNamesId, Mockery::pattern('/ERG0381 was rejected over an image without naming/'), Mockery::any())->once();
});

it('does not report a rejection that has nothing to do with images', function () {
    // Arrange
    $shop = openSimulatedShop();
    $rug = rugWithImages('ERG0382', ['products/ERG0382-1.jpg']);
    syncRug($rug);
    $shop->rejectWith = 'Ongeldige parameter(s): regular_price';

    // Act
    expect(fn () => syncRug($rug))->toThrow(Exception::class);

    // Assert
    $shop->monitor->shouldNotHaveReceived('violated');
});
