<?php

use App\Jobs\ApplyDeMunkStockJob;
use App\Jobs\FetchDeMunkCollectionStockJob;
use App\Jobs\ImportVoorraadDeMunkJob;
use App\Services\DeMunkMatcher;
use App\Services\DeMunkStockWriter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Testing\Fakes\PendingBatchFake;

beforeEach(function () {
    config()->set('demunk.username', 'test');
    config()->set('demunk.password', 'secret');
    config()->set('demunk.base_url', 'https://portal.demunk.test');
    config()->set('demunk.verify_ssl', false);
    config()->set('demunk.timeout', 5);
});

function fakeDeMunkPortalLogin(array $collections): void
{
    Http::fake([
        'portal.demunk.test/Auth/Login.aspx*'                           => Http::response('<input type="hidden" name="__VIEWSTATE" value="x">'),
        'portal.demunk.test/Secure/'                                    => Http::response('Carpetconfigurator'),
        'portal.demunk.test/Api/ConfiguratorService.aspx/GetCollecties' => Http::response([
            'd' => array_map(fn (string $code) => ['Code' => $code], $collections),
        ]),
        'portal.demunk.test/*' => Http::response(''),
    ]);
}

it('dispatches one fetch job per portal collection as a batch', function () {
    Bus::fake();
    fakeDeMunkPortalLogin(['BASIC', 'MODERN']);

    (new ImportVoorraadDeMunkJob())->handle();

    Bus::assertBatched(function (PendingBatchFake $batch) {
        return $batch->name === 'demunk-voorraad-import'
            && $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($job) => $job instanceof FetchDeMunkCollectionStockJob)
            && $batch->jobs->pluck('collection')->all() === ['BASIC', 'MODERN'];
    });
});

it('skips a new import while the previous batch is still running', function () {
    Bus::fake();

    $pending = Bus::batch([new FetchDeMunkCollectionStockJob('BASIC')])->dispatch();
    Cache::put(ImportVoorraadDeMunkJob::BATCH_CACHE_KEY, $pending->id, 600);

    Http::fake();

    (new ImportVoorraadDeMunkJob())->handle();

    Http::assertNothingSent();
});

it('applies merged parts but never touches collections that were not fetched', function () {
    Cache::put(FetchDeMunkCollectionStockJob::partCacheKey('BASIC'), [
        ['ArtikelCodeLang' => 'A-1', '_collectie' => 'BASIC', '_kwaliteit' => 'K'],
    ], 600);
    Cache::forget(FetchDeMunkCollectionStockJob::partCacheKey('MODERN'));

    $matcher = Mockery::mock(DeMunkMatcher::class);
    $matcher->shouldReceive('sync')
        ->once()
        ->andReturn(['linked' => 1, 'skipped_locked' => 0, 'unmatched' => []]);

    $writer = Mockery::mock(DeMunkStockWriter::class);
    $writer->shouldReceive('apply')
        ->once()
        ->withArgs(function (array $articles, ?array $fetchedCollections) {
            return count($articles) === 1
                && $articles[0]['ArtikelCodeLang'] === 'A-1'
                && $fetchedCollections === ['BASIC'];
        })
        ->andReturn(['products' => 1, 'variants_changed' => 2]);

    (new ApplyDeMunkStockJob(['BASIC', 'MODERN']))->handle($matcher, $writer);

    expect(Cache::get(FetchDeMunkCollectionStockJob::partCacheKey('BASIC')))->toBeNull();
    expect(Cache::get(ImportVoorraadDeMunkJob::SNAPSHOT_CACHE_KEY)['article_count'])->toBe(1);
});

it('fails loudly instead of applying when no collection was fetched at all', function () {
    Cache::forget(FetchDeMunkCollectionStockJob::partCacheKey('BASIC'));

    $matcher = Mockery::mock(DeMunkMatcher::class);
    $writer = Mockery::mock(DeMunkStockWriter::class);
    $writer->shouldNotReceive('apply');

    expect(fn () => (new ApplyDeMunkStockJob(['BASIC']))->handle($matcher, $writer))
        ->toThrow(RuntimeException::class);
});
