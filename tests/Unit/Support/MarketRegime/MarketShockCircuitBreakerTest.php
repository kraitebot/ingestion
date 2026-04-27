<?php

declare(strict_types=1);

use Kraite\Core\Support\MarketRegime\MarketShockCircuitBreaker;

/**
 * Fast cascade-in-progress detector. Reads recent 15m klines for BTC +
 * the 4 BSCS reference alts (ETH/SOL/BNB/XRP) and fires when any of the
 * four trigger rules trips. The job that consumes this arms the shared
 * `bscs_cooldown_until` column for `cooldown_hours` (default 24h),
 * blocking new opens through `HasTradingGuards::canOpenPositions()`.
 *
 * Trigger rules (any one is sufficient):
 *
 *   1. btc_15m_move_pct        <= -3.0
 *   2. btc_1h_move_pct         <= -5.0
 *   3. alt_basket_1h_move_pct  <= -7.0
 *   4. corr_1h >= 0.85 AND abs(btc_1h_move_pct) >= 3.0
 *
 * Window contracts:
 *   - 15m move      = (last close − bar 1-back close) / bar 1-back close
 *   - 1h move       = (last close − bar 4-back close) / bar 4-back close
 *                     (4 × 15m = 1 hour)
 *   - alt basket    = mean of 1h moves across ETH/SOL/BNB/XRP
 *   - correlation   = Pearson over 12 × 15m bars (= 11 returns) per token,
 *                     averaged across BTC + 4 alts off-diagonals
 *
 * Inputs are arrays of OHLCV bars: each `[close, timestamp]` minimum.
 * Newest-LAST (chronological order). Calculator is pure-PHP — no DB, no
 * HTTP. Job layer feeds it from the `candles` table.
 *
 * @see ~/docs/kraite/black-swan-logic.md (Phase 2.1A)
 */
function fakeBars(int $count, float $startPrice, float $endPrice): array
{
    // Linear ramp from startPrice → endPrice across $count bars.
    $bars = [];
    $step = ($endPrice - $startPrice) / max(1, $count - 1);
    for ($i = 0; $i < $count; $i++) {
        $price = $startPrice + ($step * $i);
        $bars[] = [
            'close' => (string) $price,
            'timestamp' => 1700000000 + ($i * 900), // 15-min spacing
        ];
    }

    return $bars;
}

function flatBars(int $count, float $price): array
{
    return fakeBars($count, $price, $price);
}

it('does NOT fire on calm flat data', function (): void {
    $btc = flatBars(20, 50000.0);
    $alts = [
        'ETH' => flatBars(20, 3000.0),
        'SOL' => flatBars(20, 100.0),
        'BNB' => flatBars(20, 600.0),
        'XRP' => flatBars(20, 0.5),
    ];

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    expect($result['fired'])->toBeFalse()
        ->and($result['rules_triggered'])->toBe([]);
});

it('fires rule #1 (btc_15m) when BTC drops 3% in the last 15m bar', function (): void {
    // 19 bars at 50000, then last bar drops to 48400 (-3.2% from prior close).
    $btc = flatBars(19, 50000.0);
    $btc[] = ['close' => '48400', 'timestamp' => 1700017100];
    $alts = [
        'ETH' => flatBars(20, 3000.0),
        'SOL' => flatBars(20, 100.0),
        'BNB' => flatBars(20, 600.0),
        'XRP' => flatBars(20, 0.5),
    ];

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    expect($result['fired'])->toBeTrue()
        ->and($result['rules_triggered'])->toContain('btc_15m');
});

it('fires rule #2 (btc_1h) when BTC drops 5% across the last hour (4 × 15m bars)', function (): void {
    // First 16 bars at 50000, last 4 bars decay linearly from 50000 → 47000 (-6%).
    $btc = flatBars(16, 50000.0);
    foreach (fakeBars(4, 50000.0, 47000.0) as $bar) {
        $btc[] = $bar;
    }
    $alts = [
        'ETH' => flatBars(20, 3000.0),
        'SOL' => flatBars(20, 100.0),
        'BNB' => flatBars(20, 600.0),
        'XRP' => flatBars(20, 0.5),
    ];

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    expect($result['fired'])->toBeTrue()
        ->and($result['rules_triggered'])->toContain('btc_1h');
});

