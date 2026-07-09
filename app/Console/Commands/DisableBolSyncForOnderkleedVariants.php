<?php

namespace App\Console\Commands;

use App\Enums\BolSyncState;
use App\Models\Product;
use Illuminate\Console\Command;

class DisableBolSyncForOnderkleedVariants extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bolcom:disable-met-onderkleed
        {--dry-run : Show what would be disabled without writing}
        {--force : Skip the confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Disable Bol.com sync for variants that are not "Zonder onderkleed" and detach their credentials locally, WITHOUT retiring the shared Bol.com offer the base variant still uses';

    public function handle(): int
    {
        $offenders = $this->findOffenders();

        if ($offenders->isEmpty()) {
            $this->info('No Bol-enabled variants with another onderkleed than "Zonder onderkleed" found.');

            return self::SUCCESS;
        }

        $this->table(
            ['SKU', 'EAN', 'onderkleed', 'gekoppelde credentials'],
            $offenders->map(fn (Product $product): array => [
                $product->sku,
                $this->decodedCommon($product)['ean'] ?? '',
                $this->decodedCommon($product)['onderkleed'] ?? '',
                $product->bolComCredentials->pluck('name')->implode(', ') ?: '—',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('[dry-run] Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Disable Bol.com sync for {$offenders->count()} product(s)? Their credential links are removed locally only; no offer is deleted at Bol.com.")) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        foreach ($offenders as $product) {
            $product->bolComCredentials()->detach();

            $values = $product->values;
            if (is_string($values)) {
                $product->values = json_decode($values, true);
            }

            $product->bol_com_sync = false;
            $product->bol_sync_state = BolSyncState::Idle;
            $product->bol_sync_state_at = now();
            $product->saveQuietly();

            $this->line("Disabled Bol.com sync for {$product->sku}");
        }

        $this->info("{$offenders->count()} product(s) disabled. The shared offers remain live under their \"Zonder onderkleed\" base variants.");

        return self::SUCCESS;
    }

    /**
     * The onderkleed filter must run in PHP: products with a double-encoded
     * `values` column (a JSON string of the object) are invisible to a SQL
     * JSON-path predicate.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function findOffenders(): \Illuminate\Support\Collection
    {
        return Product::query()
            ->where('bol_com_sync', true)
            ->with('bolComCredentials')
            ->get()
            ->filter(function (Product $product): bool {
                $onderkleed = $this->decodedCommon($product)['onderkleed'] ?? null;

                return is_string($onderkleed)
                    && trim($onderkleed) !== ''
                    && $onderkleed !== 'Zonder onderkleed';
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedCommon(Product $product): array
    {
        $values = is_string($product->values) ? json_decode($product->values, true) : $product->values;

        return is_array($values) ? ($values['common'] ?? []) : [];
    }
}
