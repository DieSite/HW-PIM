<?php

use Illuminate\Support\Facades\Route;
use Webkul\WooCommerce\Http\Controllers\AttributeMappingController;
use Webkul\WooCommerce\Http\Controllers\CredentialController;
use Webkul\WooCommerce\Http\Controllers\OptionController;
use Webkul\WooCommerce\Http\Controllers\WebhookController;

/**
 * Catalog routes.
 */
Route::group(['middleware' => ['admin'], 'prefix' => config('app.admin_url')], function () {
    Route::prefix('woocommerce')->group(function () {
        Route::controller(CredentialController::class)->prefix('credentials')->group(function () {
            Route::get('', 'index')->name('woocommerce.credentials.index');
            Route::post('create', 'store')->name('woocommerce.credentials.store');
            Route::get('edit/{id}', 'edit')->name('woocommerce.credentials.edit');
            Route::put('update/{id}', 'update')->name('woocommerce.credentials.update');
            Route::get('edit/{id}?attribute-mapping', 'edit')->name('woocommerce.credentials.attribute-mapping');
            Route::delete('delete/{id}', 'destroy')->name('woocommerce.credentials.delete');
        });
    });

    Route::prefix('mappings')->group(function () {
        Route::controller(AttributeMappingController::class)->prefix('attribute')->group(function () {
            Route::get('', 'index')->name('woocommerce.mappings.attribute-mapping.index');
            Route::post('add-additional', 'addAdditionalAttribute')->name('woocommerce.mappings.additional_attributes.add');
            Route::post('remove-additional', 'removeAdditionalAttribute')->name('woocommerce.mappings.additional_attributes.remove');
            Route::put('update/{id}', 'update')->name('woocommerce.attribute-mapping.update');
            Route::post('save', 'save')->name('woocommerce.mappings.additional_attributes.save');
            Route::post('update-editable-field', 'updateEditableField')->name('woocommerce.mappings.additional_attributes.updateEditableField');
        });
    });

    Route::controller(OptionController::class)->group(function () {
        Route::get('get-attribute', 'listAttributes')->name('admin.woocommerce.get-attribute');
        Route::get('get-image-attribute', 'listImageAttributes')->name('admin.woocommerce.get-image-attribute');
        Route::get('get-gallery-attribute', 'listGalleryAttributes')->name('admin.woocommerce.get-gallery-attribute');
        Route::get('product-sku', 'listProductSKU')->name('woocommerce.exporters.filter.productSKU.get');
        Route::get('woocommerce-credentials', 'listWooCommerceCredential')->name('woocommerce.credentials.get');
        Route::get('get-woocommerce-channel', 'listChannel')->name('woocommerce.channel.get');
        Route::get('get-woocommerce-currency', 'listCurrency')->name('woocommerce.currency.get');
        Route::get('custom-attributes', 'listCustomAttributes')->name('woocommerce.exporters.attribute.filter.attributes.get');
        Route::get('get-woocommerce-locale', 'listLocale')->name('woocommerce.locale.get');
        Route::get('additional-attribute', 'getAdditionalAttributeOptions')->name('woocommerce.additional_attributes.options.get');
        Route::get('media-attribute', 'getMediaAttributeOptions')->name('woocommerce.media.options.get');

    });

});

Route::post('woocommerce/callback', [WebhookController::class, 'handleWebhook'])
    ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
