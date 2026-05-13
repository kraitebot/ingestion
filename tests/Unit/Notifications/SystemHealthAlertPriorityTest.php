<?php

declare(strict_types=1);

use Kraite\Core\Support\NotificationMessageBuilder;

/**
 * Pin the severity → Pushover priority mapping on `system_health_alert`.
 *
 * Pre-fix, the builder accepted a `severity` field, mapped it to a
 * `NotificationSeverity` enum, then hardcoded `priority => 0` so every
 * alert arrived at the same interrupt level. Critical Redis-down at
 * 03:00 hit the operator's phone with the same urgency as a medium
 * queue-depth alert at noon.
 *
 * Post-fix, severity flows through to Pushover priority:
 *  - critical → 1 (high — bypasses quiet hours, alert sound)
 *  - high     → 1 (same — these are page-now signals)
 *  - medium   → 0 (normal default)
 *  - anything lower → -1 (quiet — no sound/vibration)
 *
 * Emergency priority 2 (repeat-until-ack) is intentionally NOT used —
 * cron-driven alerts shouldn't demand interactive ack.
 */
it('maps critical severity to Pushover priority 1', function (): void {
    $payload = NotificationMessageBuilder::build('system_health_alert', [
        'signal' => 'redis_down',
        'severity' => 'critical',
        'title' => 'Redis unreachable',
        'detail' => 'Default Redis connection ping failed.',
        'detected_at' => now()->toIso8601String(),
    ]);

    expect($payload['priority'])->toBe(1);
});

it('maps high severity to Pushover priority 1', function (): void {
    $payload = NotificationMessageBuilder::build('system_health_alert', [
        'signal' => 'mark_price_stale_BTCUSDT',
        'severity' => 'high',
        'title' => 'Mark price stale for BTCUSDT',
        'detail' => 'Stale 90s.',
        'detected_at' => now()->toIso8601String(),
    ]);

    expect($payload['priority'])->toBe(1);
});

it('maps medium severity to Pushover priority 0', function (): void {
    $payload = NotificationMessageBuilder::build('system_health_alert', [
        'signal' => 'horizon_queue_depth_default',
        'severity' => 'medium',
        'title' => 'Horizon queue depth high on `default`',
        'detail' => '`default` queue depth = 600 (threshold: 500).',
        'detected_at' => now()->toIso8601String(),
    ]);

    expect($payload['priority'])->toBe(0);
});

it('maps unknown severity to high (priority 1) via the existing default branch', function (): void {
    $payload = NotificationMessageBuilder::build('system_health_alert', [
        'signal' => 'something_unknown',
        'severity' => 'gibberish',
        'title' => 'Unknown severity test',
        'detail' => 'Should default to high → priority 1.',
        'detected_at' => now()->toIso8601String(),
    ]);

    // Unknown severity falls through to High in the existing match()
    // ladder; high maps to priority 1.
    expect($payload['priority'])->toBe(1);
});

it('maps low severity to Pushover priority -1 (quiet)', function (): void {
    $payload = NotificationMessageBuilder::build('system_health_alert', [
        'signal' => 'noise_signal',
        'severity' => 'low',
        'title' => 'Low-severity noise',
        'detail' => 'Should not interrupt.',
        'detected_at' => now()->toIso8601String(),
    ]);

    // 'low' is not in the existing critical/medium/default match, so it
    // currently routes to High. The fix should explicitly recognise
    // 'low' (or any non-critical/high/medium token) and quiet it.
    expect($payload['priority'])->toBe(-1);
});
