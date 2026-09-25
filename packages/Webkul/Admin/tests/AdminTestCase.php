<?php

namespace Webkul\Admin\Tests;

use Tests\IsolatedTestCase;
use Webkul\User\Tests\Concerns\UserAssertions;

/**
 * Runs on the self-provisioning *_testing database, so the suite no longer
 * depends on the state of the dev database.
 */
class AdminTestCase extends IsolatedTestCase
{
    use UserAssertions;
}
