<?php

declare(strict_types=1);

use Kraite\Core\Enums\NotificationLogStatus;
use Kraite\Core\Models\NotificationLog;

/**
 * Pin NotificationLog scope filters + the enum's classification helpers.
 *
 * The enum's string values are intentionally non-canonical ('soft bounced'
 * with a SPACE, not 'soft_bounced') because old production rows already
 * carry that literal. A regression that snake-cases the enum value
 * silently splits the audit trail in two — half the rows match scope
 * queries, half don't. The tests below pin the literal values so a
 * "let's normalise these" refactor explodes the suite first.
 */
it('NotificationLogStatus carries human-readable strings (NOT snake_case) for audit-trail compatibility', function (): void {
    expect(NotificationLogStatus::Delivered->value)->toBe('delivered')
        ->and(NotificationLogStatus::Failed->value)->toBe('failed')
        ->and(NotificationLogStatus::Opened->value)->toBe('opened')
        ->and(NotificationLogStatus::SoftBounced->value)->toBe('soft bounced')
        ->and(NotificationLogStatus::HardBounced->value)->toBe('hard bounced');
});

it('isBounce(): true only for SoftBounced and HardBounced', function (): void {
    expect(NotificationLogStatus::SoftBounced->isBounce())->toBeTrue()
        ->and(NotificationLogStatus::HardBounced->isBounce())->toBeTrue()
        ->and(NotificationLogStatus::Delivered->isBounce())->toBeFalse()
        ->and(NotificationLogStatus::Failed->isBounce())->toBeFalse()
        ->and(NotificationLogStatus::Opened->isBounce())->toBeFalse();
});

it('isDeliveredOrOpened(): true for Delivered and Opened', function (): void {
    expect(NotificationLogStatus::Delivered->isDeliveredOrOpened())->toBeTrue()
        ->and(NotificationLogStatus::Opened->isDeliveredOrOpened())->toBeTrue()
        ->and(NotificationLogStatus::SoftBounced->isDeliveredOrOpened())->toBeFalse()
        ->and(NotificationLogStatus::HardBounced->isDeliveredOrOpened())->toBeFalse()
        ->and(NotificationLogStatus::Failed->isDeliveredOrOpened())->toBeFalse();
});

it('failed() scope filters status=failed', function (): void {
    NotificationLog::factory()->create(['status' => NotificationLogStatus::Failed->value]);
    NotificationLog::factory()->create(['status' => NotificationLogStatus::Delivered->value]);

    expect(NotificationLog::failed()->count())->toBe(1);
});

it('delivered() scope filters status=delivered', function (): void {
    NotificationLog::factory()->create(['status' => NotificationLogStatus::Delivered->value]);
    NotificationLog::factory()->create(['status' => NotificationLogStatus::Failed->value]);

    expect(NotificationLog::delivered()->count())->toBe(1);
});

it('byChannel() scope filters by channel column', function (): void {
    NotificationLog::factory()->create(['channel' => 'email']);
    NotificationLog::factory()->create(['channel' => 'telegram']);

    expect(NotificationLog::byChannel('email')->count())->toBe(1)
        ->and(NotificationLog::byChannel('telegram')->count())->toBe(1);
});

it('byStatus() scope filters by status column (works with bounce values containing spaces)', function (): void {
    NotificationLog::factory()->create(['status' => NotificationLogStatus::SoftBounced->value]);
    NotificationLog::factory()->create(['status' => NotificationLogStatus::HardBounced->value]);

    expect(NotificationLog::byStatus('soft bounced')->count())->toBe(1)
        ->and(NotificationLog::byStatus('hard bounced')->count())->toBe(1);
});

it('byCanonical() scope filters by canonical column', function (): void {
    NotificationLog::factory()->create(['canonical' => 'position-opened']);
    NotificationLog::factory()->create(['canonical' => 'position-closed']);

    expect(NotificationLog::byCanonical('position-opened')->count())->toBe(1);
});
