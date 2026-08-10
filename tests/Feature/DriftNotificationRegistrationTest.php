<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Models\Notification;

uses(RefreshDatabase::class)->group('feature', 'drift', 'notifications');

it('registers every drift safety canonical with its exact throttle identity', function (): void {
    $expected = [
        'position_exchange_only_detected' => [
            'severity' => NotificationSeverity::Critical,
            'cache_key' => ['account', 'symbol', 'direction'],
        ],
        'orders_exchange_only_detected' => [
            'severity' => NotificationSeverity::Critical,
            'cache_key' => ['account'],
        ],
        'account_drift_snapshot_failed' => [
            'severity' => NotificationSeverity::High,
            'cache_key' => ['account'],
        ],
    ];

    $notifications = Notification::query()
        ->whereIn('canonical', array_keys($expected))
        ->get()
        ->keyBy('canonical');

    expect($notifications)->toHaveCount(3);

    foreach ($expected as $canonical => $attributes) {
        $notification = $notifications->get($canonical);

        expect($notification)->not->toBeNull()
            ->and($notification->default_severity)->toBe($attributes['severity'])
            ->and($notification->cache_key)->toBe($attributes['cache_key'])
            ->and($notification->cache_duration)->toBe(60)
            ->and($notification->verified)->toBeTrue()
            ->and($notification->is_active)->toBeTrue();
    }
});
