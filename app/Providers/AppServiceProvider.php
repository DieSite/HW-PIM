<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;

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

        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void {}
}
