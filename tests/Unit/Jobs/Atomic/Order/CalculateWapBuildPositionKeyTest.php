<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;

/**
 * Regression guard for a silent-WAP-failure bug discovered 2026-04-27 on
 * Karine Esnault / Binance Only Account (id 5, on_hedge_mode=0).
 *
 * What broke: `buildPositionKey()` always returned `"<symbol>:<direction>"`
 * (e.g. "JTOUSDT:LONG"), but Binance returns one-way-mode position keys
 * as `<symbol>:BOTH` (per `MapsPositionsQuery::resolveQueryPositionsResponse`,
 * which keys by `position['positionSide'] ?? 'BOTH'`). The lookup in
 * `CalculateWapAndModifyProfitOrderJob::computeApiable()` failed on every
 * one-way account, throwing NonNotifiableException and silently aborting
 * WAP. The position was left with stale TP at original opening price
 * even after limit orders filled — would only sell the original market
 * quantity if TP triggered, leaving the WAP'd quantity stranded.
 *
 * Hedge-mode accounts (the default for accounts 1, 4) were unaffected
 * because their snapshot keys ARE `<symbol>:LONG` / `<symbol>:SHORT`.
 *
 * Fix: `buildPositionKey()` now honours `account.on_hedge_mode`:
 *   - hedge mode (true)  → "JTOUSDT:LONG" / "JTOUSDT:SHORT"
 *   - one-way mode (false) → "JTOUSDT:BOTH"
 */
function newWapJobForKeyTest(Position $position): CalculateWapAndModifyProfitOrderJob
{
    // Bypass the constructor — only `$this->position` matters for the
    // key build, and we don't want a DB lookup for a fixture position.
    $job = (new ReflectionClass(CalculateWapAndModifyProfitOrderJob::class))
        ->newInstanceWithoutConstructor();

    $reflection = new ReflectionObject($job);
    $property = $reflection->getProperty('position');
    $property->setAccessible(true);
    $property->setValue($job, $position);

    return $job;
}

function makeStubPosition(string $direction, bool $hedgeMode, string $symbol = 'JTOUSDT'): Position
{
    $account = new Account;
    $account->on_hedge_mode = $hedgeMode;

    $position = new Position;
    $position->direction = $direction;
    $position->parsed_trading_pair = $symbol;
    $position->setRelation('account', $account);

    return $position;
}

it('builds <symbol>:LONG key for a hedge-mode LONG position', function (): void {
    $job = newWapJobForKeyTest(makeStubPosition('LONG', hedgeMode: true));

    $reflection = new ReflectionMethod($job, 'buildPositionKey');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job))->toBe('JTOUSDT:LONG');
});

it('builds <symbol>:SHORT key for a hedge-mode SHORT position', function (): void {
    $job = newWapJobForKeyTest(makeStubPosition('SHORT', hedgeMode: true));

    $reflection = new ReflectionMethod($job, 'buildPositionKey');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job))->toBe('JTOUSDT:SHORT');
});

it('builds <symbol>:BOTH key for a one-way-mode LONG position', function (): void {
    // The bug: previously returned "JTOUSDT:LONG" — never matched the
    // snapshot's "JTOUSDT:BOTH" key, WAP silently aborted. Fix must
    // produce the BOTH key regardless of position direction when the
    // account is in one-way mode.
    $job = newWapJobForKeyTest(makeStubPosition('LONG', hedgeMode: false));

    $reflection = new ReflectionMethod($job, 'buildPositionKey');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job))->toBe('JTOUSDT:BOTH');
});

it('builds <symbol>:BOTH key for a one-way-mode SHORT position', function (): void {
    // One-way mode is direction-agnostic — Binance stores both LONG and
    // SHORT intent under the same `positionSide=BOTH` slot. Same key
    // shape regardless of position direction.
    $job = newWapJobForKeyTest(makeStubPosition('SHORT', hedgeMode: false));

    $reflection = new ReflectionMethod($job, 'buildPositionKey');
    $reflection->setAccessible(true);

    expect($reflection->invoke($job))->toBe('JTOUSDT:BOTH');
});

// --- Variant inheritance pinning ----------------------------------------
//
// Both exchange-specific subclasses (Binance, Bitget) extend the base
// `CalculateWapAndModifyProfitOrderJob` and inherit `buildPositionKey()`.
// These tests pin that the inheritance chain produces the right key
// regardless of which `compute_apiable` body executes.

it('Binance variant: one-way LONG produces JTOUSDT:BOTH (inherits base fix)', function (): void {
    $reflection = new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\Binance\CalculateWapAndModifyProfitOrderJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $position = makeStubPosition('LONG', hedgeMode: false);

    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $method = new ReflectionMethod($job, 'buildPositionKey');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('JTOUSDT:BOTH');
});

it('Binance variant: hedge LONG produces JTOUSDT:LONG (unchanged)', function (): void {
    $reflection = new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\Binance\CalculateWapAndModifyProfitOrderJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $position = makeStubPosition('LONG', hedgeMode: true);

    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $method = new ReflectionMethod($job, 'buildPositionKey');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('JTOUSDT:LONG');
});

it('Bitget variant: one-way SHORT produces JTOUSDT:BOTH (inherits base fix)', function (): void {
    $reflection = new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\Bitget\CalculateWapAndModifyProfitOrderJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $position = makeStubPosition('SHORT', hedgeMode: false);

    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $method = new ReflectionMethod($job, 'buildPositionKey');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('JTOUSDT:BOTH');
});

it('Bitget variant: hedge SHORT produces JTOUSDT:SHORT (unchanged)', function (): void {
    // Bitget's MapsPositionsQuery keys hedge-mode rows by SYMBOL:SHORT
    // when holdSide='short'. The WAP lookup must match exactly.
    $reflection = new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\Bitget\CalculateWapAndModifyProfitOrderJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    $position = makeStubPosition('SHORT', hedgeMode: true);

    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $method = new ReflectionMethod($job, 'buildPositionKey');
    $method->setAccessible(true);

    expect($method->invoke($job))->toBe('JTOUSDT:SHORT');
});
