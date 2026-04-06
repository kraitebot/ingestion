<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Once;

abstract class TestCase extends BaseTestCase
{
    protected static bool $parallelHooksRegistered = false;

    /**
     * Setup parallel testing hooks.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Clear the once() cache to prevent test pollution
        // The once() helper memoizes results per process, which can cause
        // stale cached values (like Kraite::admin()) to persist between tests
        Once::flush();

        // Register parallel testing hooks only once
        if (! static::$parallelHooksRegistered) {
            static::$parallelHooksRegistered = true;

            // Cleanup hook: drop parallel test databases after all tests complete
            ParallelTesting::tearDownProcess(static function (#[\SensitiveParameter] int $token) {
                $testDatabase = config('database.connections.mysql.database').'_test_'.$token;

                try {
                    DB::statement("DROP DATABASE IF EXISTS `{$testDatabase}`");
                } catch (\Exception $e) {
                    // Silently ignore if database doesn't exist or can't be dropped
                }
            });
        }
    }
}
