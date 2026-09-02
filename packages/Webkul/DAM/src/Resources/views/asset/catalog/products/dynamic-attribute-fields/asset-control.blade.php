@php
    $value ??= [];

    $fieldName ??= 'assets';

    $field ??= [];

    $productId ??= null;

    /**
     * De assetkiezer opent standaard op de productnaam: de bestandsnamen in de
     * DAM zijn opgebouwd rond diezelfde naam, dus dat scheelt telkens hetzelfde
     * zoekwoord intypen.
     */
    $defaultSearch = $productId
        ? once(function () use ($productId) {
            $product = \Webkul\Product\Models\Product::find($productId);

            return $product ? app(\App\Services\ProductService::class)->assetSearchTerm($product) : '';
        })
        : '';
@endphp

<x-dam::asset.field
    :name="$fieldName"
    :asset-values="is_array($value) ? implode(',', $value) : $value"
    :default-search="$defaultSearch"
    showPlaceholders="true"
    width="210px"
/>
