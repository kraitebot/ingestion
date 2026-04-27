<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\PlaceStopLossOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;

/**
 * Defensive fallback when the position's snapshot of `stop_market_percentage`
 * is null. Hard-failing on null breaks two scenarios:
 *
 *   1. Positions opened BEFORE the snapshot column existed.
 *   2. Half-baked deploys where an old `PreparePositionDataJob` ran (no
 *      snapshot) but the new `PlaceStopLossOrderJob` requires it.
 *
 * The fix: when snapshot is null, live-resolve via `TpSlResolver` from the
 * exchange symbol + account (same resolver `PrepareJob` uses, just deferred
 * to placement time). The snapshot remains the canonical source — this is
 * a safety net, not a behaviour change for the happy path.
 *
 * Tested via reflection-built instances so the fallback resolver can be
 * exercised without a full Position + ApiSnapshot + Step setup.
 */
function newPlaceStopLossJobForResolveTest(): PlaceStopLossOrderJob
{
    return (new ReflectionClass(PlaceStopLossOrderJob::class))
        ->newInstanceWithoutConstructor();
}

function makePositionStub(?string $snapshotValue, string $accountSlPct, ?string $symbolSlValue = null, bool $overrideSl = false): Position
{
    $exchangeSymbol = new ExchangeSymbol;
    $exchangeSymbol->stop_market_percentage = $symbolSlValue;

    $account = new Account;
    $account->stop_market_initial_percentage = $accountSlPct;
    $account->override_sl = $overrideSl;

    $position = new Position;
    $position->stop_market_percentage = $snapshotValue;
    $position->setRelation('exchangeSymbol', $exchangeSymbol);
    $position->setRelation('account', $account);

    return $position;
}

it('resolves to the snapshot value when present (happy path)', function (): void {
    $job = newPlaceStopLossJobForResolveTest();
    $job->position = makePositionStub(snapshotValue: '2.50', accountSlPct: '5.00');

    expect($job->resolveStopLossPercentage())->toBe('2.50');
});

it('falls back to account when snapshot is null and symbol value is null', function (): void {
    // Pre-snapshot positions and half-baked deploys hit this path —
    // both should still place SL using the account default.
    $job = newPlaceStopLossJobForResolveTest();
    $job->position = makePositionStub(snapshotValue: null, accountSlPct: '5.00');

    expect($job->resolveStopLossPercentage())->toBe('5.00');
});

it('falls back through TpSlResolver respecting symbol value when override is false', function (): void {
    $job = newPlaceStopLossJobForResolveTest();
    $job->position = makePositionStub(
        snapshotValue: null,
        accountSlPct: '5.00',
        symbolSlValue: '3.00',
        overrideSl: false,
    );

    expect($job->resolveStopLossPercentage())->toBe('3.00');
});

it('falls back through TpSlResolver forcing account value when override is true', function (): void {
    $job = newPlaceStopLossJobForResolveTest();
    $job->position = makePositionStub(
        snapshotValue: null,
        accountSlPct: '5.00',
        symbolSlValue: '3.00',
        overrideSl: true,
    );

    expect($job->resolveStopLossPercentage())->toBe('5.00');
});

it('returns null only when snapshot and account value are both missing', function (): void {
    // A genuinely unconfigured account — placement still fails fast,
    // matching the original startOrFail guard semantics.
    $job = newPlaceStopLossJobForResolveTest();

    $exchangeSymbol = new ExchangeSymbol;

    $account = new Account;
    $account->stop_market_initial_percentage = null;

    $position = new Position;
    $position->stop_market_percentage = null;
    $position->setRelation('exchangeSymbol', $exchangeSymbol);
    $position->setRelation('account', $account);

    $job->position = $position;

    expect($job->resolveStopLossPercentage())->toBeNull();
});

it('treats empty-string snapshot as missing and falls back', function (): void {
    // Defensive — DB driver shouldn't return empty string on a nullable
    // decimal, but if it does we treat it as no-value so PlaceJob doesn't
    // attempt an empty stopPercent.
    $job = newPlaceStopLossJobForResolveTest();
    $job->position = makePositionStub(snapshotValue: '', accountSlPct: '5.00');

    expect($job->resolveStopLossPercentage())->toBe('5.00');
});
