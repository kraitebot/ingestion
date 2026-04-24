<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Kraite\Core\Models\Kraite;
use Tests\TestCase;

/**
 * Pre-seed `Kraite::ip()` for every test so the fallback — which reaches
 * out to ipify.org — never fires under `Http::preventStrayRequests()`.
 * Tests that need a specific server IP can still override the cache key.
 */
function seedKraiteServerIpCache(): void
{
    Cache::put(Kraite::IP_CACHE_KEY, '127.0.0.1', Kraite::IP_CACHE_TTL_SECONDS);
}

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();

        // Ensure Engine record exists for tests that need it
        Kraite::firstOrCreate(
            ['id' => 1],
            [
                'allow_opening_positions' => true,
                'email' => env('ADMIN_USER_EMAIL', 'admin@example.com'),
                'admin_pushover_user_key' => env('ADMIN_USER_PUSHOVER_USER_KEY', 'test'),
                'admin_pushover_application_key' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY', 'test'),
                'notification_channels' => ['pushover', 'mail'],
            ]
        );

        // Clear the once() cache to prevent cross-test pollution
        Once::flush();

        seedKraiteServerIpCache();
    })
    ->in('Browser', 'Feature');

// Pure unit tests without database
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();

        // Ensure Engine record exists for tests that need it
        Kraite::firstOrCreate(
            ['id' => 1],
            [
                'allow_opening_positions' => true,
                'email' => env('ADMIN_USER_EMAIL', 'admin@example.com'),
                'admin_pushover_user_key' => env('ADMIN_USER_PUSHOVER_USER_KEY', 'test'),
                'admin_pushover_application_key' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY', 'test'),
                'notification_channels' => ['pushover', 'mail'],
            ]
        );

        // Clear the once() cache to prevent cross-test pollution
        Once::flush();

        seedKraiteServerIpCache();
    })
    ->in('Unit');

// Integration tests without seeding - use factories instead
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();

        // Clear the once() cache to prevent cross-test pollution
        Once::flush();

        seedKraiteServerIpCache();
    })
    ->in('Integration');

expect()->extend('toBeOne', fn () => $this->toBe(1));
