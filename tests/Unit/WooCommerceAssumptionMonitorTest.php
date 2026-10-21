<?php

use App\Enums\WooCommerceAssumption;
use App\Services\WooCommerce\WooCommerceAssumptionMonitor;
use Illuminate\Support\Facades\Log;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\SentrySdk;

/**
 * Binds a Sentry client that records events instead of sending them.
 *
 * @return ArrayObject<int, Event>
 */
function recordSentryAssumptionEvents(): ArrayObject
{
    $recorded = new ArrayObject();

    $GLOBALS['sentry_previous_client'] = SentrySdk::getCurrentHub()->getClient();

    SentrySdk::getCurrentHub()->bindClient(ClientBuilder::create([
        'dsn'         => 'https://public@sentry.invalid/1',
        'before_send' => function (Event $event) use ($recorded): ?Event {
            $recorded[] = $event;

            return null;
        },
    ])->getClient());

    return $recorded;
}

afterEach(function () {
    $previous = $GLOBALS['sentry_previous_client'] ?? null;

    if ($previous instanceof ClientInterface) {
        SentrySdk::getCurrentHub()->bindClient($previous);
    }
});

it('sends a violated assumption to Sentry as its own issue', function () {
    $events = recordSentryAssumptionEvents();

    app(WooCommerceAssumptionMonitor::class)->violated(WooCommerceAssumption::ImagesInSentOrder, 'ERG0362 came back shuffled.', ['sku' => 'ERG0362']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->getFingerprint())->toBe(['woocommerce-assumption', 'images-in-sent-order'])
        ->and($events[0]->getTags())->toHaveKey('woocommerce.assumption', 'images-in-sent-order')
        ->and($events[0]->getContexts()['woocommerce_assumption'])->toBe(['sku' => 'ERG0362'])
        ->and($events[0]->getMessage())
        ->toContain(WooCommerceAssumption::ImagesInSentOrder->description())
        ->toContain('ERG0362 came back shuffled.')
        ->toContain(WooCommerceAssumption::ImagesInSentOrder->consequence());
});

/**
 * Once the shop changes behaviour every product in a bulk sync violates the
 * same assumption; one Sentry event per product would burn the quota.
 */
it('sends one Sentry event per assumption per alert interval but logs every violation', function () {
    $events = recordSentryAssumptionEvents();
    Log::spy();
    $monitor = app(WooCommerceAssumptionMonitor::class);

    foreach (range(1, 3) as $product) {
        $monitor->violated(WooCommerceAssumption::SkuSavedAsSent, "Product {$product}.");
    }
    $monitor->violated(WooCommerceAssumption::EveryImageListed, 'Another assumption.');

    $this->travel(WooCommerceAssumptionMonitor::ALERT_INTERVAL_SECONDS + 1)->seconds();
    $monitor->violated(WooCommerceAssumption::SkuSavedAsSent, 'After the interval.');

    expect(array_map(fn (Event $event) => $event->getFingerprint()[1], $events->getArrayCopy()))
        ->toBe(['sku-saved-as-sent', 'every-image-listed', 'sku-saved-as-sent']);

    Log::shouldHaveReceived('warning')->times(5);
});

it('describes every assumption and what failing it means', function () {
    foreach (WooCommerceAssumption::cases() as $assumption) {
        expect($assumption->description())->not->toBeEmpty()
            ->and($assumption->consequence())->not->toBeEmpty();
    }
});
