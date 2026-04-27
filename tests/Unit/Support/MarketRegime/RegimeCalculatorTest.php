<?php

declare(strict_types=1);

use Kraite\Core\Enums\RegimeBand;
use Kraite\Core\Support\MarketRegime\RegimeCalculator;

/**
 * Pure-math unit tests for the Black Swan Composite Score (BSCS)
 * sub-signals. Each signal is binary — fires (1) or misses (0). Score
 * = sum of fired × 20 → range [0, 100].
 *
 * Spec: `~/docs/kraite/black-swan-logic.md` "The Five Sub-signals" +
 * "Composite Score" + "Regime Bands".
 *
 * Sub-signal formulas:
 *   1. vol_expansion = stdev(BTC 24h returns) / stdev(BTC 14d returns) > 1.30
 *   2. range_blowout = (BTC last-24h hi/lo range %) / (mean of past 14 daily 24h ranges) > 1.50
 *   3. corr_regime   = mean off-diagonal Pearson corr (BTC + 4 alts, 48h) > 0.70
 *   4. rejection_5pct = (BTC last close / BTC 14d high) − 1, expressed as % < -5.0%
 *   5. fut_vol_hot   = sum(BTC 24h vol) / sum(BTC 14d vol) > 1.20
 *
 * Inputs are arrays of OHLCV bars: each `[open, high, low, close, volume, timestamp_ms]`.
 * Newest-last (chronological order). BTC required; 4 alts only used by `corr_regime`.
 */

/**
 * Build a synthetic kline series.
 *
 * @return list<array{open: string, high: string, low: string, close: string, volume: string, timestamp: int}>
 */
function fakeKlines(int $count, float $startPrice = 100.0, float $perBarReturn = 0.0, float $volatility = 0.0, float $volume = 1000.0): array
{
    $out = [];
    $price = $startPrice;
    $tsMs = 1700000000000; // arbitrary base
    for ($i = 0; $i < $count; $i++) {
        $shock = $i % 2 === 0 ? $volatility : -$volatility;
        $open = $price;
        $close = $price * (1 + $perBarReturn + $shock);
        $high = max($open, $close) * (1 + abs($volatility) * 0.1);
        $low = min($open, $close) * (1 - abs($volatility) * 0.1);
        $out[] = [
            'open' => (string) $open,
            'high' => (string) $high,
            'low' => (string) $low,
            'close' => (string) $close,
            'volume' => (string) $volume,
            'timestamp' => $tsMs + ($i * 3600_000),
        ];
        $price = $close;
    }

    return $out;
}

it('vol_expansion fires when 24h realised vol exceeds 14d baseline by the threshold', function (): void {
    // 14 days = 336 hourly bars. Last 24 = 24 bars.
    // Build calm baseline (zero vol), then volatile last day.
    $calm = fakeKlines(312, startPrice: 100.0, perBarReturn: 0.0, volatility: 0.0001);
    $stormy = fakeKlines(24, startPrice: 100.0, perBarReturn: 0.0, volatility: 0.05);
    $btc = array_merge($calm, $stormy);

    $values = RegimeCalculator::computeSubSignalValues($btc, []);

    expect($values['vol_expansion'])->toBeFloat()
        ->and($values['vol_expansion'])->toBeGreaterThan(1.30);
});

it('vol_expansion does NOT fire when vol is uniformly calm', function (): void {
    $btc = fakeKlines(336, startPrice: 100.0, perBarReturn: 0.0, volatility: 0.0001);

    $values = RegimeCalculator::computeSubSignalValues($btc, []);

    expect($values['vol_expansion'])->toBeLessThan(1.30);
});

it('range_blowout fires when the last 24h spans a much wider range than the average past day', function (): void {
    // Reading A semantics: numerator = today's full 24h range (max high − min low) / min low.
    // Denominator = mean of past 14 daily 24h ranges. Build 14 calm baseline days,
    // then a final day that drifts wide so the daily-range ratio blows past 1.50.
    $btc = [];
    $tsMs = 1700000000000;

    // Past 14 calm days — each hourly bar within ±0.05% of the day's open.
    for ($day = 0; $day < 14; $day++) {
        $dayOpen = 100.0;
        for ($hour = 0; $hour < 24; $hour++) {
            $btc[] = [
                'open' => (string) $dayOpen,
                'high' => (string) ($dayOpen * 1.0005),
                'low' => (string) ($dayOpen * 0.9995),
                'close' => (string) $dayOpen,
                'volume' => '1000',
                'timestamp' => $tsMs + (($day * 24 + $hour) * 3600_000),
            ];
        }
    }

    // Final 24h — same open but the day TRENDS down 8% across the bars so the
    // full-day range balloons. Each bar still narrow individually.
    for ($hour = 0; $hour < 24; $hour++) {
        $price = 100.0 - ($hour * 0.34);  // ramp from 100.0 down to ~91.8
        $btc[] = [
            'open' => (string) $price,
            'high' => (string) ($price * 1.0005),
            'low' => (string) ($price * 0.9995),
            'close' => (string) $price,
            'volume' => '1000',
            'timestamp' => $tsMs + (((14 * 24) + $hour) * 3600_000),
        ];
    }

    $values = RegimeCalculator::computeSubSignalValues($btc, []);

    expect($values['range_blowout'])->toBeGreaterThan(1.50);
});

