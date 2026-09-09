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

use App\Http\Controllers\CompetitorCoverageController;
use App\Http\Controllers\ProductHelperController;
use App\Http\Controllers\ProductImageEditorController;
use Webkul\User\Models\Admin;

Route::post('/product/met_onderkleed_price', [ProductHelperController::class, 'price']);
Route::post('/product/meta_fields', [ProductHelperController::class, 'metaFields']);
Route::post('/product/generateSku', [ProductHelperController::class, 'sku']);

Route::get('/product/frontend/{product}', [ProductHelperController::class, 'redirectToFrontend'])->name('product.frontend');

Route::get('/product-by-sku/{sku}', [ProductHelperController::class, 'redirectToEditBySku'])
    ->middleware('admin')
    ->name('product.by-sku');

/*
 * Dev-gemak: log in één klik in als de eerste admin. Bestaat alleen in de
 * lokale omgeving — overal anders een 404 (runtime-check, zodat een gecachte
 * routelijst hem nooit per ongeluk in productie activeert).
 */
Route::middleware('web')->get('/dev/quick-login', function () {
    abort_unless(app()->environment('local'), 404);

    auth('admin')->login(Admin::query()->firstOrFail());

    return redirect()->route('admin.dashboard.index');
})->name('dev.quick-login');

Route::group([
    'middleware' => ['web', 'admin'],
    'prefix'     => config('app.admin_url').'/product-image-editor',
], function () {
    Route::get('/source/{asset}', [ProductImageEditorController::class, 'source'])->name('admin.product_image_editor.source');
    Route::get('/image/{asset}', [ProductImageEditorController::class, 'image'])->name('admin.product_image_editor.image');
});

/*
 * De knop "Klopt, geen concurrent gevonden" uit het dagrapport. Wordt vanuit
 * een mailclient geklikt, dus zonder sessie — vandaar `signed` in plaats van
 * `admin`. De handtekening zit in de URL die de mail zelf genereert.
 */
Route::middleware(['web', 'signed'])->group(function (): void {
    Route::get('/pricing/geen-concurrent/{sku}', [CompetitorCoverageController::class, 'confirm'])
        ->where('sku', '.*')
        ->name('pricing.coverage.confirm');

    Route::get('/pricing/prijs-akkoord/{sku}', [CompetitorCoverageController::class, 'acknowledge'])
        ->where('sku', '.*')
        ->name('pricing.suspect.acknowledge');
});
