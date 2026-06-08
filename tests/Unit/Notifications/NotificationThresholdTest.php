<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification as NotificationDef;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\NotificationService;

/**
 * Notification Threshold — the opt-in escalation gate that sits on top of the
 * Throttler.
 *
 * A notification with `has_threshold = true` is only physically delivered once
 * it has recurred `threshold_max_notifications` times within a rolling
 * `threshold_max_duration_minutes` window. Sub-threshold occurrences are still
 * recorded in notification_logs (passed_threshold = false, status
 * 'threshold held') but never delivered. After a breach the counter re-earns
 * from scratch — so with "2 in 1 minute" the admin is alerted on every 2nd
 * occurrence (#2, #4, …), never on the lone ones.
 *
 * Notifications WITHOUT a threshold are completely untouched.
 */
uses(RefreshDatabase::class)
    ->group('unit', 'notifications', 'threshold');

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ]
    );

    Server::firstOrCreate(
        ['hostname' => gethostname()],
        [
            'ip_address' => '127.0.0.1',
            'is_apiable' => false,
            'needs_whitelisting' => false,
            'type' => 'app',
        ]
    );

    Once::flush();
    Cache::flush();
    NotificationService::flushNotificationCache();
});

/**
 * Seed the server_rate_limit_exceeded canonical (a known template that needs no
 * required reference data) with a threshold and no throttle, so every
 * occurrence reaches the threshold gate.
 */
function seedThresholdNotification(bool $hasThreshold, ?int $max = null, ?int $minutes = null): void
{
    NotificationDef::updateOrCreate(
        ['canonical' => 'server_rate_limit_exceeded'],
        [
            'title' => 'Rate Limit Exceeded',
            'description' => 'Test seed.',
            'default_severity' => 'info',
            'verified' => 1,
            'is_active' => true,
            'cache_duration' => 0, // no throttle — every occurrence reaches the threshold gate
            'cache_key' => null,
            'has_threshold' => $hasThreshold,
            'threshold_max_notifications' => $max,
            'threshold_max_duration_minutes' => $minutes,
        ]
    );

    NotificationService::flushNotificationCache();
}

function fireRateLimitNotification(): bool
{
    return NotificationService::send(
        user: Kraite::admin(),
        canonical: 'server_rate_limit_exceeded',
        referenceData: ['exchange' => 'bybit'],
    );
}

function heldRows(): int
{
    return NotificationLog::where('canonical', 'server_rate_limit_exceeded')
        ->where('passed_threshold', false)
        ->count();
}

it('holds sub-threshold occurrences and delivers on every Nth (re-earn)', function (): void {
    Notification::fake();
    seedThresholdNotification(hasThreshold: true, max: 2, minutes: 1);

    // 2-in-1-minute: alert on every 2nd, re-earning after each breach.
    $results = [
        fireRateLimitNotification(), // #1 — held
        fireRateLimitNotification(), // #2 — breach → delivered
        fireRateLimitNotification(), // #3 — held (counter reset after #2)
        fireRateLimitNotification(), // #4 — breach → delivered
    ];

    expect($results)->toBe([false, true, false, true]);

    // Two breaches delivered, two occurrences held (recorded, not sent).
    Notification::assertCount(2);
    expect(heldRows())->toBe(2);

    $held = NotificationLog::where('canonical', 'server_rate_limit_exceeded')
        ->where('passed_threshold', false)
        ->first();
    expect($held->status)->toBe('threshold held');
});

it('does not breach when occurrences are spread wider than the window', function (): void {
    Notification::fake();
    seedThresholdNotification(hasThreshold: true, max: 2, minutes: 1);

    expect(fireRateLimitNotification())->toBeFalse(); // #1 — held

    // Advance past the 1-minute window so #1 no longer counts.
    $this->travel(2)->minutes();

    expect(fireRateLimitNotification())->toBeFalse(); // #2 — fresh start, still held

    Notification::assertNothingSent();
    expect(heldRows())->toBe(2);
});

it('delivers immediately once the window holds enough occurrences (max 3)', function (): void {
    Notification::fake();
    seedThresholdNotification(hasThreshold: true, max: 3, minutes: 5);

    $results = [
        fireRateLimitNotification(), // #1 — held
        fireRateLimitNotification(), // #2 — held
        fireRateLimitNotification(), // #3 — breach (3 in window)
    ];

    expect($results)->toBe([false, false, true]);
    Notification::assertCount(1);
    expect(heldRows())->toBe(2);
});

it('leaves notifications without a threshold completely unchanged', function (): void {
    Notification::fake();
    seedThresholdNotification(hasThreshold: false);

    expect(fireRateLimitNotification())->toBeTrue();

    Notification::assertCount(1);
    expect(heldRows())->toBe(0);
});
