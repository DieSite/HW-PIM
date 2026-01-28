<?php

namespace App\Services;

use App\Jobs\SyncProductWithBolComJob;
use App\Models\BolComCredential;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Str;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

class ProductService
{
    public function copyStockValuesOnderkleed(Product $product, bool $withUpdatedEvent = true): void
    {
        if (is_null($product->parent)) {
            return;
        }

        $otherRug = $this->getUnderrugAlternative($product);
        if (is_null($otherRug)) {
            return;
        }

        $stockEurogros = $product->values['common']['voorraad_eurogros'] ?? 0;
        $stockDeMunk = $product->values['common']['voorraad_5_korting_handmatig'] ?? 0;
        $stockHW = $product->values['common']['voorraad_hw_5_korting'] ?? 0;
        $stockSale = $product->values['common']['uitverkoop_15_korting'] ?? 0;

        $otherRugValues = $otherRug->values;
        $otherRugValues['common']['voorraad_eurogros'] = $stockEurogros;
        $otherRugValues['common']['voorraad_5_korting_handmatig'] = $stockDeMunk;
        $otherRugValues['common']['voorraad_hw_5_korting'] = $stockHW;
        $otherRugValues['common']['uitverkoop_15_korting'] = $stockSale;
        $otherRug->values = $otherRugValues;
        $saved = $otherRug->save();
        Log::debug("Saved: $saved");

        if ($withUpdatedEvent) {
            Event::dispatch('catalog.product.update.after', $otherRug);
        }
    }

    public function generateMetaTitle(string $naam, string $merk): string
    {
        return "Vloerkleed $naam van $merk bij Huis & Wonen";
    }

    public function generateMetaDescription(string $naam): string
    {
        return "Bestel je vloerkleed $naam bij Huis & Wonen online of kom langs in ons Experience Center in Gorinchem. Huis & Wonen de vloerkleden specialist.";
    }

    public function calculateMetOnderkleedPrice(Product $product): string
    {
        if ($product->values['common']['onderkleed'] !== 'Met onderkleed') {
            throw new \Exception('Moet zonder onderkleed zijn');
        }

        $withOnderkleed = $this->getUnderrugAlternative($product);

        if (is_null($withOnderkleed)) {
            return '0';
        }

        $price = (float) $withOnderkleed->values['common']['prijs']['EUR'];
        $size = $product->values['common']['maat'] ?? null;
        $size = ! is_null($size) ? trim($size) : null;

        $plusPrice = config('rugs.underrugs_cost')[$size] ?? null;

        if (is_null($plusPrice)) {
            Log::warning('Underrugs cost not found for size', ['size' => $size, 'costs' => config('rugs.underrugs_cost')]);

            return (string) $price;
        }

        return (string) ($price + $plusPrice);
    }

    public function getUnderrugAlternative(Product $product): ?Product
    {
        if (is_null($product->parent)) {
            return null;
        }

        $underrug = $product->values['common']['onderkleed'] ?? null;
        if (is_null($underrug)) {
            return null;
        }

        $otherUnderrug = match ($underrug) {
            'Zonder onderkleed' => 'Met onderkleed',
            default             => 'Zonder onderkleed',
        };
        $size = $product->values['common']['maat'] ?? null;

        // Get other inverse of onderkleed

        return $product->parent->variants()->where('values->common->maat', $size)->where('values->common->onderkleed', $otherUnderrug)->first();
    }

    public function triggerWCSyncForParent(Product $product): void
    {
        $parentJob = new SerializedProcessProductsToWooCommerce($product);

        $childJobs = [];
        foreach ($product->variants as $variant) {
            $childJobs[] = new SerializedProcessProductsToWooCommerce($variant);
        }

        \Bus::chain([
            $parentJob,
            ...$childJobs,
        ])->dispatch();
    }

    public function triggerFullExternalSync(Product $product, ?array $bolCredentials = null): void
    {
        if (is_null($bolCredentials)) {
            $bolCredentials = BolComCredential::all();
        }

        if (is_null($product->parent)) {
            $this->triggerWCSyncForParent($product);
        } else {
            $this->triggerWCSyncForChild($product);
        }

        $this->triggerBolSync($product, $bolCredentials, [], true);
    }

    public function triggerWCSyncForChild(Product $product): void
    {
        $this->triggerWCSyncForParent($product->parent);
    }

    public function processBolSync(
        Product $product,
        bool $sync,
        ?array $bolComCredentials,
        ?string $deliveryCode,
        ?float $bolPriceOverride,
        $previousSyncState
    ): void {
        $ean = $product->values['common']['ean'] ?? null;
        $product->bol_com_sync = $sync;
        $clearedAdditional = $product->additional ?? [];

        unset($clearedAdditional['product_sku_already_exists']);
        unset($clearedAdditional['product_sync_error']);

        $maat = $product->values['common']['maat'] ?? null;

        if (is_null($maat) && $product->bol_com_sync) {
            $clearedAdditional['product_sync_error'] = 'Je moet een maat invullen om met Bol.com te kunnen synchroniseren.';
            $product->bol_com_sync = false;
        } elseif (Str::contains($maat, ['Maatwerk', 'Afwijkende afmetingen']) && $product->bol_com_sync) {
            $clearedAdditional['product_sync_error'] = 'We kunnen op dit moment geen maatwerk kleden op Bol.com plaatsen';
            $product->bol_com_sync = false;
        }

        $selectedCredentialIds = $sync
            ? $bolComCredentials
            : [];

        $credentialsToDelete = $product->bolComCredentials()
            ->when(! $sync, function ($query) {
                return $query->whereNotNull('reference');
            }, function ($query) use ($selectedCredentialIds) {
                return $query->whereNotIn('bol_com_credentials.id', $selectedCredentialIds)
                    ->whereNotNull('reference');
            })
            ->get();

        $deliveryCode = $sync ? $deliveryCode : null;
        $product->bol_price_override = $bolPriceOverride ?: null;

        Log::debug('BOL.com price override', ['bol_price_override' => $product->bol_price_override]);

        $selectedCredentials = BolComCredential::whereIn('id', $selectedCredentialIds)->get();

        if ($sync && ! is_null($bolComCredentials)) {
            $syncData = [];
            foreach ($selectedCredentialIds as $credentialId) {
                $syncData[$credentialId] = [
                    'delivery_code' => $deliveryCode,
                ];
            }
            $product->bolComCredentials()->sync($syncData);
        }

        if (count($clearedAdditional) === 0) {
            $clearedAdditional = null;
        }
        $product->additional = $clearedAdditional;

        $product->saveQuietly();

        $this->triggerBolSync($product, $selectedCredentials, $credentialsToDelete, $previousSyncState);
    }

    public function triggerBolSync(Product $product, array $selectedCredentials, array $credentialsToDelete, bool $previousSyncState)
    {
        $ean = $product->values['common']['ean'] ?? null;
        if ($ean !== null && $product->bol_com_sync) {
            foreach ($selectedCredentials as $credential) {
                SyncProductWithBolComJob::dispatch($product, $credential, $previousSyncState);
            }
        }

        if ($ean !== null) {
            foreach ($credentialsToDelete as $credential) {
                SyncProductWithBolComJob::dispatch($product, $credential, $previousSyncState, null, true);
            }
        }
    }
}
