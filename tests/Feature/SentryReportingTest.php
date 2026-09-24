<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\SentrySdk;

/**
 * Binds a Sentry client that records exception types instead of sending them,
 * so the assertions cover what the application hands to Sentry.
 *
 * @return ArrayObject<int, string>
 */
function recordSentryExceptions(): ArrayObject
{
    $recorded = new ArrayObject();

    $GLOBALS['sentry_previous_client'] = SentrySdk::getCurrentHub()->getClient();

    SentrySdk::getCurrentHub()->bindClient(ClientBuilder::create([
        'dsn'         => 'https://public@sentry.invalid/1',
        'before_send' => function (Event $event) use ($recorded): ?Event {
            $recorded[] = $event->getExceptions()[0]->getType();

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

it('sends report()ed exceptions to Sentry through the handler that is actually bound', function () {
    $recorded = recordSentryExceptions();

    report(new RuntimeException('reported'));

    expect(app(ExceptionHandler::class))->toBeInstanceOf(Webkul\Core\Exceptions\Handler::class)
        ->and($recorded->getArrayCopy())->toBe([RuntimeException::class]);
});

it('sends a failed job to Sentry once although it is both failed and reported', function () {
    $recorded = recordSentryExceptions();

    $exception = new LogicException('job failed');

    $job = new SyncJob(app(), json_encode(['uuid' => 'sentry-once', 'job' => 'SentryOnceProbe', 'data' => []]), 'sync', 'default');

    event(new JobFailed('sync', $job, $exception));
    report($exception);

    expect($recorded->getArrayCopy())->toBe([LogicException::class]);
});
