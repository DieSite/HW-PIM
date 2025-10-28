<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobFailed as QueueJobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Laravel\Horizon\Events\JobFailed as HorizonJobFailed;
use Sentry\Laravel\Integration;

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

        Event::listen(HorizonJobFailed::class, function (HorizonJobFailed $event) {
            Integration::captureUnhandledException($event->exception);
        });

        Event::listen(QueueJobFailed::class, function (QueueJobFailed $event) {
            Integration::captureUnhandledException($event->exception);
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void {}
}
