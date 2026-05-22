<?php

declare(strict_types=1);

use Kraite\Core\Models\AppLog;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;

beforeEach(function (): void {
    AppLog::enable();
});

it('creates an app log entry with correct polymorphic relationship', function (): void {
    $position = Position::factory()->create();

    $log = $position->appLog(
        event: 'market_order_placed',
        message: 'Market order placed — 0.5 BTCUSDT at $50000',
        metadata: ['order_id' => 1, 'price' => '50000']
    );

    expect($log)->toBeInstanceOf(AppLog::class);
    expect($log->loggable_type)->toBe(Position::class);
    expect($log->loggable_id)->toBe($position->id);
    expect($log->event)->toBe('market_order_placed');
    expect($log->message)->toBe('Market order placed — 0.5 BTCUSDT at $50000');
    expect($log->loggable)->toBeInstanceOf(Position::class);
    expect($log->loggable->id)->toBe($position->id);
});

it('defaults severity to info', function (): void {
    $position = Position::factory()->create();

    $log = $position->appLog(
        event: 'position_activated',
        message: 'Position activated — all orders confirmed'
    );

    expect($log->severity)->toBe('info');
});

it('stores metadata as JSON', function (): void {
    $position = Position::factory()->create();
    $metadata = ['order_id' => 42, 'price' => '2450.50', 'quantity' => '0.1'];

    $log = $position->appLog(
        event: 'market_order_placed',
        message: 'Market order placed',
        metadata: $metadata
    );

    // Re-fetch from database to verify JSON round-trip. MySQL's JSON type
    // doesn't guarantee key order on retrieval, so compare canonically
    // (same keys, same values) rather than by literal array structure.
    $freshLog = AppLog::find($log->id);
    $actual = $freshLog->metadata;
    ksort($actual);
    $expected = $metadata;
    ksort($expected);
    expect($actual)->toBe($expected);
});

it('prevents logging when disabled', function (): void {
    AppLog::disable();
    expect(AppLog::isEnabled())->toBeFalse();

    $position = Position::factory()->create();

    $result = $position->appLog(
        event: 'position_activated',
        message: 'Position activated'
    );

    expect($result)->toBeNull();
    expect(AppLog::where('loggable_id', $position->id)->count())->toBe(0);

    // Re-enable and verify it works again
    AppLog::enable();
    expect(AppLog::isEnabled())->toBeTrue();

    $log = $position->appLog(
        event: 'position_activated',
        message: 'Position activated'
    );

    expect($log)->toBeInstanceOf(AppLog::class);
});

it('does not create recursive logs when AppLog is modified', function (): void {
    $position = Position::factory()->create();

    $log = $position->appLog(
        event: 'test_event',
        message: 'Test message'
    );

    $countBefore = AppLog::count();

    // AppLog extends Model (not BaseModel), so no observer triggers
    $log->update(['message' => 'Updated message']);

    expect(AppLog::count())->toBe($countBefore);
});

it('accepts custom severity levels', function (): void {
    $position = Position::factory()->create();

    $warningLog = $position->appLog(
        event: 'order_rejected',
        message: 'LIMIT order rejected',
        severity: 'warning'
    );

    $criticalLog = $position->appLog(
        event: 'position_error',
        message: 'Critical failure',
        severity: 'critical'
    );

    expect($warningLog->severity)->toBe('warning');
    expect($criticalLog->severity)->toBe('critical');
});

it('works with different model types', function (): void {
    $position = Position::factory()->create();
    $exchangeSymbol = ExchangeSymbol::factory()->create();

    $positionLog = $position->appLog(event: 'position_activated', message: 'Activated');
    $symbolLog = $exchangeSymbol->appLog(event: 'symbol_event', message: 'Something happened');

    expect($positionLog->loggable_type)->toBe(Position::class);
    expect($symbolLog->loggable_type)->toBe(ExchangeSymbol::class);
});
