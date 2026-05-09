<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Trading\Kraite;

/**
 * Pin the Kraite ladder calculator's two contract corners that the
 * dispatcher leans on:
 *
 *   1. N=0 → empty ladder. Simple-trade mode (1 MARKET + 1 TP + 1 SL,
 *      no DCA rungs) MUST be representable. A regression that rejects
 *      N=0 with InvalidArgumentException ships as DispatchLimitOrdersJob
 *      throwing on every simple-trade position.
 *
 *   2. The ladder's price geometry — for LONG, rungs go BELOW the
 *      reference price (cheaper buys averaging down); for SHORT, rungs
 *      go ABOVE. A regression that flips the sign produces a ladder
 *      that buys higher on a long position and shorts lower — i.e.
 *      ladder fills mid-loss, the opposite of the martingale recovery
 *      strategy.
 *
 *   3. Ladder length matches N exactly. A regression that off-by-ones
 *      (e.g., loops `for $i = 0; $i < N` but should be `1..N`) ships as
 *      ActivatePositionJob's count check refusing to activate every
 *      position because the order count is short.
 */
function buildLadderSymbol(): ExchangeSymbol
{
    $token = 'LDR'.mb_strtoupper(Str::random(4));

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'percentage_gap_long' => '5',
        'percentage_gap_short' => '5',
        'limit_quantity_multipliers' => [2, 2, 2, 2],
    ]);
}

it('returns an empty ladder for N=0 (simple-trade mode contract)', function (): void {
    $symbol = buildLadderSymbol();

    $ladder = Kraite::calculateLimitOrdersData(
        totalLimitOrders: 0,
        direction: 'LONG',
        referencePrice: '100',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );

    expect($ladder)->toBe([]);
});

it('returns an empty ladder for negative N (defensive)', function (): void {
    $symbol = buildLadderSymbol();

    $ladder = Kraite::calculateLimitOrdersData(
        totalLimitOrders: -3,
        direction: 'LONG',
        referencePrice: '100',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );

    expect($ladder)->toBe([]);
});

it('returns N rungs for a LONG ladder', function (): void {
    $symbol = buildLadderSymbol();

    $ladder = Kraite::calculateLimitOrdersData(
        totalLimitOrders: 4,
        direction: 'LONG',
        referencePrice: '100',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );

    expect($ladder)->toHaveCount(4);
});

it('LONG rung prices are BELOW the reference price (averaging down)', function (): void {
    $symbol = buildLadderSymbol();

    $ladder = Kraite::calculateLimitOrdersData(
        totalLimitOrders: 4,
        direction: 'LONG',
        referencePrice: '100',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );

    foreach ($ladder as $rung) {
        expect((float) $rung['price'])->toBeLessThan(100.0);
    }
});

it('SHORT rung prices are ABOVE the reference price (averaging up)', function (): void {
    $symbol = buildLadderSymbol();

    $ladder = Kraite::calculateLimitOrdersData(
        totalLimitOrders: 4,
        direction: 'SHORT',
        referencePrice: '100',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );

    foreach ($ladder as $rung) {
        expect((float) $rung['price'])->toBeGreaterThan(100.0);
    }
});

it('LONG rung prices are strictly DECREASING (rung 1 closest to ref, rung N farthest)', function (): void {
    $symbol = buildLadderSymbol();

    $ladder = Kraite::calculateLimitOrdersData(
        totalLimitOrders: 4,
        direction: 'LONG',
        referencePrice: '100',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );

    $prices = array_map(fn ($r) => (float) $r['price'], $ladder);
    $sortedDesc = $prices;
    rsort($sortedDesc);

    expect($prices)->toBe($sortedDesc);
});

it('SHORT rung prices are strictly INCREASING (rung 1 closest to ref, rung N farthest)', function (): void {
    $symbol = buildLadderSymbol();

    $ladder = Kraite::calculateLimitOrdersData(
        totalLimitOrders: 4,
        direction: 'SHORT',
        referencePrice: '100',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );

    $prices = array_map(fn ($r) => (float) $r['price'], $ladder);
    $sortedAsc = $prices;
    sort($sortedAsc);

    expect($prices)->toBe($sortedAsc);
});

it('throws InvalidArgumentException for an invalid direction', function (): void {
    $symbol = buildLadderSymbol();

    Kraite::calculateLimitOrdersData(
        totalLimitOrders: 4,
        direction: 'SIDEWAYS',
        referencePrice: '100',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );
})->throws(InvalidArgumentException::class);

it('throws InvalidArgumentException for a zero or negative reference price', function (): void {
    $symbol = buildLadderSymbol();

    Kraite::calculateLimitOrdersData(
        totalLimitOrders: 4,
        direction: 'LONG',
        referencePrice: '0',
        marketOrderQty: '10',
        exchangeSymbol: $symbol,
    );
})->throws(InvalidArgumentException::class);
