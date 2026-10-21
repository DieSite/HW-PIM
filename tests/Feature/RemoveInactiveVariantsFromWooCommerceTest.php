<?php

use Illuminate\Support\Facades\Queue;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * A configurable parent with one variant per given status.
 *
 * @param  array<string, int>  $variantStatuses  Variant SKU => status
 */
function parentWithVariants(string $sku, array $variantStatuses): Product
{
    $parent = Product::factory()->configurable()->create(['sku' => $sku]);

    foreach ($variantStatuses as $variantSku => $status) {
        Product::factory()->simple()->create(['sku' => $variantSku, 'parent_id' => $parent->id, 'status' => $status]);
    }

    return $parent;
}

/**
 * @return array<int, array<int, string>> Per queued chain, the SKUs it syncs in order
 */
function queuedVariantChains(): array
{
    return Queue::pushed(SerializedProcessProductsToWooCommerce::class)->map(function (SerializedProcessProductsToWooCommerce $job): array {
        $first = (new ReflectionProperty($job, 'product'))->getValue($job);
        $next = (new ReflectionProperty($job, 'nextProductIds'))->getValue($job);

        return [$first->sku, ...Product::query()->whereIn('id', $next)->orderBy('id')->pluck('sku')->all()];
    })->values()->all();
}

beforeEach(function () {
    Queue::fake();
});

it('queues each parent followed by only its disabled variants', function () {
    // Arrange
    parentWithVariants('ERG0600', ['ERG0600.1' => 0, 'ERG0600.2' => 1, 'ERG0600.3' => 0]);
    parentWithVariants('ERG0601', ['ERG0601.1' => 0]);
    parentWithVariants('ERG0602', ['ERG0602.1' => 1]);

    // Act
    $this->artisan('wc:remove-inactive-variants')
        ->expectsOutputToContain('Queued 3 disabled variant(s) of 2 parent(s) for removal from WooCommerce')
        ->assertSuccessful();

    // Assert
    expect(queuedVariantChains())->toEqualCanonicalizing([
        ['ERG0600', 'ERG0600.1', 'ERG0600.3'],
        ['ERG0601', 'ERG0601.1'],
    ]);
});

it('narrows to the given parent and variant SKUs', function () {
    // Arrange
    parentWithVariants('ERG0600', ['ERG0600.1' => 0, 'ERG0600.2' => 0]);
    parentWithVariants('ERG0601', ['ERG0601.1' => 0, 'ERG0601.2' => 0]);
    parentWithVariants('ERG0602', ['ERG0602.1' => 0]);

    // Act
    $this->artisan('wc:remove-inactive-variants', ['--sku' => ['ERG0600', 'ERG0601.2']])->assertSuccessful();

    // Assert
    expect(queuedVariantChains())->toEqualCanonicalizing([
        ['ERG0600', 'ERG0600.1', 'ERG0600.2'],
        ['ERG0601', 'ERG0601.2'],
    ]);
});

it('ignores an enabled variant even when its SKU is given', function () {
    // Arrange
    parentWithVariants('ERG0600', ['ERG0600.1' => 1]);

    // Act
    $this->artisan('wc:remove-inactive-variants', ['--sku' => ['ERG0600.1']])
        ->expectsOutputToContain('No disabled variants found.')
        ->assertSuccessful();

    // Assert
    Queue::assertNothingPushed();
});

it('lists the variants without queueing anything on a dry run', function () {
    // Arrange
    parentWithVariants('ERG0600', ['ERG0600.1' => 0, 'ERG0600.2' => 0]);

    // Act
    $this->artisan('wc:remove-inactive-variants', ['--dry-run' => true])
        ->expectsTable(['Parent', 'Disabled variants'], [['ERG0600', 'ERG0600.1, ERG0600.2']])
        ->expectsOutputToContain('Dry run: 2 disabled variant(s) of 1 parent(s)')
        ->assertSuccessful();

    // Assert
    Queue::assertNothingPushed();
});

it('processes no more parents than the limit', function () {
    // Arrange
    parentWithVariants('ERG0600', ['ERG0600.1' => 0, 'ERG0600.2' => 0]);
    parentWithVariants('ERG0601', ['ERG0601.1' => 0]);

    // Act
    $this->artisan('wc:remove-inactive-variants', ['--limit' => 1])->assertSuccessful();

    // Assert
    expect(queuedVariantChains())->toEqual([['ERG0600', 'ERG0600.1', 'ERG0600.2']]);
});