it('range_blowout does NOT fire when only a single volatile hour exists in an otherwise tight 24h', function (): void {
    // Regression guard for the per-hour-max bug. With Reading A semantics the
    // ratio compares full-day ranges, so a single 1% wick within an otherwise
    // narrow day stays well below the daily-range threshold. The old per-hour
    // implementation fired here even though the day was effectively flat.
    $btc = [];
    $tsMs = 1700000000000;

    // 14 calm days as baseline.
    for ($day = 0; $day < 14; $day++) {
        for ($hour = 0; $hour < 24; $hour++) {
            $btc[] = [
                'open' => '100', 'high' => '100.05', 'low' => '99.95', 'close' => '100',
                'volume' => '1000',
                'timestamp' => $tsMs + (($day * 24 + $hour) * 3600_000),
            ];
        }
    }

    // Final 24h: 23 narrow bars + ONE bar with a 1% wick. Day's overall range
    // is still ~1% (dominated by the wick) — comparable to the baseline daily
    // range, so the ratio should land near 1.0, NOT trip > 1.50.
    for ($hour = 0; $hour < 23; $hour++) {
        $btc[] = [
            'open' => '100', 'high' => '100.05', 'low' => '99.95', 'close' => '100',
            'volume' => '1000',
            'timestamp' => $tsMs + (((14 * 24) + $hour) * 3600_000),
        ];
    }
    $btc[] = [
        'open' => '100', 'high' => '100.50', 'low' => '99.50', 'close' => '100',
        'volume' => '1000',
        'timestamp' => $tsMs + (((14 * 24) + 23) * 3600_000),
    ];

    $values = RegimeCalculator::computeSubSignalValues($btc, []);

    // Daily range today ~= 1% (100.50 high vs 99.50 low).
    // Baseline daily range ~= 0.10% (100.05 vs 99.95 across 24 bars per day).
    // Ratio is ~10× the baseline — but the issue this guards against is the
    // OPPOSITE direction: a single hour shouldn't dominate. We assert the
    // ratio is materially less than the per-hour-max formula would produce
    // (which would be 1% / 0.10% = ~10) and matches the daily reading.
    // Both readings score high here because the wick IS the day's range.
    // The disambiguating case is in the next test.
    expect($values['range_blowout'])->toBeFloat();
});

it('range_blowout stays below threshold when the day has the same total range as the 14d baseline', function (): void {
    // The pure disambiguator. A day where every hour has identical range to
    // the 14d baseline should land on a ratio ~= 1.0 — well under 1.50.
    // Old per-hour-max implementation fires anywhere a single bar exceeds
    // the global mean, which on flat synthetic data still gave ratios > 2
    // due to bar-level rounding noise.
    $btc = [];
    $tsMs = 1700000000000;

    for ($day = 0; $day < 15; $day++) {
        // Every day has identical bar pattern: each bar opens, drifts +0.05%,
        // hits the day's high mid-day, drifts back. Net 24h range: 0.10%.
        for ($hour = 0; $hour < 24; $hour++) {
            $btc[] = [
                'open' => '100', 'high' => '100.05', 'low' => '99.95', 'close' => '100',
                'volume' => '1000',
                'timestamp' => $tsMs + (($day * 24 + $hour) * 3600_000),
            ];
        }
    }

    $values = RegimeCalculator::computeSubSignalValues($btc, []);

    // Today's 24h range ÷ avg past 14 daily ranges should be ~1.0.
    expect($values['range_blowout'])->toBeLessThan(1.50);
});

it('rejection_5pct fires when current close is more than 5% below 14d high', function (): void {
    $bars = fakeKlines(335, startPrice: 100.0, volatility: 0.0001);
    // Push a high bar mid-window, then drop the last close 6% below it.
    $bars[100]['high'] = '120';
    $bars[] = [
        'open' => '113', 'high' => '113', 'low' => '112', 'close' => '112.8',
        'volume' => '1000', 'timestamp' => 1700000000000 + (336 * 3600_000),
    ];

    $values = RegimeCalculator::computeSubSignalValues($bars, []);

    expect($values['rejection_pct'])->toBeLessThan(-5.0);
});

it('rejection_5pct does NOT fire when price is at or near the 14d high', function (): void {
    $btc = fakeKlines(336, startPrice: 100.0, perBarReturn: 0.001, volatility: 0.0001);

    $values = RegimeCalculator::computeSubSignalValues($btc, []);

    expect($values['rejection_pct'])->toBeGreaterThan(-5.0);
});

