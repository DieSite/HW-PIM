<?php

namespace App\Services;

use App\Enums\WooCommerceSyncEventStatus;
use App\Jobs\SyncProductWithBolComJob;
use App\Models\BolComCredential;
use App\Services\WooCommerce\WooCommerceSyncEventRecorder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

class ProductService
{
    public function __construct(private WooCommerceSyncEventRecorder $syncEventRecorder) {}

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

    /**
     * De bundelprijs: de prijs van de kale variant plus de onderkleedtoeslag
     * voor die maat.
     */
    public function calculateMetOnderkleedPrice(Product $product): string
    {
        $withoutOnderkleed = $this->assertMetOnderkleed($product);

        if (is_null($withoutOnderkleed)) {
            return '0';
        }

        $price = (float) ($this->commonValues($withoutOnderkleed)['prijs']['EUR'] ?? 0);

        $surcharge = $this->underrugSurcharge($product);

        if (is_null($surcharge)) {
            return (string) $price;
        }

        return (string) ($price + $surcharge);
    }

    /**
     * Dezelfde afleiding voor de adviesverkoopprijs. Die is het plafond waar de
     * dynamische prijsbepaling de bundelprijs tegenaan legt, dus hij moet
     * meelopen met de kale variant; anders zakt de bundel terug naar een
     * verouderd plafond.
     *
     * Null wanneer er niets te berekenen valt (geen tegenvariant, of die heeft
     * zelf geen adviesverkoopprijs) — het veld blijft dan met rust.
     */
    public function calculateMetOnderkleedAdviesPrice(Product $product): ?string
    {
        $withoutOnderkleed = $this->assertMetOnderkleed($product);

        if (is_null($withoutOnderkleed)) {
            return null;
        }

        $advies = $this->commonValues($withoutOnderkleed)['adviesverkoopprijs']['EUR'] ?? null;

        if (is_null($advies) || $advies === '') {
            return null;
        }

        $surcharge = $this->underrugSurcharge($product) ?? 0;

        return (string) ((float) $advies + $surcharge);
    }

    /**
     * De variant van hetzelfde hoofdproduct in dezelfde maat, maar met het
     * andere onderkleed.
     *
     * De vergelijking loopt door PHP en niet meer door een `values->common->…`
     * SQL-match: die miste elke tegenvariant met een andere schrijfwijze van de
     * maat ('Rond 240 cm' naast '240 cm Rond') en elke rij waarvan de
     * `values`-kolom dubbel gecodeerd in de database staat.
     */
    public function getUnderrugAlternative(Product $product): ?Product
    {
        if (is_null($product->parent)) {
            return null;
        }

        $common = $this->commonValues($product);

        $underrug = $common['onderkleed'] ?? null;
        if (is_null($underrug)) {
            return null;
        }

        $otherUnderrug = $this->normaliseLabel($underrug) === 'zonder onderkleed'
            ? 'met onderkleed'
            : 'zonder onderkleed';

        $size = $this->normaliseSizeKey((string) ($common['maat'] ?? ''));

        // Zonder maat valt niet vast te stellen wélke tegenvariant het is; dan
        // liever niets dan de verkeerde prijs koppelen.
        if ($size === '') {
            return null;
        }

        return $product->parent->variants->first(function (Product $variant) use ($product, $otherUnderrug, $size): bool {
            if ($variant->id === $product->id) {
                return false;
            }

            $values = $this->commonValues($variant);

            return $this->normaliseLabel($values['onderkleed'] ?? null) === $otherUnderrug
                && $this->normaliseSizeKey((string) ($values['maat'] ?? '')) === $size;
        });
    }

    /**
     * De `values.common` van een product, ook wanneer de kolom dubbel gecodeerd
     * is opgeslagen — de cast levert dan een JSON-string op waar array-toegang
     * stilzwijgend niets uit haalt.
     *
     * @return array<string, mixed>
     */
    public function commonValues(Product $product): array
    {
        $values = $product->values;

        for ($depth = 0; is_string($values) && $depth < 5; $depth++) {
            $values = json_decode($values, true);
        }

        return is_array($values) && is_array($values['common'] ?? null) ? $values['common'] : [];
    }

