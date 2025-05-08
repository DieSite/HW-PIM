<?php

use App\Http\Controllers\CustomBolComController;
use App\Http\Controllers\CustomImportController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['web', 'admin']], function () {
    Route::prefix('custom')->group(function () {
        Route::get('imports', [CustomImportController::class, 'index'])->name('admin.custom.imports.index');
        Route::post('imports/upload', [CustomImportController::class, 'upload'])->name('admin.custom.imports.upload');
    });
});

Route::group(['middleware' => ['web', 'admin']], function () {
    Route::prefix('custom')->group(function () {
        Route::get('bolCom', [CustomBolComController::class, 'index'])->name('admin.custom.bolCom.index');
        Route::get('bolCom/create', [CustomBolComController::class, 'create'])->name('admin.custom.bolCom.create');
        Route::post('bolCom', [CustomBolComController::class, 'store'])->name('admin.custom.bolCom.store');
        Route::get('bolCom/{id}/edit', [CustomBolComController::class, 'edit'])->name('admin.custom.bolCom.edit');
        Route::put('bolCom/{id}', [CustomBolComController::class, 'update'])->name('admin.custom.bolCom.update');
        Route::delete('bolCom/{id}', [CustomBolComController::class, 'destroy'])->name('admin.custom.bolCom.destroy');
        Route::get('bolCom/{id}/test', [CustomBolComController::class, 'test'])->name('admin.custom.bolCom.test');
        Route::post('/bolCom/bulk-sync', [CustomBolComController::class, 'bulkSync'])->name('admin.custom.bolCom.bulkSync');
    });
});
