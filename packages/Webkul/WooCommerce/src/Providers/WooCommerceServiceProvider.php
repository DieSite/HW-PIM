<?php

namespace Webkul\WooCommerce\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Theme\ViewRenderEventManager;
use Webkul\WooCommerce\Console\Commands\WooCommerceInstaller;
use Webkul\WooCommerce\Listeners\ProductSync;
use Webkul\WooCommerce\Models\Credential;
use Webkul\WooCommerce\Models\DataTransferMapping;

class WooCommerceServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Route::middleware('web')->group(__DIR__.'/../Routes/woocommerce-routes.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migration');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'woocommerce');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'woocommerce');
        $this->app->register(ModuleServiceProvider::class);
        // Bust the cached default credential whenever it is saved so the next job
        // picks up the latest configuration without waiting for the TTL to expire.
        Credential::saved(fn () => Cache::forget('wc_default_credential'));

        // Bust cached attribute/category mapping entries when a mapping record is
        // written — this covers attribute export jobs re-exporting to WooCommerce.
        DataTransferMapping::saved(function (DataTransferMapping $mapping) {
            if (in_array($mapping->entityType, ['attribute', 'category'])) {
                Cache::forget("wc_mapping_{$mapping->entityType}_{$mapping->code}_".md5($mapping->apiUrl));
            }
        });

        Event::listen('catalog.product.update.after', [ProductSync::class, 'syncProductToWooCommerce']);
        Event::listen('catalog.product.create.after', [ProductSync::class, 'syncProductToWooCommerce']);
        Event::listen('catalog.product.delete.before', [ProductSync::class, 'deleteProductFromWooCommerce']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                WooCommerceInstaller::class,
            ]);
        }

        Event::listen('unopim.admin.layout.head', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('woocommerce::icon-style');
        });

        $this->publishes([
            __DIR__.'/../../publishable' => public_path('themes/woocommerce'),
        ], 'woocommerce-config');
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/exporters.php',
            'exporters'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/quick_exporters.php',
            'quick_exporters'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/unopim-vite.php',
            'unopim-vite.viters'
        );
    }
}
