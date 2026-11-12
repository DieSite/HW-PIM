<?php

use App\Models\WooCommerceSyncQueueEntry;
use App\Services\WooCommerce\WooCommerceSyncQueue;
use App\Services\WooCommerce\WooCommerceSyncQueueJobRepository;
use App\Services\WooCommerce\WooCommerceSyncQueueWorkloadRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Repositories\RedisJobRepository;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Listeners\ProcessProductsToWooCommerce;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * @return array{0: int, 1: array<int, int>, 2: int|null} Product id, next product ids, queue entry id
 */
function syncJobState(SerializedProcessProductsToWooCommerce $job): array
{
    return (fn (): array => [$this->product->id, $this->nextProductIds, $this->syncQueueEntryId])->call($job);
}

beforeEach(function () {
    Queue::fake();
    config()->set('woocommerce_sync.queue.max_in_flight', 2);
    config()->set('woocommerce_sync.queue.stale_after_minutes', 45);
});

it('puts only max_in_flight syncs on the queue and keeps the rest waiting in the table', function () {
    $products = Product::factory()->simple()->count(5)->create();

    $products->each(fn (Product $product) => app(WooCommerceSyncQueue::class)->enqueue($product));

    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class, 2);
    expect(WooCommerceSyncQueueEntry::query()->claimed()->pluck('product_id')->all())->toBe($products->take(2)->pluck('id')->all())
        ->and(app(WooCommerceSyncQueue::class)->waitingCount())->toBe(3);
});

it('merges a product queued again while it waits into its existing entry', function () {
    Product::factory()->simple()->count(2)->create()->each(fn (Product $p) => app(WooCommerceSyncQueue::class)->enqueue($p));
    $parent = Product::factory()->configurable()->create();

    app(WooCommerceSyncQueue::class)->enqueue($parent, [11, 12]);
    app(WooCommerceSyncQueue::class)->enqueue($parent, [12, 13]);

    $entries = WooCommerceSyncQueueEntry::query()->where('product_id', $parent->id)->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->next_product_ids)->toBe([11, 12, 13])
        ->and($entries->first()->claimed_at)->toBeNull();
    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class, 2);
});

it('frees the slot of a finished sync for the next waiting product', function () {
    [$first, $second, $third] = Product::factory()->simple()->count(3)->create()->all();
    $queue = app(WooCommerceSyncQueue::class);
    collect([$first, $second, $third])->each(fn (Product $product) => $queue->enqueue($product));
    $firstEntry = WooCommerceSyncQueueEntry::query()->where('product_id', $first->id)->sole();

    $queue->finished($firstEntry->id);

    expect($firstEntry->fresh())->toBeNull()
        ->and(WooCommerceSyncQueueEntry::query()->where('product_id', $third->id)->value('claimed_at'))->not->toBeNull();
    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class, fn (SerializedProcessProductsToWooCommerce $job): bool => syncJobState($job)[0] === $third->id);
});

it('runs a sync once more when its product changes while it is under way', function () {
    $product = Product::factory()->simple()->create();
    $queue = app(WooCommerceSyncQueue::class);

    $queue->enqueue($product);
    $entry = WooCommerceSyncQueueEntry::query()->sole();
    $queue->enqueue($product);

    expect($entry->fresh()->rerun_requested)->toBeTrue();

    $queue->finished($entry->id);

    expect($entry->fresh())->not->toBeNull()
        ->and($entry->fresh()->rerun_requested)->toBeFalse()
        ->and($entry->fresh()->claimed_at)->not->toBeNull();
    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class, 2);
});

it('requeues a claimed sync whose job was lost', function () {
    $lost = WooCommerceSyncQueueEntry::factory()->claimed(now()->subHour())->create();
    $alive = WooCommerceSyncQueueEntry::factory()->claimed(now()->subMinutes(5))->create();

    $this->artisan('wc:pump-sync-queue')->assertSuccessful();

    expect($lost->fresh()->claimed_at->isAfter(now()->subMinute()))->toBeTrue()
        ->and($alive->fresh()->claimed_at->isBefore(now()->subMinutes(4)))->toBeTrue();
    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class, fn (SerializedProcessProductsToWooCommerce $job): bool => syncJobState($job)[2] === $lost->id);
});

it('drops the entry of a deleted product', function () {
    $entry = WooCommerceSyncQueueEntry::factory()->create();

    Product::query()->whereKey($entry->product_id)->delete();

    expect($entry->fresh())->toBeNull();
});

