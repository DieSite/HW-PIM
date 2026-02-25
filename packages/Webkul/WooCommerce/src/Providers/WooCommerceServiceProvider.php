<?php

namespace Webkul\WooCommerce\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Theme\ViewRenderEventManager;
use Webkul\WooCommerce\Console\Commands\WooCommerceInstaller;
use Webkul\WooCommerce\Listeners\ProductSync;

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
