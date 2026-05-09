<?php

declare(strict_types=1);

use Kraite\Core\Trading\Kraite;

/**
 * Pin the financial primitives in HasPnLCalculations. These math
 * routines power TP price math, WAP recalculation after DCA fills,
 * and the SL-loss prediction. A regression here ships either as
 * mispriced TP / SL on the exchange (mispnl-on-fill) or as a wrong
 * sign on a SHORT (the TP placed UP instead of DOWN — guarantees
 * negative-PnL exits the moment the trade ticks the wrong way).
 */
it('calculatePnL: LONG yields zero pnl when fills match (Q0=Q1, P0=P1)', function (): void {
    $result = Kraite::calculatePnL('LONG', '10', '100', '10', '100');

    expect((float) $result['cum_qty'])->toBe(20.0)
        ->and((float) $result['avg_price'])->toBe(100.0)
        ->and((float) $result['pnl'])->toBe(0.0);
});

it('calculatePnL: LONG positive pnl when last fill price exceeds avg (price went up)', function (): void {
    $result = Kraite::calculatePnL('LONG', '10', '100', '10', '110');

    expect((float) $result['cum_qty'])->toBe(20.0)
        ->and((float) $result['avg_price'])->toBe(105.0)
        ->and((float) $result['pnl'])->toBeGreaterThan(0);
});

it('calculatePnL: SHORT positive pnl when last fill price BELOW avg (price went down)', function (): void {
    $result = Kraite::calculatePnL('SHORT', '10', '110', '10', '100');

    expect((float) $result['cum_qty'])->toBe(20.0)
        ->and((float) $result['avg_price'])->toBe(105.0)
        ->and((float) $result['pnl'])->toBeGreaterThan(0);
});

it('calculatePnL: SHORT NEGATIVE pnl when last fill price ABOVE avg (price went up against the short)', function (): void {
    $result = Kraite::calculatePnL('SHORT', '10', '100', '10', '110');

    expect((float) $result['pnl'])->toBeLessThan(0);
});

it('calculatePnL: zero cumulative quantity returns 0 avg_price (defensive)', function (): void {
    $result = Kraite::calculatePnL('LONG', '0', '100', '0', '110');

    expect($result['avg_price'])->toBe('0');
});

it('calculatePnL throws InvalidArgumentException for an invalid direction', function (): void {
    Kraite::calculatePnL('SIDEWAYS', '10', '100', '10', '110');
})->throws(InvalidArgumentException::class);

it('calculateWAPData computes per-rung cumulative WAP', function (): void {
    $rows = [
        ['price' => '100', 'quantity' => '10'],
        ['price' => '90', 'quantity' => '20'],
    ];

    $out = Kraite::calculateWAPData($rows, 'LONG');

    expect($out)->toHaveCount(2)
        ->and((float) $out[0]['wap'])->toBe(100.0)
        ->and((float) $out[1]['wap'])->toEqualWithDelta(93.3333, 0.001);
});

it('calculateWAPData applies LONG profit factor (1 + p%) when profitPercent provided', function (): void {
    $rows = [
        ['price' => '100', 'quantity' => '10'],
    ];

    $out = Kraite::calculateWAPData($rows, 'LONG', '1');

    expect((float) $out[0]['wap'])->toBe(101.0);
});

it('calculateWAPData applies SHORT profit factor (1 - p%) when profitPercent provided', function (): void {
    $rows = [
        ['price' => '100', 'quantity' => '10'],
    ];

    $out = Kraite::calculateWAPData($rows, 'SHORT', '1');

    expect((float) $out[0]['wap'])->toBe(99.0);
});

it('calculateWAPData throws on invalid direction', function (): void {
    Kraite::calculateWAPData([['price' => '100', 'quantity' => '10']], 'SIDEWAYS');
})->throws(InvalidArgumentException::class);

it('calculateWAPData throws when a row is missing price or quantity', function (): void {
    Kraite::calculateWAPData([['price' => '100']], 'LONG');
})->throws(InvalidArgumentException::class);

it('calculatePnLAnalysis: returns one level per rung (MKT + N limits)', function (): void {
    $result = Kraite::calculatePnLAnalysis(
        direction: 'LONG',
        marketOrder: ['price' => '100', 'quantity' => '10'],
        limitOrders: [
            ['price' => '90', 'quantity' => '20'],
            ['price' => '80', 'quantity' => '40'],
        ],
        tpPercent: '0.5',
        slPrice: '70',
    );

    expect($result['levels'])->toHaveCount(3) // MKT + 2 limits
        ->and($result['levels'][0]['level'])->toBe('MKT')
        ->and($result['levels'][1]['level'])->toBe('L1')
        ->and($result['levels'][2]['level'])->toBe('L2');
});

it('calculatePnLAnalysis: LONG TP price is ABOVE WAP at every level', function (): void {
    $result = Kraite::calculatePnLAnalysis(
        direction: 'LONG',
        marketOrder: ['price' => '100', 'quantity' => '10'],
        limitOrders: [['price' => '90', 'quantity' => '20']],
        tpPercent: '0.5',
        slPrice: '70',
    );

    foreach ($result['levels'] as $level) {
        expect((float) $level['tp_price'])->toBeGreaterThan((float) $level['wap']);
    }
});

it('calculatePnLAnalysis: SHORT TP price is BELOW WAP at every level (sign-flip pin)', function (): void {
    // Critical contract: a regression that flips the sign here ships
    // as the SHORT TP placed ABOVE entry — TP can never fill and the
    // SL becomes the only exit, guaranteed loss.
    $result = Kraite::calculatePnLAnalysis(
        direction: 'SHORT',
        marketOrder: ['price' => '100', 'quantity' => '10'],
        limitOrders: [['price' => '110', 'quantity' => '20']],
        tpPercent: '0.5',
        slPrice: '120',
    );

    foreach ($result['levels'] as $level) {
        expect((float) $level['tp_price'])->toBeLessThan((float) $level['wap']);
    }
});

it('calculatePnLAnalysis: sl_loss is NEGATIVE for a LONG when SL price < final WAP (loss case)', function (): void {
    $result = Kraite::calculatePnLAnalysis(
        direction: 'LONG',
        marketOrder: ['price' => '100', 'quantity' => '10'],
        limitOrders: [['price' => '90', 'quantity' => '20']],
        tpPercent: '0.5',
        slPrice: '70', // WELL below the WAP
    );

    expect((float) $result['sl_loss'])->toBeLessThan(0);
});

it('calculatePnLAnalysis throws on invalid direction', function (): void {
    Kraite::calculatePnLAnalysis(
        direction: 'SIDEWAYS',
        marketOrder: ['price' => '100', 'quantity' => '10'],
        limitOrders: [],
        tpPercent: '0.5',
        slPrice: '70',
    );
})->throws(InvalidArgumentException::class);
