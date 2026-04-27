<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\MarketRegime\DirectionalBookRisk;

/**
 * DirectionalBookRisk — portfolio shape DTO. Reads OPEN positions
 * across all accounts and computes which side of the book carries
 * more risk. Consumed by:
 *
 *   - `BlackSwanIndex::portfolioRisk()` for dashboard rendering
 *   - Phase 2.1C's directional crowding multiplier in
 *     `PreparePositionDataJob` (downscales the crowded side only)
 *
 * Risk is measured by **margin × leverage** (notional exposure), not
 * by raw position count. A single 50× leveraged LONG outweighs five
 * 1× SHORT positions on the risk axis.
 *
 * Position scope: uses the existing `Position::opened()` scope —
 * statuses `[opening, waping, active, new, closing, cancelling, syncing]`.
 * Closed / cancelled / failed positions are out by definition.
 */
function makeOpenPositionForRisk(string $direction, string $margin, int $leverage): Position
{
    $account = Account::factory()->create();

    return Position::factory()->create([
        'account_id' => $account->id,
        'direction' => $direction,
        'status' => 'active',
        'margin' => $margin,
        'leverage' => $leverage,
    ]);
}

it('reports zero exposure when no positions are open', function (): void {
    $risk = DirectionalBookRisk::current();

    expect($risk->longOpenCount())->toBe(0)
        ->and($risk->shortOpenCount())->toBe(0)
        ->and($risk->longMarginAtRisk())->toBe('0')
        ->and($risk->shortMarginAtRisk())->toBe('0')
        ->and($risk->largestSide())->toBe('BALANCED')
        ->and($risk->largestSideRatio())->toBe(0.5)
        ->and($risk->netDirectionalBias())->toBe(0.0);
});

it('counts open LONG and SHORT positions separately', function (): void {
    makeOpenPositionForRisk('LONG', '100.00', 10);
    makeOpenPositionForRisk('LONG', '50.00', 10);
    makeOpenPositionForRisk('SHORT', '75.00', 10);

    $risk = DirectionalBookRisk::current();

    expect($risk->longOpenCount())->toBe(2)
        ->and($risk->shortOpenCount())->toBe(1);
});

it('computes margin-at-risk as margin × leverage (notional exposure)', function (): void {
    makeOpenPositionForRisk('LONG', '100.00', 20);
    makeOpenPositionForRisk('SHORT', '50.00', 10);

    $risk = DirectionalBookRisk::current();

    // LONG:  100 × 20 = 2000
    // SHORT: 50  × 10 = 500
    expect((float) $risk->longMarginAtRisk())->toBe(2000.0)
        ->and((float) $risk->shortMarginAtRisk())->toBe(500.0);
});

it('flags LONG side as largest when long margin-at-risk dominates', function (): void {
    makeOpenPositionForRisk('LONG', '100.00', 20);
    makeOpenPositionForRisk('LONG', '100.00', 20);
    makeOpenPositionForRisk('SHORT', '50.00', 10);

    $risk = DirectionalBookRisk::current();

    // LONG total notional: 4000. SHORT: 500. Total: 4500. Ratio: 4000/4500 ≈ 0.889.
    expect($risk->largestSide())->toBe('LONG')
        ->and(round($risk->largestSideRatio(), 3))->toBe(0.889);
});

it('flags SHORT side as largest when short margin-at-risk dominates', function (): void {
    makeOpenPositionForRisk('LONG', '50.00', 5);
    makeOpenPositionForRisk('SHORT', '100.00', 20);

    $risk = DirectionalBookRisk::current();

    // LONG: 250. SHORT: 2000. Total: 2250. Ratio: 2000/2250 ≈ 0.889.
    expect($risk->largestSide())->toBe('SHORT')
        ->and(round($risk->largestSideRatio(), 3))->toBe(0.889);
});

it('reports BALANCED when long and short margin-at-risk are equal', function (): void {
    makeOpenPositionForRisk('LONG', '100.00', 10);
    makeOpenPositionForRisk('SHORT', '100.00', 10);

    $risk = DirectionalBookRisk::current();

    expect($risk->largestSide())->toBe('BALANCED')
        ->and($risk->largestSideRatio())->toBe(0.5);
});

it('signed net_directional_bias: +1 = all LONG, -1 = all SHORT, 0 = balanced', function (): void {
    makeOpenPositionForRisk('LONG', '100.00', 10);

    $risk = DirectionalBookRisk::current();
    expect($risk->netDirectionalBias())->toBe(1.0);

    // Add an equal SHORT — bias goes back to 0.
    makeOpenPositionForRisk('SHORT', '100.00', 10);
    $risk = DirectionalBookRisk::current();
    expect($risk->netDirectionalBias())->toBe(0.0);
});

it('ignores closed positions (status NOT in the opened set)', function (): void {
    makeOpenPositionForRisk('LONG', '100.00', 10);
    Position::factory()->create([
        'account_id' => Account::factory()->create()->id,
        'direction' => 'LONG',
        'status' => 'closed',
        'margin' => '5000',
        'leverage' => 50,
    ]);

    $risk = DirectionalBookRisk::current();

    // Only the open position is counted (margin × lev = 1000).
    // Closed position with massive notional is excluded.
    expect($risk->longOpenCount())->toBe(1)
        ->and((float) $risk->longMarginAtRisk())->toBe(1000.0);
});

it('toArray exposes a flat dashboard payload', function (): void {
    makeOpenPositionForRisk('LONG', '100.00', 20);
    makeOpenPositionForRisk('SHORT', '50.00', 10);

    $payload = DirectionalBookRisk::current()->toArray();

    expect($payload)->toHaveKey('long_open_count', 1)
        ->and($payload)->toHaveKey('short_open_count', 1)
        ->and($payload)->toHaveKey('long_margin_at_risk')
        ->and($payload)->toHaveKey('short_margin_at_risk')
        ->and($payload)->toHaveKey('largest_side', 'LONG')
        ->and($payload)->toHaveKey('largest_side_ratio')
        ->and($payload)->toHaveKey('net_directional_bias');
});
