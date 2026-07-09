<?php

use App\Http\Controllers\BolSyncController;
use App\Http\Controllers\CustomBolComController;
use App\Http\Controllers\CustomImportController;
use App\Http\Controllers\PhotoroomController;
use App\Http\Controllers\Tools\DeMunkStockController;
use App\Http\Controllers\Tools\ErroredProductsController;
use App\Http\Controllers\Tools\EurgrosController;
use App\Http\Controllers\Tools\HordeurenAnalysisController;
use App\Http\Controllers\Tools\ProductHWStockEditorController;
use App\Http\Controllers\Tools\ProductStockEditorController;
use App\Http\Controllers\WooCommerceSyncController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['web', 'admin']], function () {
    Route::prefix('catalog/products/{productId}/photoroom')->group(function () {
        Route::post('{attributeCode}/transform', [PhotoroomController::class, 'transform'])
            ->name('admin.catalog.products.photoroom.transform');
    });
});

Route::group(['middleware' => ['web', 'admin']], function () {
    Route::prefix('custom')->group(function () {
        Route::get('imports', [CustomImportController::class, 'index'])->name('admin.custom.imports.index');
        Route::post('imports/upload', [CustomImportController::class, 'upload'])->name('admin.custom.imports.upload');
    });
});

Route::group(['middleware' => ['web', 'admin']], function () {
    Route::prefix('tools')->group(function () {
        Route::get('stock', [ProductStockEditorController::class, 'index'])->name('admin.tools.product-stock-editor.index');
        Route::post('stock', [ProductStockEditorController::class, 'update'])->name('admin.tools.product-stock-editor.post');

        Route::get('/showroom-stock', [ProductHWStockEditorController::class, 'index'])->name('admin.tools.product-hw-stock-editor.index');
        Route::post('/showroom-stock', [ProductHWStockEditorController::class, 'update'])->name('admin.tools.product-hw-stock-editor.post');

        Route::get('/errored-products', [ErroredProductsController::class, 'index'])->name('admin.tools.errored-products.index');

        Route::get('/eurogros/voorraadlijst', [EurgrosController::class, 'downloadVoorraadlijst'])->name('admin.tools.eurgros.vooraadlijst');

        Route::get('/hordeuren-analyse', [HordeurenAnalysisController::class, 'index'])->name('admin.tools.hordeuren-analyse.index');
        Route::post('/hordeuren-analyse', [HordeurenAnalysisController::class, 'run'])->name('admin.tools.hordeuren-analyse.run');

        Route::get('/demunk-voorraad', [DeMunkStockController::class, 'index'])->name('admin.tools.demunk-voorraad.index');
        Route::post('/demunk-voorraad/import', [DeMunkStockController::class, 'import'])->name('admin.tools.demunk-voorraad.import');
        Route::get('/demunk-voorraad/search-products', [DeMunkStockController::class, 'searchProducts'])->name('admin.tools.demunk-voorraad.search-products');
        Route::post('/demunk-voorraad/link', [DeMunkStockController::class, 'link'])->name('admin.tools.demunk-voorraad.link');
        Route::post('/demunk-voorraad/unlink', [DeMunkStockController::class, 'unlink'])->name('admin.tools.demunk-voorraad.unlink');
    });

    Route::prefix('custom')->group(function () {
        Route::get('bolCom', [CustomBolComController::class, 'index'])->name('admin.custom.bolCom.index');
        Route::get('bolCom/create', [CustomBolComController::class, 'create'])->name('admin.custom.bolCom.create');
        Route::post('bolCom', [CustomBolComController::class, 'store'])->name('admin.custom.bolCom.store');
        Route::get('bolCom/{id}/edit', [CustomBolComController::class, 'edit'])->name('admin.custom.bolCom.edit');
        Route::put('bolCom/{id}', [CustomBolComController::class, 'update'])->name('admin.custom.bolCom.update');
        Route::delete('bolCom/{id}', [CustomBolComController::class, 'destroy'])->name('admin.custom.bolCom.destroy');
        Route::get('bolCom/{id}/test', [CustomBolComController::class, 'test'])->name('admin.custom.bolCom.test');
        Route::post('/bolCom/bulk-sync', [CustomBolComController::class, 'bulkSync'])->name('admin.custom.bolCom.bulkSync');

        Route::post('bolCom/products/{productId}/retry', [BolSyncController::class, 'retry'])
            ->name('admin.custom.bolCom.product.retry');
        Route::get('bolCom/products/{productId}/timeline', [BolSyncController::class, 'timeline'])
            ->name('admin.custom.bolCom.product.timeline');

        Route::post('wooCommerce/products/{productId}/retry', [WooCommerceSyncController::class, 'retry'])
            ->name('admin.custom.wooCommerce.product.retry');
    });
});
