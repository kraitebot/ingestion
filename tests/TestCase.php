<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Once;
use Kraite\Core\Models\AppLog;
use Kraite\Core\Models\ModelLog;
use Kraite\Core\Support\NotificationService;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Parallel workers reuse their PHP process for multiple tests. Clear
        // process state so database rollbacks cannot affect later tests.
        Once::flush();
        NotificationService::flushNotificationCache();
        AppLog::enable();
        ModelLog::enable();
        ModelLog::setCurrentStep(null);
    }
}
