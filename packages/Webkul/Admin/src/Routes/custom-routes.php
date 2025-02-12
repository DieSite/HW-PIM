<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomImportController;

Route::group(['middleware' => ['web', 'admin']], function () {
    Route::prefix('custom')->group(function () {
        Route::get('imports', [CustomImportController::class, 'index'])->name('admin.custom.imports.index');
        Route::post('imports/upload', [CustomImportController::class, 'upload'])->name('admin.custom.imports.upload');
    });
});