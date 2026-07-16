<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Once;
use Kraite\Core\Support\NotificationService;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Parallel workers reuse their PHP process for multiple tests. Clear
        // both process caches so database rollbacks cannot leave stale models.
        Once::flush();
        NotificationService::flushNotificationCache();
    }
}