    /**
     * Een tekstwaarde uit values.common op vergelijkbare vorm.
     */
    private function normaliseLabel(mixed $label): string
    {
        return is_string($label)
            ? mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $label)))
            : '';
    }

    public function triggerWCSyncForParent(Product $product): void
    {
        if ($product->variants->isEmpty()) {
            $message = "Product '{$product->sku}' has no variants. Add at least one variant before syncing to WooCommerce.";

            $additional = $product->additional ?? [];
            $additional['product_sync_error'] = $message;
            $product->additional = $additional;
            $product->saveQuietly();

            $this->syncEventRecorder->record(
                $product,
                WooCommerceSyncEventStatus::Failed,
                'sync',
                $message,
                'Dit product heeft nog geen varianten. Voeg minstens één variant toe voordat je naar WooCommerce synchroniseert.'
            );

            return;
        }

        $parentJob = new SerializedProcessProductsToWooCommerce($product);
        $this->syncEventRecorder->queued($product);

        $childJobs = [];
        foreach ($product->variants as $variant) {
            $childJobs[] = new SerializedProcessProductsToWooCommerce($variant);
            $this->syncEventRecorder->queued($variant);
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
        $product->bol_com_sync = $sync;
        $clearedAdditional = $product->additional ?? [];

        unset($clearedAdditional['product_sku_already_exists']);
        unset($clearedAdditional['product_sync_error']);

        if ($product->bol_com_sync) {
            $validation = app(\App\Services\Bol\BolProductValidator::class)->validate($product);
            if ($validation->failed()) {
                $clearedAdditional['product_sync_error'] = $validation->customerSummary();
                $product->bol_com_sync = false;
            }
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

        $this->triggerBolSync($product, $selectedCredentials->all(), $credentialsToDelete->all(), $previousSyncState);
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

    /**
     * De kale tegenvariant van een met-onderkleed-variant, of null als die er
     * niet is.
     *
     * @throws \Exception wanneer het product zelf geen onderkleed heeft
     */
    private function assertMetOnderkleed(Product $product): ?Product
    {
        if ($this->normaliseLabel($this->commonValues($product)['onderkleed'] ?? null) !== 'met onderkleed') {
            throw new \Exception('Moet zonder onderkleed zijn');
        }

        return $this->getUnderrugAlternative($product);
    }

    /**
     * De onderkleedtoeslag voor de maat van dit product, of null wanneer die
     * maat niet in de tarieventabel staat.
     *
     * De maat wordt genormaliseerd opgezocht: de catalogus schrijft dezelfde
     * ronde maat als 'Rond 240 cm', '240 cm Rond' en '240 cm rond', terwijl de
     * tarieventabel er maar één spelling van kent. Staat de maat er alsnog niet
     * in, dan telt de maatgroep — die is per definitie wel een tabelmaat.
     */
    public function underrugSurcharge(Product $product): ?float
    {
        $costs = $this->underrugCosts();
        $common = $this->commonValues($product);

        foreach (['maat', 'maatgroep'] as $attribute) {
            $size = $common[$attribute] ?? null;

            if (! is_string($size) || trim($size) === '') {
                continue;
            }

            $plusPrice = $costs[$this->normaliseSizeKey($size)] ?? null;

            if (! is_null($plusPrice)) {
                return (float) $plusPrice;
            }
        }

        Log::warning('Underrugs cost not found for size', [
            'maat'      => $common['maat'] ?? null,
            'maatgroep' => $common['maatgroep'] ?? null,
        ]);

        return null;
    }

    /**
     * De tarieventabel op genormaliseerde sleutel.
     *
     * @return array<string, int|null>
     */
    private function underrugCosts(): array
    {
        $costs = [];

        foreach ((array) config('rugs.underrugs_cost') as $size => $plusPrice) {
            $costs[$this->normaliseSizeKey((string) $size)] = $plusPrice;
        }

        return $costs;
    }

    /**
     * Eén schrijfwijze per maat: kleine letters, enkele spaties, en ronde maten
     * altijd als 'rond <n> cm', ongeacht waar het woord 'rond' stond.
     */
    private function normaliseSizeKey(string $size): string
    {
        $key = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $size)));

        if (str_contains($key, 'rond') && preg_match('/^(?:rond )?(\d+)(?: cm)?(?: rond)?$/', $key, $matches) === 1) {
            return "rond {$matches[1]} cm";
        }

        return $key;
    }
}
