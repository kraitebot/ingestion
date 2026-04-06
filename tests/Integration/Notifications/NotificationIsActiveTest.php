<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\NotificationService;

/**
 * Helper to create admin user for notification tests.
 */
function createAdminForIsActiveTests(): Kraite
{
    return Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);
}

it('does not send notifications when notification is_active is false', function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Create notification with is_active = false
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create(['is_active' => false]);

    createAdminForIsActiveTests();

    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    expect($result)->toBeFalse();
    Notification::assertNothingSent();
});

it('sends notifications when notification is_active is true', function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Create notification with is_active = true (explicit)
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create(['is_active' => true]);

    createAdminForIsActiveTests();

    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    expect($result)->toBeTrue();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function ($notification) {
            return $notification->canonical === 'server_rate_limit_exceeded';
        }
    );
});

it('sends notifications when is_active defaults to true', function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Create notification WITHOUT specifying is_active (should default to true)
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create();

    // Verify is_active defaulted to true
    $notification = \Kraite\Core\Models\Notification::where('canonical', 'server_rate_limit_exceeded')->first();
    expect($notification->is_active)->toBeTrue();

    createAdminForIsActiveTests();

    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    expect($result)->toBeTrue();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class
    );
});

it('blocks inactive notification even when throttle would allow it', function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Create notification with is_active = false and throttle settings
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create([
            'is_active' => false,
            'cache_duration' => 300,
            'cache_key' => ['api_system'],
        ]);

    createAdminForIsActiveTests();

    // First call with cache-based throttling (would normally be allowed)
    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        cacheKeys: ['api_system' => 'binance']
    );

    expect($result)->toBeFalse();
    Notification::assertNothingSent();

    // Verify throttle cache was never set (is_active check happened before throttle)
    expect(cache()->has('server_rate_limit_exceeded-api_system:binance'))->toBeFalse();
});

it('respects is_active per notification independently', function () {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Create one active and one inactive notification
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create(['is_active' => false]);

    \Kraite\Core\Models\Notification::factory()
        ->serverIpForbidden()
        ->create(['is_active' => true]);

    createAdminForIsActiveTests();

    // Inactive notification should NOT be sent
    $result1 = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    // Active notification should be sent
    $result2 = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_ip_forbidden',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    expect($result1)->toBeFalse();
    expect($result2)->toBeTrue();

    // Only one notification should have been sent
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function ($notification) {
            return $notification->canonical === 'server_ip_forbidden';
        }
    );

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function ($notification) {
            return $notification->canonical === 'server_rate_limit_exceeded';
        }
    );
});

it('global toggle takes precedence over is_active', function () {
    // Global toggle OFF, but notification is_active = true
    config(['kraite.notifications_enabled' => false]);
    Notification::fake();

    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create(['is_active' => true]);

    createAdminForIsActiveTests();

    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    // Should be blocked by global toggle, not is_active
    expect($result)->toBeFalse();
    Notification::assertNothingSent();
});

it('sends notification when canonical does not exist in database', function () {
    // Backwards compatibility: if notification record doesn't exist,
    // the is_active check should not block (notification is null)
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();

    // Do NOT create notification record - simulate unknown canonical
    createAdminForIsActiveTests();

    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'unknown_notification_canonical',
        referenceData: ['test' => 'data'],
        duration: 0
    );

    // Should still send (backwards compatible behavior)
    expect($result)->toBeTrue();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class
    );
});