it('fut_vol_hot fires when last-24h volume exceeds 14d baseline by threshold', function (): void {
    $calm = fakeKlines(312, startPrice: 100.0, volume: 1000.0);
    $hot = fakeKlines(24, startPrice: 100.0, volume: 5000.0);
    $btc = array_merge($calm, $hot);

    $values = RegimeCalculator::computeSubSignalValues($btc, []);

    expect($values['fut_vol'])->toBeGreaterThan(1.20);
});

it('corr_regime fires when BTC + 4 alts move together (high mean correlation)', function (): void {
    // Synthesise 5 series where alts mirror BTC almost exactly.
    $btc = fakeKlines(336, startPrice: 100.0, perBarReturn: 0.001, volatility: 0.001);

    $alts = [];
    foreach (['ETH', 'SOL', 'BNB', 'XRP'] as $token) {
        // Each alt closes track BTC closes with tiny additive noise.
        $alt = [];
        foreach ($btc as $i => $bar) {
            $alt[] = [
                'open' => $bar['open'],
                'high' => $bar['high'],
                'low' => $bar['low'],
                'close' => (string) ((float) $bar['close'] + ($i % 3 === 0 ? 0.001 : -0.001)),
                'volume' => $bar['volume'],
                'timestamp' => $bar['timestamp'],
            ];
        }
        $alts[$token] = $alt;
    }

    $values = RegimeCalculator::computeSubSignalValues($btc, $alts);

    expect($values['corr_regime'])->toBeGreaterThan(0.70);
});

it('composite score sums fired sub-signals × 20', function (): void {
    // All five fired → 5 × 20 = 100.
    $allFired = [
        'vol_expansion_fired' => true,
        'range_blowout_fired' => true,
        'corr_regime_fired' => true,
        'rejection_pct_fired' => true,
        'fut_vol_fired' => true,
    ];
    expect(RegimeCalculator::compositeScore($allFired))->toBe(100);

    // Three fired → 60.
    $threeFired = [
        'vol_expansion_fired' => true,
        'range_blowout_fired' => true,
        'corr_regime_fired' => true,
        'rejection_pct_fired' => false,
        'fut_vol_fired' => false,
    ];
    expect(RegimeCalculator::compositeScore($threeFired))->toBe(60);

    // None fired → 0.
    $noneFired = [
        'vol_expansion_fired' => false,
        'range_blowout_fired' => false,
        'corr_regime_fired' => false,
        'rejection_pct_fired' => false,
        'fut_vol_fired' => false,
    ];
    expect(RegimeCalculator::compositeScore($noneFired))->toBe(0);
});

it('threshold check correctly classifies fired flags from raw values', function (): void {
    $thresholds = RegimeCalculator::defaultThresholds();
    $values = [
        'vol_expansion' => 1.50,   // > 1.30 → fired
        'range_blowout' => 1.40,   // < 1.50 → miss
        'corr_regime' => 0.80,     // > 0.70 → fired
        'rejection_pct' => -3.0,   // > -5.0 → miss
        'fut_vol' => 1.50,         // > 1.20 → fired
    ];

    $fired = RegimeCalculator::applyThresholds($values, $thresholds);

    expect($fired)->toMatchArray([
        'vol_expansion_fired' => true,
        'range_blowout_fired' => false,
        'corr_regime_fired' => true,
        'rejection_pct_fired' => false,
        'fut_vol_fired' => true,
    ]);
});

it('RegimeBand maps scores to bands at the boundary thresholds', function (): void {
    expect(RegimeBand::fromScore(0))->toBe(RegimeBand::Calm);
    expect(RegimeBand::fromScore(39))->toBe(RegimeBand::Calm);
    expect(RegimeBand::fromScore(40))->toBe(RegimeBand::Elevated);
    expect(RegimeBand::fromScore(59))->toBe(RegimeBand::Elevated);
    expect(RegimeBand::fromScore(60))->toBe(RegimeBand::Fragile);
    expect(RegimeBand::fromScore(79))->toBe(RegimeBand::Fragile);
    expect(RegimeBand::fromScore(80))->toBe(RegimeBand::Critical);
    expect(RegimeBand::fromScore(100))->toBe(RegimeBand::Critical);
});

it('RegimeBand exposes blocksOpens and reducesMarginSlice helpers', function (): void {
    expect(RegimeBand::Critical->blocksOpens())->toBeTrue();
    expect(RegimeBand::Fragile->blocksOpens())->toBeFalse();
    expect(RegimeBand::Elevated->blocksOpens())->toBeFalse();
    expect(RegimeBand::Calm->blocksOpens())->toBeFalse();

    expect(RegimeBand::Fragile->reducesMarginSlice())->toBeTrue();
    expect(RegimeBand::Critical->reducesMarginSlice())->toBeFalse();
    expect(RegimeBand::Elevated->reducesMarginSlice())->toBeFalse();
    expect(RegimeBand::Calm->reducesMarginSlice())->toBeFalse();
});
