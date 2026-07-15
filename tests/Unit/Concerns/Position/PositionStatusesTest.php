<?php

declare(strict_types=1);

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;

/**
 * Pin the Position status-transition contract used by every lifecycle
 * step. Each `updateTo*()` method is the canonical writer for the
 * named state, and several carry side-effects (closed_at stamp,
 * error_message clearing, optional message arg). A regression that
 * drops one of these side-effects ships as positions stuck in their
 * pre-transition state — closed_at unset, error_message lingering,
 * or — worst — `failed` rows that never get their notification fired.
 */
it('isActive returns true for non-terminal statuses', function (string $status): void {
    $position = Position::factory()->long()->create(['status' => $status]);
    expect($position->isActive())->toBeTrue();
})->with([
    'opening' => ['opening'],
    'active' => ['active'],
    'syncing' => ['syncing'],
    'waping' => ['waping'],
    'closing' => ['closing'],
    'cancelling' => ['cancelling'],
    'new' => ['new'],
    'watching' => ['watching'],
    'failed' => ['failed'],
]);

it('isActive returns false for terminal statuses (closed and cancelled)', function (string $terminal): void {
    $position = Position::factory()->long()->create(['status' => $terminal]);
    expect($position->isActive())->toBeFalse();
})->with([
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
]);

it('isClosing strictly returns true only for status=closing', function (): void {
    $closing = Position::factory()->long()->create(['status' => 'closing']);
    $active = Position::factory()->long()->create(['status' => 'active']);
    expect($closing->isClosing())->toBeTrue()
        ->and($active->isClosing())->toBeFalse();
});

it('isCancelled strictly returns true only for status=cancelled', function (): void {
    $cancelled = Position::factory()->long()->create(['status' => 'cancelled']);
    $cancelling = Position::factory()->long()->create(['status' => 'cancelling']);
    expect($cancelled->isCancelled())->toBeTrue()
        ->and($cancelling->isCancelled())->toBeFalse();
});

// ───────────────────────── transition writers ─────────────────────────

it('updateToWatching sets status to watching', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $position->updateToWatching();
    expect($position->fresh()->status)->toBe('watching');
});

it('updateToWaping sets status to waping', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $position->updateToWaping();
    expect($position->fresh()->status)->toBe('waping');
});

it('updateToOpening sets status to opening', function (): void {
    $position = Position::factory()->long()->create(['status' => 'new']);
    $position->updateToOpening();
    expect($position->fresh()->status)->toBe('opening');
});

it('updateToCancelling sets status to cancelling', function (): void {
    $position = Position::factory()->long()->create(['status' => 'opening']);
    $position->updateToCancelling();
    expect($position->fresh()->status)->toBe('cancelling');
});

it('updateToActive sets status to active', function (): void {
    $position = Position::factory()->long()->create(['status' => 'syncing']);
    $position->updateToActive();
    expect($position->fresh()->status)->toBe('active');
});

it('updateToSyncing sets status to syncing', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $position->updateToSyncing();
    expect($position->fresh()->status)->toBe('syncing');
});

it('updateToClosing sets status to closing', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);
    $position->updateToClosing();
    expect($position->fresh()->status)->toBe('closing');
});

// ───────────────────────── updateToClosed: side effects ─────────────────────────

it('updateToClosed sets status, stamps closed_at, and clears error_message', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'closing',
        'closed_at' => null,
        'error_message' => 'previous-error',
    ]);

    $position->updateToClosed();

    $fresh = $position->fresh();
    expect($fresh->status)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and($fresh->error_message)->toBeNull();
});

// ───────────────────────── updateToCancelled: side effects ─────────────────────────

it('updateToCancelled stamps closed_at when the position had been opened', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'cancelling',
        'opened_at' => now()->subMinute(),
        'closed_at' => null,
    ]);

    $position->updateToCancelled();

    $fresh = $position->fresh();
    expect($fresh->status)->toBe('cancelled')
        ->and($fresh->closed_at)->not->toBeNull();
});

it('updateToCancelled does NOT stamp closed_at when the position never opened (rejected pre-fill)', function (): void {
    // A position that never reached the exchange has opened_at=null —
    // stamping closed_at on those rows would falsely imply lifetime.
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'opened_at' => null,
        'closed_at' => null,
    ]);

    $position->updateToCancelled('rejected before reaching exchange');

    $fresh = $position->fresh();
    expect($fresh->status)->toBe('cancelled')
        ->and($fresh->closed_at)->toBeNull()
        ->and($fresh->error_message)->toBe('rejected before reaching exchange');
});

it('updateToCancelled persists the error_message when provided', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'cancelling',
        'opened_at' => now()->subMinute(),
    ]);

    $position->updateToCancelled('cancel-flow ran');

    expect($position->fresh()->error_message)->toBe('cancel-flow ran');
});

// ───────────────────────── updateToFailed: side effects ─────────────────────────

it('updateToFailed stamps closed_at when the position had been opened', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'opened_at' => now()->subMinute(),
        'closed_at' => null,
    ]);

    $position->updateToFailed('placement rejected');

    $fresh = $position->fresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and($fresh->error_message)->toBe('placement rejected');
});

it('updateToFailed does NOT stamp closed_at when the position never opened', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'opening',
        'opened_at' => null,
    ]);

    $position->updateToFailed('rejected before fill');

    expect($position->fresh()->closed_at)->toBeNull();
});

it('opening failure applies an automatic selection block without changing the sysadmin flag', function (): void {
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'is_manually_enabled' => true,
        'system_disabled_at' => null,
        'system_disabled_reason' => null,
    ]);
    $position = Position::factory()->long()->create([
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
    ]);

    $position->updateToFailed('placement rejected');

    $exchangeSymbol->refresh();

    expect($exchangeSymbol->is_manually_enabled)->toBeTrue()
        ->and($exchangeSymbol->system_disabled_at->isSameSecond(now()))->toBeTrue()
        ->and($exchangeSymbol->system_disabled_reason)->toBe('position_opening_failed')
        ->and($exchangeSymbol->isTradeable())->toBeFalse();
});

it('updateToFailed re-entry does NOT re-stamp side effects on an already-failed row (idempotent message bump)', function (): void {
    // Side effects (notification + symbol disable) fire only on the
    // *transition* into failed. Re-entries via retried sync paths must
    // not double-ping the operator. The status flip + closed_at +
    // error_message are persisted regardless.
    $position = Position::factory()->long()->create([
        'status' => 'failed',
        'opened_at' => now()->subMinute(),
        'closed_at' => now()->subSecond(),
        'error_message' => 'first failure',
    ]);

    $position->updateToFailed('second attempt at writing the same failure');

    $fresh = $position->fresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and($fresh->error_message)->toBe('second attempt at writing the same failure');
});
