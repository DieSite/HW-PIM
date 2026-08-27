<?php

namespace App\Providers;

use App\Jobs\Middleware\ThrottlesWooCommerceSync;
use App\Services\AI\AiSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Events\JobFailed as QueueJobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Laravel\Horizon\Events\JobFailed as HorizonJobFailed;
use Sentry\Laravel\Integration;
use Webkul\Product\Models\Product as WebkulProduct;
use Webkul\Theme\ViewRenderEventManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->bind(DriverInterface::class, function () {
            if (extension_loaded('imagick')) {
                return new ImagickDriver();
            }

            if (extension_loaded('gd')) {
                return new GdDriver();
            }

            throw new \RuntimeException('No supported image driver found.');
        });

        $this->app->singleton(ImageManager::class, function ($app) {
            return new ImageManager($app->make(DriverInterface::class));
        });

        Schema::defaultStringLength(191);

        /**
         * Ceiling on outgoing WooCommerce product writes, one slot per
         * product (a parent rug and each of its variants are separate jobs).
         * Applied by {@see ThrottlesWooCommerceSync}; a job that finds the
         * window full waits for the next one instead of being dropped.
         */
        RateLimiter::for(
            ThrottlesWooCommerceSync::LIMITER,
            fn () => Limit::perMinute((int) config('woocommerce_sync.rate_limit.per_minute'))
        );

        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });

        Event::listen(HorizonJobFailed::class, function (HorizonJobFailed $event) {
            Integration::captureUnhandledException($event->exception);
        });

        Event::listen(QueueJobFailed::class, function (QueueJobFailed $event) {
            Integration::captureUnhandledException($event->exception);
        });

        Event::listen('unopim.admin.catalog.product.edit.form.after', function (ViewRenderEventManager $event) {
            $product = $event->getParam('product');

            if (! $product instanceof WebkulProduct) {
                return;
            }

            $appProduct = \App\Models\Product::with(['bolSyncEvents.credential', 'wooCommerceSyncEvents'])->find($product->id);

            if ($appProduct === null) {
                return;
            }

            $event->addTemplate(view('admin::custom.bolCom.timeline', [
                'product' => $appProduct,
                'events'  => $appProduct->bolSyncEvents,
            ])->render());

            $event->addTemplate(view('admin::custom.wooCommerce.timeline', [
                'product' => $appProduct,
                'events'  => $appProduct->wooCommerceSyncEvents,
            ])->render());
        });

        // Render the primary-image editor directly below the image gallery
        // (the "afbeelding" asset field), not at the bottom of the form.
        Event::listen('unopim.admin.products.dynamic-attribute-fields.control.asset.after', function (ViewRenderEventManager $event) {
            $field = $event->getParam('field');

            if (! config('product_image_editor.enabled')
                || ! $field
                || $field->code !== config('product_image_editor.primary_attribute')) {
                return;
            }

            $productId = request()->route('id');
            $product = $productId ? WebkulProduct::find($productId) : null;

            if (! $product instanceof WebkulProduct) {
                return;
            }

            $event->addTemplate(view('admin::custom.productImageEditor.editor', [
                'product' => $product,
            ])->render());
        });

        // A per-field "write this one with AI" button under each text the
        // generator can produce. The header button still does all three in a
        // single call; this is for touching up one of them without paying for
        // the other two.
        Event::listen('unopim.admin.products.dynamic-attribute-fields.control.textarea.after', function (ViewRenderEventManager $event) {
            $field = $event->getParam('field');

            if (! $field || ! array_key_exists($field->code, (array) config('ai.fields'))) {
                return;
            }

            if (! app(AiSettings::class)->enabled()) {
                return;
            }

            // Descriptions live on the parent; a variant's text fields are all null.
            $productId = request()->route('id');
            $product = $productId ? WebkulProduct::find($productId) : null;

            if (! $product instanceof WebkulProduct || $product->parent_id) {
                return;
            }

            $event->addTemplate(view('admin::custom.aiTexts.field-button', [
                'fieldCode' => $field->code,
            ])->render());
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/image_editor_settings.php'), 'core');
        $this->mergeConfigFrom(base_path('config/competitor_pricing_settings.php'), 'core');
        $this->mergeConfigFrom(base_path('config/afwerkingen_settings.php'), 'core');
        $this->mergeConfigFrom(base_path('config/ai_settings.php'), 'core');
    }
}
