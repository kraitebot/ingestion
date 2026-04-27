<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Trading\Kraite;

/**
 * The BSCS cooldown is a global gate that flows through
 * `HasTradingGuards::canOpenPositions()`. When BlackSwanIndex says
 * shouldBlockOpens=true, every direction-specific guard
 * (canOpenLongs / canOpenShorts) and the broader `canOpenNewPositions`
 * cascade through canOpenPositions and inherit the block.
 *
 * Wiring contract:
 *
 *   1. allow_opening_positions=false  → false (existing behaviour, unchanged)
 *   2. cooldown_until in the future   → false (BSCS gate active)
 *   3. override_until in the future   → true  (operator escape hatch wins)
 *   4. cooldown_until in the past
 *      AND override_until null/past  → true  (no block, opens allowed)
 *   5. no kraite row at all           → false (existing edge case, unchanged)
 */
function makeEngineForGate(): Kraite
{
    // Engine ctor requires an Account; the canOpenPositions guard
    // doesn't read it (global gate). Bypass the ctor to keep the test
    // focused on the guard contract.
    return (new ReflectionClass(Kraite::class))->newInstanceWithoutConstructor();
}

function setKraiteForGateTest(bool $allowOpens, ?CarbonImmutable $cooldown = null, ?CarbonImmutable $override = null): KraiteModel
{
    $kraite = KraiteModel::find(1);
    $kraite->updateSaving([
        'allow_opening_positions' => $allowOpens,
        'bscs_cooldown_until' => $cooldown,
        'bscs_override_until' => $override,
    ]);

    return $kraite;
}

it('allows opens when no BSCS cooldown is set', function (): void {
    setKraiteForGateTest(allowOpens: true);

    expect(makeEngineForGate()->canOpenPositions())->toBeTrue();
});

it('blocks opens when BSCS cooldown is in the future', function (): void {
    setKraiteForGateTest(
        allowOpens: true,
        cooldown: CarbonImmutable::now()->addHours(20),
    );

    expect(makeEngineForGate()->canOpenPositions())->toBeFalse();
});

it('still respects allow_opening_positions=false even without a cooldown', function (): void {
    setKraiteForGateTest(allowOpens: false);

    expect(makeEngineForGate()->canOpenPositions())->toBeFalse();
});

it('lets operator override bypass an active BSCS cooldown', function (): void {
    setKraiteForGateTest(
        allowOpens: true,
        cooldown: CarbonImmutable::now()->addHours(20),
        override: CarbonImmutable::now()->addHours(2),
    );

    expect(makeEngineForGate()->canOpenPositions())->toBeTrue();
});

it('opens resume once the BSCS cooldown timestamp is in the past', function (): void {
    setKraiteForGateTest(
        allowOpens: true,
        cooldown: CarbonImmutable::now()->subHour(),
    );

    expect(makeEngineForGate()->canOpenPositions())->toBeTrue();
});
