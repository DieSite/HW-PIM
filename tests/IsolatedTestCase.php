<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case for suites that should run in full isolation.
 *
 * Unlike the shared Tests\TestCase — which runs inside a transaction against
 * whatever database is configured — this base isolates its suites onto a
 * dedicated "*_testing" database and (via RefreshDatabase) migrates it itself.
 * That keeps tests from ever touching the working/dev database.
 */
abstract class IsolatedTestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Seed the installer's reference data (channels, locales, currencies,
     * attributes, admin) after the fresh migration — many app tests assume a
     * fully installed instance, which previously came from the dev database.
     */
    protected $seed = true;

    /**
     * Boot the application, then redirect the default connection at a dedicated
     * testing database. The name is derived by appending "_testing", so it is
     * structurally impossible for RefreshDatabase to wipe the working database.
     * The database is created on demand (RefreshDatabase migrates tables, not
     * the schema itself), so the suite is self-provisioning.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $connection = $app['config']['database.default'];
        $database = (string) $app['config']["database.connections.$connection.database"];
        $testing = str_ends_with($database, '_testing') ? $database : $database.'_testing';

        $app['config']->set("database.connections.$connection.database", null);
        $app['db']->purge($connection);
        $app['db']->connection($connection)->statement(
            "CREATE DATABASE IF NOT EXISTS `$testing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        $app['config']->set("database.connections.$connection.database", $testing);
        $app['db']->purge($connection);

        return $app;
    }
}