it('carries the entry through the variant chain and frees it after the last variant', function () {
    Bus::fake([ProcessProductsToWooCommerce::class]);
    $parent = Product::factory()->configurable()->create();
    $variant = Product::factory()->simple()->create(['parent_id' => $parent->id]);
    $entry = WooCommerceSyncQueueEntry::factory()->claimed(now()->subMinutes(10))->create(['product_id' => $parent->id, 'next_product_ids' => [$variant->id]]);

    (new SerializedProcessProductsToWooCommerce($parent, [$variant->id], $entry->id))->handle(app(WooCommerceSyncQueue::class));

    expect($entry->fresh()->claimed_at->isAfter(now()->subMinute()))->toBeTrue();
    Queue::assertPushed(SerializedProcessProductsToWooCommerce::class, fn (SerializedProcessProductsToWooCommerce $job): bool => syncJobState($job) === [$variant->id, [], $entry->id]);

    (new SerializedProcessProductsToWooCommerce($variant, [], $entry->id))->handle(app(WooCommerceSyncQueue::class));

    expect($entry->fresh())->toBeNull();
});

it('frees the entry of a sync that failed for good', function () {
    $entry = WooCommerceSyncQueueEntry::factory()->claimed()->create();

    (new SerializedProcessProductsToWooCommerce(Product::query()->findOrFail($entry->product_id), [], $entry->id))->failed(new RuntimeException('shop down'));

    expect($entry->fresh())->toBeNull();
});

it('shows waiting products as pending jobs in Horizon', function () {
    $product = Product::factory()->simple()->create(['sku' => 'WACHT-001']);
    $entry = WooCommerceSyncQueueEntry::factory()->create(['product_id' => $product->id, 'next_product_ids' => [1, 2]]);
    $jobs = app(JobRepository::class);

    expect($jobs)->toBeInstanceOf(WooCommerceSyncQueueJobRepository::class);

    $redisPending = (new ReflectionMethod(RedisJobRepository::class, 'countPending'))->invoke($jobs);
    expect($jobs->countPending())->toBe($redisPending + 1);

    $listed = $jobs->getPending($redisPending - 1)->first();
    $payload = json_decode($listed->payload, true);
    expect($listed->id)->toBe(WooCommerceSyncQueueJobRepository::ID_PREFIX.$entry->id)
        ->and($listed->queue)->toBe(WooCommerceSyncQueueJobRepository::QUEUE)
        ->and($listed->index)->toBe($redisPending)
        ->and($payload['tags'])->toBe(['WACHT-001', 'product:'.$product->id, '+2 varianten'])
        ->and(unserialize($payload['data']['command'])['next_product_ids'])->toBe([1, 2]);

    $detail = $this->withoutMiddleware()->getJson('/horizon/api/jobs/'.$listed->id);
    $detail->assertOk()->assertJsonPath('id', $listed->id)->assertJsonPath('payload.tags.0', 'WACHT-001');
});

it('adds the waiting room to Horizon\'s workload', function () {
    WooCommerceSyncQueueEntry::factory()->count(3)->create();
    config()->set('woocommerce_sync.rate_limit.per_minute', 20);

    $row = collect(app(WorkloadRepository::class)->get())->firstWhere('name', WooCommerceSyncQueueJobRepository::QUEUE);

    expect(app(WorkloadRepository::class))->toBeInstanceOf(WooCommerceSyncQueueWorkloadRepository::class)
        ->and($row['length'])->toBe(3)
        ->and($row['wait'])->toBe(9);
});

it('still runs a sync job that was queued before the waiting room existed', function () {
    Bus::fake([ProcessProductsToWooCommerce::class]);
    $product = Product::factory()->simple()->create();

    $class = SerializedProcessProductsToWooCommerce::class;
    $legacy = sprintf(
        'O:%d:"%s":2:{s:%d:"%s";O:45:"Illuminate\Contracts\Database\ModelIdentifier":5:{s:5:"class";s:29:"Webkul\Product\Models\Product";s:2:"id";i:%d;s:9:"relations";a:0:{}s:10:"connection";s:5:"mysql";s:15:"collectionClass";N;}s:%d:"%s";a:0:{}}',
        strlen($class), $class,
        strlen("\0{$class}\0product"), "\0{$class}\0product",
        $product->id,
        strlen("\0{$class}\0nextProductIds"), "\0{$class}\0nextProductIds",
    );

    $job = unserialize($legacy);
    $job->handle(app(WooCommerceSyncQueue::class));
    $job->failed(new RuntimeException('shop down'));

    Bus::assertDispatchedSync(ProcessProductsToWooCommerce::class);
});
