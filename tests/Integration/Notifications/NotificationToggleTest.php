<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\NotificationService;

// Test that notifications are blocked when NOTIFICATIONS_ENABLED is false
it('does not send notifications when notifications are globally disabled', function (): void {
    // Disable notifications globally
    config(['kraite.notifications_enabled' => false]);

    // Fake notifications to intercept them
    Notification::fake();

    // Create the notification canonical definition
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create();

    // Create Engine record (singleton) for admin notifications
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // Attempt to send notification - should return false
    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0 // Disable throttling to ensure only the toggle affects the result
    );

    // Assert send() returned false
    expect($result)->toBeFalse();

    // Assert no notifications were sent
    Notification::assertNothingSent();
});

// Test that notifications ARE sent when NOTIFICATIONS_ENABLED is true
it('sends notifications when notifications are globally enabled', function (): void {
    // Enable notifications globally (default)
    config(['kraite.notifications_enabled' => true]);

    // Fake notifications to intercept them
    Notification::fake();

    // Create the notification canonical definition
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create();

    // Create Engine record (singleton) for admin notifications
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // Attempt to send notification - should return true
    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0 // Disable throttling
    );

    // Assert send() returned true
    expect($result)->toBeTrue();

    // Assert notification was sent to admin
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        function ($notification) {
            return $notification->canonical === 'server_rate_limit_exceeded'
                && str_contains($notification->message, 'binance');
        }
    );
});

// Test that the toggle works for all notification types, not just one
it('blocks all notification types when notifications are disabled', function (): void {
    // Disable notifications globally
    config(['kraite.notifications_enabled' => false]);

    // Fake notifications to intercept them
    Notification::fake();

    // Create multiple notification canonical definitions
    \Kraite\Core\Models\Notification::factory()->serverRateLimitExceeded()->create();
    \Kraite\Core\Models\Notification::factory()->serverIpForbidden()->create();

    // Create Engine record (singleton) for admin notifications
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // Try to send multiple different notification types
    $result1 = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    $result2 = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_ip_forbidden',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    // Assert all send() calls returned false
    expect($result1)->toBeFalse();
    expect($result2)->toBeFalse();

    // Assert no notifications were sent at all
    Notification::assertNothingSent();
});

// Test that toggle uses the default value from config when explicitly set to true
it('sends notifications when explicitly enabled in config', function (): void {
    // Explicitly enable notifications (simulates default behavior from config/kraite.php)
    config(['kraite.notifications_enabled' => true]);

    // Fake notifications to intercept them
    Notification::fake();

    // Create the notification canonical definition
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create();

    // Create Engine record (singleton) for admin notifications
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // The config value is true, so notification should be sent
    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        duration: 0
    );

    // Assert send() returned true
    expect($result)->toBeTrue();

    // Assert notification was sent
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class
    );
});

// Test that toggle blocks notifications even when throttling would allow them
it('blocks notifications regardless of throttle settings when disabled', function (): void {
    // Disable notifications globally
    config(['kraite.notifications_enabled' => false]);

    // Fake notifications to intercept them
    Notification::fake();

    // Create the notification canonical definition with throttle
    \Kraite\Core\Models\Notification::factory()
        ->serverRateLimitExceeded()
        ->create([
            'cache_duration' => 300, // 5 minutes throttle
            'cache_key' => ['api_system'],
        ]);

    // Create Engine record (singleton) for admin notifications
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);

    // Try to send with cache-based throttling (would normally be allowed on first call)
    $result = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'binance'],
        cacheKeys: ['api_system' => 'binance']
    );

    // Assert send() returned false due to global toggle, NOT throttle
    expect($result)->toBeFalse();

    // Assert no notifications were sent
    Notification::assertNothingSent();

    // Verify that throttle check was never reached by confirming no cache key was set
    // If the toggle works correctly, we should never even attempt to set throttle cache
    expect(cache()->has('server_rate_limit_exceeded-api_system:binance'))->toBeFalse();
});
