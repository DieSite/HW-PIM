<?php

namespace App\Console\Commands;

use App\Models\BolComCredential;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnableBolSyncForEligibleProducts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bolcom:enable-eligible
        {--credential=* : Credential id(s) to attach (default: all active)}
        {--delivery-code= : Delivery code stored on the pivot (default: none; Bol offers fall back to 1-8d)}
        {--limit=0 : Only process the first N eligible products (0 = all)}
        {--dry-run : Show what would be enabled without writing or dispatching}
        {--force : Skip the confirmation prompt}';

    /**
     * @var string
     */
    protected $description = "Enable Bol.com sync and dispatch it for products with an EAN, Eurogros stock, and onderkleed='Zonder onderkleed'";

    public function handle(ProductService $productService): int
    {
        $credentials = $this->resolveCredentials();
        if ($credentials->isEmpty()) {
            $this->error('No active Bol.com credentials found.');

            return self::FAILURE;
        }
        $credentialIds = $credentials->pluck('id')->all();
        $deliveryCode = $this->option('delivery-code') ?: null;
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = Product::query()->bolSyncEligible()->orderBy('id');
        $total = (clone $query)->count();
        $target = $limit > 0 ? min($limit, $total) : $total;

        $this->info("Eligible products: {$total}".($limit > 0 ? " (processing {$target})" : ''));
        $this->line('  Credentials: '.$credentials->pluck('name')->implode(', ').' | delivery code: '.($deliveryCode ?? '— (Bol fallback 1-8d)'));

        $this->warnAboutDoubleEncoding();

        if ($target === 0) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(['SKU', 'EAN', 'voorraad_eurogros'], $this->sampleRows($query, $target));
            $this->comment('[dry-run] Nothing was enabled or dispatched.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Enable Bol.com sync and dispatch a sync job for {$target} product(s)?")) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $stats = ['enabled' => 0, 'validation_failed' => 0, 'processed' => 0];
        $bar = $this->output->createProgressBar($target);

        $query->chunkById(200, function ($products) use ($productService, $credentialIds, $deliveryCode, $limit, $bar, &$stats): bool {
            foreach ($products as $product) {
                if ($limit > 0 && $stats['processed'] >= $limit) {
                    return false;
                }
                $stats['processed']++;
                $bar->advance();

                $previousSyncState = (bool) $product->bol_com_sync;
                $productService->processBolSync($product, true, $credentialIds, $deliveryCode, null, $previousSyncState);

                if ($product->bol_com_sync) {
                    $stats['enabled']++;
                } else {
                    $stats['validation_failed']++;
                }
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(['Resultaat', 'Aantal'], [
            ['ingeschakeld + sync gedispatcht', $stats['enabled']],
            ['validatie mislukt (zie product_sync_error)', $stats['validation_failed']],
        ]);

        if ($stats['enabled'] > 0) {
            $this->info('Sync jobs queued on the "bolcom" queue — ensure the queue worker (unopim-q) is running.');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, BolComCredential> */
    private function resolveCredentials(): \Illuminate\Support\Collection
    {
        $ids = (array) $this->option('credential');

        return BolComCredential::query()
            ->where('is_active', true)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->get();
    }

    private function warnAboutDoubleEncoding(): void
    {
        $double = DB::table('products')->whereRaw("LEFT(TRIM(`values`), 1) = '\"'")->count();
        if ($double > 0) {
            $this->warn("{$double} products still have double-encoded values and are invisible to this filter. Run `php artisan fix:double-encoded-product-values` first to include them.");
        }
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function sampleRows(\Illuminate\Database\Eloquent\Builder $query, int $target): array
    {
        return (clone $query)->limit(min($target, 20))->get()
            ->map(fn (Product $p) => [
                $p->sku,
                (string) ($p->values['common']['ean'] ?? ''),
                (string) ($p->values['common']['voorraad_eurogros'] ?? ''),
            ])->all();
    }
}
