<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\Http\Controllers\ProductHelperController;
use App\Http\Controllers\ProductImageEditorController;

Route::post('/product/met_onderkleed_price', [ProductHelperController::class, 'price']);
Route::post('/product/meta_fields', [ProductHelperController::class, 'metaFields']);
Route::post('/product/generateSku', [ProductHelperController::class, 'sku']);

Route::get('/product/frontend/{product}', [ProductHelperController::class, 'redirectToFrontend'])->name('product.frontend');

Route::group([
    'middleware' => ['web', 'admin'],
    'prefix'     => config('app.admin_url').'/product-image-editor',
], function () {
    Route::get('/source/{asset}', [ProductImageEditorController::class, 'source'])->name('admin.product_image_editor.source');
    Route::get('/image/{asset}', [ProductImageEditorController::class, 'image'])->name('admin.product_image_editor.image');
});
