<?php

use App\Enums\BolSyncState;
use App\Enums\WooCommerceSyncEventStatus;
use App\Http\Middleware\TrackAdminActivity;
use App\Jobs\BulkEditProductsJob;
use App\Jobs\ImportVoorraadEurogrosJob;
use App\Jobs\SyncProductWithBolComJob;
use App\Models\AiDescriptionDraft;
use App\Models\BulkEditRun;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\WooCommerceSyncEvent;
use App\Monitor\ActiveAdmins;
use App\Monitor\AlertFailedJob;
use App\Monitor\HorizonQueueStats;
use App\Monitor\PimHighlights;
use Diesite\Monitor\Events\EventLog;
use Diesite\Monitor\Metrics\QueueStats;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\User\Models\Admin;

const MONITOR_TEST_KEY = 'test-monitor-key';

function makeMonitorProduct(array $attributes = []): Product
{
    $product = new Product();
    $product->attribute_family_id = DB::table('attribute_families')->value('id');
    $product->sku = 'MONITOR-'.uniqid();
    $product->type = 'configurable';
    $product->status = 1;
    $product->values = ['common' => []];

    foreach ($attributes as $key => $value) {
        $product->{$key} = $value;
    }

    $product->save();

    return $product;
}

function fakeFailedJobEvent(string $jobClass, string $message = 'Kapot'): JobFailed
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($jobClass);

    return new JobFailed('redis', $job, new RuntimeException($message));
}

function recordedMonitorEvents(): array
{
    return app(EventLog::class)->since();
}

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
    config()->set('diesite-monitor.key', MONITOR_TEST_KEY);
});

it('rejects requests without the right monitor key', function () {
    $this->getJson('/api/diesite-monitor')->assertForbidden();

    $this->getJson('/api/diesite-monitor', ['X-DieSite-Monitor-Key' => 'wrong'])->assertForbidden();
});

it('serves the PIM status payload with the configured queues', function () {
    Queue::fake();

    $this->getJson('/api/diesite-monitor', ['X-DieSite-Monitor-Key' => MONITOR_TEST_KEY])
        ->assertOk()
        ->assertJsonStructure([
            'app' => ['name', 'env', 'laravel', 'php'],
            'queues' => [['queue', 'pending', 'failed', 'rate_per_minute']],
            'visitors_now',
            'highlights' => [['label', 'value', 'format']],
            'deploys',
        ])
        ->assertJsonPath('orders', null)
        ->assertJsonPath('revenue', null)
        ->assertJsonPath('queues.*.queue', ['default', 'bolcom', 'hordeuren', 'demunk', 'long', 'ai']);
});

it('reads pending counts from the queue connection and failures from failed_jobs', function () {
    expect(app(QueueStats::class))->toBeInstanceOf(HorizonQueueStats::class);

    Queue::fake();
    Queue::pushOn('demunk', 'job-a');
    Queue::pushOn('demunk', 'job-b');
    Queue::pushOn('ai', 'job-c');

    foreach ([now()->subHour(), now()->subDays(2)] as $failedAt) {
        DB::table('failed_jobs')->insert([
            'uuid'       => (string) Str::uuid(),
            'connection' => 'redis-demunk',
            'queue'      => 'demunk',
            'payload'    => '{}',
            'exception'  => 'boom',
            'failed_at'  => $failedAt,
        ]);
    }

    $stats = collect(app(QueueStats::class)->collect())->keyBy('queue');

    expect($stats['demunk']['pending'])->toBe(2)
        ->and($stats['demunk']['failed'])->toBe(1)
        ->and($stats['ai']['pending'])->toBe(1)
        ->and($stats['default']['pending'])->toBe(0);
});

it('maps each queue to the connection its Horizon supervisor serves', function () {
    $map = app(HorizonQueueStats::class)->connectionPerQueue();

    expect($map['default'])->toBe('redis')
        ->and($map['demunk'])->toBe('redis-demunk')
        ->and($map['hordeuren'])->toBe('redis-hordeuren')
        ->and($map['long'])->toBe('redis-long')
        ->and($map['ai'])->toBe('redis-ai');
});

