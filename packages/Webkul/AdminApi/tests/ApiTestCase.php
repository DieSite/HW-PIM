<?php

namespace Webkul\AdminApi\Tests;

use Tests\IsolatedTestCase;
use Webkul\AdminApi\Tests\Traits\ApiHelperTrait;
use Webkul\Core\Tests\Concerns\CoreAssertions;

/**
 * Runs on the self-provisioning *_testing database, so the suite no longer
 * depends on the state of the dev database.
 */
class ApiTestCase extends IsolatedTestCase
{
    use ApiHelperTrait, CoreAssertions;
}
