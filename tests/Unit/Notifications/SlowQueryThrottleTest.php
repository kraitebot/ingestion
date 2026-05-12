<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification as NotificationDef;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\NotificationService;

/**
 * Pin the slow_query_detected race-safety fix.
 *
 * Pre-fix: cache_key was NULL, so the throttle fell back to a non-atomic
 * `notification_logs::exists()` check. Two workers hitting a slow query in
 * the same window could both pass the check and both emit.
 *
 * Post-fix: cache_key=["connection"] routes through Cache::add() (atomic
 * SETNX). Two sends with the same connection within the throttle window
 * collapse to a single emission. Different connections still alert
 * independently.
 */
uses(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->group('unit', 'notifications', 'slow-query');

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

    NotificationDef::firstOrCreate(
        ['canonical' => 'slow_query_detected'],
        [
            'title' => 'Slow Database Query Detected',
            'description' => 'Triggered when a database query exceeds the configured slow_query_threshold_ms.',
            'detailed_description' => 'Test seed.',
            'default_severity' => 'high',
            'verified' => 1,
            'is_active' => true,
            'cache_duration' => 300,
            'cache_key' => ['connection'],
        ]
    );

    Illuminate\Support\Once::flush();
    Cache::flush();
    NotificationService::flushNotificationCache();
});

it('slow_query_detected notification row uses cache_key=["connection"]', function (): void {
    $notification = NotificationDef::where('canonical', 'slow_query_detected')->first();

    expect($notification)->not->toBeNull();
    expect($notification->cache_key)->toBe(['connection']);
    expect($notification->cache_duration)->toBe(300);
});

it('two slow_query sends on the same connection within the window collapse to one emission', function (): void {
    Notification::fake();

    $first = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'slow_query_detected',
        referenceData: [
            'sql_full' => 'select * from positions where id = 1',
            'time_ms' => 7000,
            'connection' => 'mysql',
            'threshold_ms' => 5000,
        ],
        cacheKeys: ['connection' => 'mysql']
    );

    $second = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'slow_query_detected',
        referenceData: [
            'sql_full' => 'select * from orders where id = 1',
            'time_ms' => 8500,
            'connection' => 'mysql',
            'threshold_ms' => 5000,
        ],
        cacheKeys: ['connection' => 'mysql']
    );

    expect($first)->toBeTrue();
    expect($second)->toBeFalse();
});

it('slow_query sends on different connections both emit independently', function (): void {
    Notification::fake();

    $primary = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'slow_query_detected',
        referenceData: [
            'sql_full' => 'select * from positions where id = 1',
            'time_ms' => 7000,
            'connection' => 'mysql',
            'threshold_ms' => 5000,
        ],
        cacheKeys: ['connection' => 'mysql']
    );

    $replica = NotificationService::send(
        user: Kraite::admin(),
        canonical: 'slow_query_detected',
        referenceData: [
            'sql_full' => 'select * from positions where id = 1',
            'time_ms' => 9000,
            'connection' => 'mysql_read',
            'threshold_ms' => 5000,
        ],
        cacheKeys: ['connection' => 'mysql_read']
    );

    expect($primary)->toBeTrue();
    expect($replica)->toBeTrue();
});
