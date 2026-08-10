<?php

declare(strict_types=1);

use Kraite\Core\Enums\NotificationLogStatus;
use Kraite\Core\Support\NotificationMessageBuilder;
use Kraite\Core\Support\NotificationService;

/**
 * Regression guards for the 2026-04-26 notification lifecycle sweep:
 *
 * - `NotificationLogStatus` enum centralises status strings previously
 *   scattered as bare literals across listener / observer / webhook
 *   controller / model scopes (silent typo / casing risk).
 * - `NotificationMessageBuilder` fails loud on unknown canonicals
 *   instead of silently shipping a placeholder notification.
 * - `NotificationService::send` swallows the build failure so callers
 *   (observers / jobs / DB listeners) keep working — the failure only
 *   surfaces in logs (Log::error) and the send returns false.
 */
it('NotificationLogStatus exposes the historical string values', function (): void {
    expect(NotificationLogStatus::Delivered->value)->toBe('delivered');
    expect(NotificationLogStatus::Failed->value)->toBe('failed');
    expect(NotificationLogStatus::Opened->value)->toBe('opened');
    expect(NotificationLogStatus::SoftBounced->value)->toBe('soft bounced');
    expect(NotificationLogStatus::HardBounced->value)->toBe('hard bounced');
});

it('NotificationLogStatus.isBounce returns true only for soft and hard bounces', function (): void {
    expect(NotificationLogStatus::SoftBounced->isBounce())->toBeTrue();
    expect(NotificationLogStatus::HardBounced->isBounce())->toBeTrue();
    expect(NotificationLogStatus::Delivered->isBounce())->toBeFalse();
    expect(NotificationLogStatus::Failed->isBounce())->toBeFalse();
    expect(NotificationLogStatus::Opened->isBounce())->toBeFalse();
});

it('NotificationLogStatus.isDeliveredOrOpened classifies recovery statuses', function (): void {
    expect(NotificationLogStatus::Delivered->isDeliveredOrOpened())->toBeTrue();
    expect(NotificationLogStatus::Opened->isDeliveredOrOpened())->toBeTrue();
    expect(NotificationLogStatus::Failed->isDeliveredOrOpened())->toBeFalse();
    expect(NotificationLogStatus::SoftBounced->isDeliveredOrOpened())->toBeFalse();
    expect(NotificationLogStatus::HardBounced->isDeliveredOrOpened())->toBeFalse();
});

it('NotificationMessageBuilder throws InvalidArgumentException on unknown canonicals', function (): void {
    NotificationMessageBuilder::build('this_canonical_does_not_exist_xyz', [], null);
})->throws(InvalidArgumentException::class, 'unknown canonical');

it('describes position drift as alert-only instead of claiming the drift command dispatched a heal', function (): void {
    $message = NotificationMessageBuilder::build('position_drift_detected', [
        'position_id' => 4365,
        'pair' => 'SFPUSDT',
        'direction' => 'LONG',
        'account_name' => 'Binance Account',
        'exchange' => 'binance',
        'pair_status' => 'drift',
        'position_drift_fields' => ['quantity'],
        'order_drifts' => [],
    ]);

    expect($message['emailMessage'])
        ->toContain('alert-only')
        ->not->toContain('has dispatched `PrepareSyncOrdersJob`')
        ->and($message['pushoverMessage'])
        ->not->toContain('sync-orders dispatched');
});

it('builds the exchange-only position alert used by the drift command', function (): void {
    $message = NotificationMessageBuilder::build('position_exchange_only_detected', [
        'pair' => 'GRAMUSDT',
        'direction' => 'SHORT',
        'account_name' => 'Binance Account',
        'exchange' => 'binance',
        'exchange_data' => ['quantity' => '86'],
    ]);

    expect($message['severity'])->toBe(Kraite\Core\Enums\NotificationSeverity::Critical)
        ->and($message['title'])->toBe('Untracked Exchange Position Detected')
        ->and($message['emailMessage'])->toContain('GRAMUSDT', 'SHORT', 'Binance Account')
        ->and($message['pushoverMessage'])->toContain('GRAMUSDT', 'SHORT');
});

it('builds the exchange-only orders alert used by the drift command', function (): void {
    $message = NotificationMessageBuilder::build('orders_exchange_only_detected', [
        'account_name' => 'Binance Account',
        'exchange' => 'binance',
        'orphan_count' => 1,
        'orphans' => [[
            'symbol' => 'GRAMUSDT',
            'type' => 'STOP_MARKET',
            'exchange_order_id' => '4000001819508418',
        ]],
    ]);

    expect($message['severity'])->toBe(Kraite\Core\Enums\NotificationSeverity::Critical)
        ->and($message['title'])->toBe('Untracked Exchange Orders Detected')
        ->and($message['emailMessage'])->toContain('GRAMUSDT', '4000001819508418')
        ->and($message['pushoverMessage'])->toContain('1', 'Binance Account');
});

it('builds the incomplete drift snapshot alert used by the drift command', function (): void {
    $message = NotificationMessageBuilder::build('account_drift_snapshot_failed', [
        'account_name' => 'Binance Account',
        'exchange' => 'binance',
        'api_error' => 'openAlgoOrders unavailable?signature=DO_NOT_EXPOSE',
    ]);

    expect($message['severity'])->toBe(Kraite\Core\Enums\NotificationSeverity::High)
        ->and($message['title'])->toBe('Drift Snapshot Incomplete')
        ->and($message['emailMessage'])->toContain('Binance Account', 'openAlgoOrders unavailable')
        ->and($message['emailMessage'])->not->toContain('DO_NOT_EXPOSE')
        ->and($message['pushoverMessage'])->toContain('Binance Account', 'snapshot');
});

it('NotificationService.flushNotificationCache exists for test isolation', function (): void {
    // The in-process cache prevents a per-call DB hit on hot paths
    // (`ApiRequestLog::saved` event, every position lifecycle step).
    // The flush helper exists so tests can clear state between cases.
    expect(method_exists(NotificationService::class, 'flushNotificationCache'))->toBeTrue();
});
