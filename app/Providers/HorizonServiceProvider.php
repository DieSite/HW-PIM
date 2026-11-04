<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * Access follows the PIM login, not the visitor's IP address: an active
     * admin whose role has the `horizon` permission (full administrators
     * always do). The gate reads the `admin` guard explicitly instead of the
     * user Horizon passes in, so it keeps working if the default guard ever
     * changes. The `admin` middleware in config/horizon.php sends guests to
     * the login.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            $admin = auth('admin')->user();

            return $admin !== null
                && (bool) $admin->status
                && bouncer()->hasPermission('horizon');
        });
    }
}
