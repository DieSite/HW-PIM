<?php

namespace App\Services;

use App\Clients\BolApiClient;
use App\Models\BolComCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Product\Models\Product;

/**
 * Thin read/utility facade over Bol.com.
 *
 * All write/sync logic lives in App\Services\Bol\BolSyncStateMachine
 * + BolPayloadBuilder + BolOfferUpdater. This class only holds:
 *   - Read endpoints used by Artisan commands (categories, catalog, content
 *     status, upload report).
 *   - Helpers used by Blade views (getCredentialsOptions, getProductPrice).
 */
class BolComProductService
{
    public function fetchContentStatus(string $id, BolComCredential $bolComCredential): ?array
    {
        return (new BolApiClient())
            ->setCredential($bolComCredential)
            ->get('/shared/process-status/'.$id);
    }

    public function fetchUploadReport(string $id, BolComCredential $bolComCredential): ?array
    {
        return (new BolApiClient())
            ->setCredential($bolComCredential)
            ->get('/retailer/content/upload-report/'.$id);
    }

    public function fetchCategories(BolComCredential $bolComCredential): ?array
    {
        return (new BolApiClient())
            ->setCredential($bolComCredential)
            ->get('/retailer/products/categories');
    }

    public function fetchCatalogProductDetails(BolComCredential $bolComCredential, string $ean, bool $assets): ?array
    {
        $endpoint = $assets
            ? "/retailer/products/{$ean}/assets"
            : "/retailer/content/catalog-products/{$ean}";

        return (new BolApiClient())
            ->setCredential($bolComCredential)
            ->get($endpoint);
    }

    public function getCredentialsOptions(): array
    {
        return DB::table('bol_com_credentials')
            ->select('id', 'name')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($credential) => [$credential->id => $credential->name])
            ->all();
    }

    /**
     * Reference price shown in the product edit form before bol_price_override
     * is applied (when $excludeOverride is true) or the effective sync price.
     *
     * Kept here because the product edit Blade view calls it directly.
     */
    public function getProductPrice(Product $product, bool $excludeOverride = false): float
    {
        $common = $product->values['common'] ?? [];

        if ($product->bol_price_override && ! $excludeOverride) {
            $price = (float) $product->bol_price_override;
        } else {
            $priceData = $common['prijs'] ?? 0;
            $price = is_array($priceData) ? (float) ($priceData['EUR'] ?? 0) : 0.0;

            $parentCommon = $product->parent?->values['common'] ?? [];
            if (! empty($parentCommon['merk'])) {
                $snake = Str::snake((string) $parentCommon['merk']);
                $discount = (float) config("bolcom.bol_discounts.$snake", 1);
                $price *= $discount;
            }
        }

        return (float) number_format($price, 2, '.', '');
    }
}