it('fires rule #3 (alt_basket_1h) when 4-alt average drops 7% across the last hour', function (): void {
    // BTC stays calm, alts all drop ~8% in the last hour.
    $btc = flatBars(20, 50000.0);
    $alts = [];
    foreach (['ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5] as $token => $price) {
        $bars = flatBars(16, $price);
        // Last 4 bars drop from price → price * 0.92 (-8%).
        foreach (fakeBars(4, $price, $price * 0.92) as $bar) {
            $bars[] = $bar;
        }
        $alts[$token] = $bars;
    }

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    expect($result['fired'])->toBeTrue()
        ->and($result['rules_triggered'])->toContain('alt_basket_1h');
});

it('fires rule #4 (corr+magnitude) when BTC + alts move ≥ 3% in lockstep', function (): void {
    // All 5 tokens drop 4% across the last hour, perfectly correlated.
    // Calm baseline for first 16 bars to keep correlation stable, then a
    // synchronised 4% drop in the last 4 bars.
    $btc = flatBars(16, 50000.0);
    foreach (fakeBars(4, 50000.0, 48000.0) as $bar) {
        $btc[] = $bar;
    }

    $alts = [];
    foreach (['ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5] as $token => $price) {
        $bars = flatBars(16, $price);
        foreach (fakeBars(4, $price, $price * 0.96) as $bar) {
            $bars[] = $bar;
        }
        $alts[$token] = $bars;
    }

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    // The 1h drop is 4% — large enough to trip rule #4 (magnitude ≥ 3.0%
    // and correlation ≥ 0.85), but NOT rule #2 (which needs 5%).
    expect($result['fired'])->toBeTrue()
        ->and($result['rules_triggered'])->toContain('corr_magnitude');
});

it('does NOT fire rule #4 when correlation is high but magnitude is below 3%', function (): void {
    // Tiny synchronised move — high correlation but only 1% magnitude.
    $btc = flatBars(16, 50000.0);
    foreach (fakeBars(4, 50000.0, 49500.0) as $bar) {
        $btc[] = $bar;
    }
    $alts = [];
    foreach (['ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5] as $token => $price) {
        $bars = flatBars(16, $price);
        foreach (fakeBars(4, $price, $price * 0.99) as $bar) {
            $bars[] = $bar;
        }
        $alts[$token] = $bars;
    }

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    expect($result['rules_triggered'])->not->toContain('corr_magnitude')
        ->and($result['fired'])->toBeFalse();
});

it('returns ALL triggered rules when multiple fire simultaneously', function (): void {
    // Aggressive: BTC drops 4% in the last bar AND 7% across the hour.
    // Both rule #1 (15m) and rule #2 (1h) fire; alts in lockstep also
    // trips rule #4.
    $btc = flatBars(15, 50000.0);
    foreach (fakeBars(5, 50000.0, 46500.0) as $bar) {
        $btc[] = $bar;
    }
    // Force the very last bar to drop more aggressively for the 15m rule.
    $btc[count($btc) - 1] = ['close' => '44640', 'timestamp' => 1700018000];

    $alts = [];
    foreach (['ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5] as $token => $price) {
        $bars = flatBars(15, $price);
        foreach (fakeBars(5, $price, $price * 0.93) as $bar) {
            $bars[] = $bar;
        }
        $alts[$token] = $bars;
    }

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    expect($result['fired'])->toBeTrue()
        ->and(count($result['rules_triggered']))->toBeGreaterThan(1);
});

it('returns no-fire when input arrays are too short for the analysis windows', function (): void {
    // Only 3 bars — calculator can't compute 1h moves (needs 5) or
    // correlation (needs 12). Should fail safe to no-fire rather than
    // throw or fire spuriously.
    $btc = flatBars(3, 50000.0);
    $alts = [
        'ETH' => flatBars(3, 3000.0),
        'SOL' => flatBars(3, 100.0),
        'BNB' => flatBars(3, 600.0),
        'XRP' => flatBars(3, 0.5),
    ];

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    expect($result['fired'])->toBeFalse();
});

it('exposes the raw computed values alongside the fired flags for forensic logging', function (): void {
    $btc = flatBars(20, 50000.0);
    $alts = [
        'ETH' => flatBars(20, 3000.0),
        'SOL' => flatBars(20, 100.0),
        'BNB' => flatBars(20, 600.0),
        'XRP' => flatBars(20, 0.5),
    ];

    $result = MarketShockCircuitBreaker::evaluate($btc, $alts);

    expect($result)->toHaveKey('btc_15m_pct')
        ->and($result)->toHaveKey('btc_1h_pct')
        ->and($result)->toHaveKey('alt_basket_1h_pct')
        ->and($result)->toHaveKey('corr_1h');
});