it('counts the PIM highlight tiles', function () {
    $before = collect((new PimHighlights())->collect())->pluck('value', 'label');

    $errored = makeMonitorProduct([
        'additional'     => ['product_sync_error' => 'Kapot'],
        'bol_sync_state' => BolSyncState::Failed->value,
    ]);
    makeMonitorProduct(['bol_sync_state' => BolSyncState::Live->value]);
    makeMonitorProduct([
        'bol_sync_state'    => BolSyncState::SubmittingOffer->value,
        'bol_sync_state_at' => now()->subHours(2),
    ]);
    makeMonitorProduct([
        'bol_sync_state'    => BolSyncState::SubmittingOffer->value,
        'bol_sync_state_at' => now(),
    ]);

    WooCommerceSyncEvent::create([
        'product_id' => $errored->id,
        'action'     => 'sync',
        'status'     => WooCommerceSyncEventStatus::Failed,
        'message'    => 'Kapot',
    ]);

    ProductPriceHistory::create([
        'product_id' => $errored->id,
        'sku'        => $errored->sku,
        'old_price'  => 100,
        'new_price'  => 90,
        'reason'     => 'competitor',
        'changed_at' => now(),
    ]);

    AiDescriptionDraft::create(['product_id' => $errored->id, 'status' => AiDescriptionDraft::STATUS_PENDING, 'fields' => []]);
    AiDescriptionDraft::create(['product_id' => $errored->id, 'status' => AiDescriptionDraft::STATUS_APPLIED, 'fields' => []]);

    $after = collect((new PimHighlights())->collect())->pluck('value', 'label');
    $delta = fn (string $label): int => $after[$label] - $before[$label];

    expect($delta('Actieve producten'))->toBe(4)
        ->and($delta('Sync-fouten'))->toBe(1)
        ->and($delta('Live op Bol'))->toBe(1)
        ->and($delta('Bol mislukt'))->toBe(1)
        ->and($delta('Bol vastgelopen'))->toBe(1)
        ->and($delta('WC-fouten vandaag'))->toBe(1)
        ->and($delta('Prijswijzigingen vandaag'))->toBe(1)
        ->and($delta('AI-concepten te beoordelen'))->toBe(1);
});

it('counts admins active in the last five minutes as live visitors', function () {
    $admin = Admin::query()->first();

    $this->actingAs($admin, 'admin');
    (new TrackAdminActivity())->handle(Request::create('/admin'), fn () => response('ok'));

    expect((new ActiveAdmins())())->toBe(1);

    $this->travel(6)->minutes();

    expect((new ActiveAdmins())())->toBe(0);
});

it('pushes a positive event when a bulk edit finishes', function () {
    DB::table('attributes')->insertOrIgnore([
        'code'              => 'merk',
        'type'              => 'text',
        'position'          => 1,
        'is_required'       => 0,
        'is_unique'         => 0,
        'value_per_locale'  => 0,
        'value_per_channel' => 0,
        'enable_wysiwyg'    => 0,
        'usable_in_grid'    => 0,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    $run = BulkEditRun::create([
        'target_attribute' => 'merk',
        'filters'   => ['sku_prefix' => 'NO-MATCH-'],
        'operation' => ['target' => 'merk', 'type' => 'set', 'value' => 'x'],
        'status'    => 'queued',
    ]);

    $job = new BulkEditProductsJob(['sku_prefix' => 'NO-MATCH-'], ['target' => 'merk', 'type' => 'set', 'value' => 'x'], false, $run->id);
    app()->call([$job, 'handle']);

    $this->getJson('/api/diesite-monitor/events', ['X-DieSite-Monitor-Key' => MONITOR_TEST_KEY])
        ->assertOk()
        ->assertJsonPath('events.0.title', 'Bulkbewerking klaar')
        ->assertJsonPath('events.0.sentiment', 'positive');
});

it('alerts a permanently failed job once per throttle window', function () {
    $listener = new AlertFailedJob();

    $listener->handle(fakeFailedJobEvent(SyncProductWithBolComJob::class, 'Eerste'));
    $listener->handle(fakeFailedJobEvent(SyncProductWithBolComJob::class, 'Tweede'));

    $events = recordedMonitorEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]['sentiment'])->toBe('negative')
        ->and($events[0]['title'])->toBe('Job mislukt: SyncProductWithBolComJob')
        ->and($events[0]['message'])->toBe('Eerste');
});

it('leaves failures of self-reporting jobs to the job itself', function () {
    (new AlertFailedJob())->handle(fakeFailedJobEvent(ImportVoorraadEurogrosJob::class));

    expect(recordedMonitorEvents())->toBe([]);

    (new ImportVoorraadEurogrosJob())->failed(new RuntimeException('SFTP onbereikbaar'));

    expect(recordedMonitorEvents())->toHaveCount(1)
        ->and(recordedMonitorEvents()[0]['title'])->toBe('Eurogros voorraad import mislukt');
});
