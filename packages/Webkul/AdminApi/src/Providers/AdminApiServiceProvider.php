<?php

namespace Webkul\AdminApi\Providers;

use Carbon\Carbon;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Webkul\AdminApi\Console\ApiClientCommand;
use Webkul\AdminApi\Http\Middleware\EnsureAcceptsJson;
use Webkul\AdminApi\Http\Middleware\LocaleMiddleware;
use Webkul\AdminApi\Http\Middleware\ScopeMiddleware;
use Webkul\AdminApi\Models\Client;
use Webkul\Core\Tree;

class AdminApiServiceProvider extends ServiceProvider
{
    /**
     * Register your middleware aliases here.
     *
     * @var array
     */
    protected $middlewareAliases = [
        'accept.json'    => EnsureAcceptsJson::class,
        'request.locale' => LocaleMiddleware::class,
        'api.scope'      => ScopeMiddleware::class,
    ];

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        Route::middleware('web')->group(__DIR__.'/../Routes/integrations-routes.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->activateMiddlewareAliases();
        $this->activatePassportApiClient();

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'admin_api');
        $this->composeView();
        $this->registerACL();
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerCommands();
        $this->registerApiRoutes();
        $this->registerConfig();
    }

    /**
     * Registers acl to entire application
     *
     * @return void
     */
    public function registerACL()
    {
        $this->app->singleton('api-acl', function () {
            return $this->createACL();
        });
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/api-acl.php', 'api-acl'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php', 'menu.admin'
        );
    }

    /**
     * Define the "api" routes for the application.
     *
     * @return void
     */
    protected function registerApiRoutes()
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/admin-api.php');

    }

    /**
     * Register the Installer Commands of this package.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ApiClientCommand::class,
            ]);
        }
    }

    /**
     * Register the Installer Commands of this package.
     */
    protected function activatePassportApiClient(): void
    {
        Passport::loadKeysFrom(__DIR__.'/../Secrets/Oauth');

        /**
         * The keys ship with the git checkout, which cannot carry 0600 permissions.
         */
        Passport::$validateKeyPermissions = false;

        Passport::enablePasswordGrant();
        Passport::useClientModel(Client::class);

        /**
         * Access tokens live a day; refresh tokens rotate on every use and live
         * 90 days, so MCP connectors and API integrations only sign in again
         * after 90 days of inactivity.
         */
        Passport::tokensExpireIn(Carbon::now()->addDay());

        Passport::refreshTokensExpireIn(Carbon::now()->addDays(90));

        $this->app->bind(ClientRepository::class, \Webkul\AdminApi\Repositories\ClientRepository::class);
    }

    /**
     * Activate middleware aliases.
     *
     * @return void
     */
    protected function activateMiddlewareAliases()
    {
        collect($this->middlewareAliases)->each(function ($className, $alias) {
            $this->app['router']->aliasMiddleware($alias, $className);
        });
    }

    /**
     * Bind the the data to the views
     *
     * @return void
     */
    protected function composeView()
    {
        view()->composer([
            'admin_api::integrations.api-keys.create',
            'admin_api::integrations.api-keys.edit',
        ], function ($view) {
            $view->with('acl', $this->createACL());
        });
    }

    /**
     * Create ACL tree.
     *
     * @return mixed
     */
    protected function createACL()
    {
        static $tree;

        if ($tree) {
            return $tree;
        }

        $tree = Tree::create();

        foreach (config('api-acl') as $item) {
            $tree->add($item, 'acl');
        }

        $tree->items = core()->sortItems($tree->items);

        return $tree;
    }
}
