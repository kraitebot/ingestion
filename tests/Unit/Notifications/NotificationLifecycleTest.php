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

it('NotificationService.flushNotificationCache exists for test isolation', function (): void {
    // The in-process cache prevents a per-call DB hit on hot paths
    // (`ApiRequestLog::saved` event, every position lifecycle step).
    // The flush helper exists so tests can clear state between cases.
    expect(method_exists(NotificationService::class, 'flushNotificationCache'))->toBeTrue();
});
