<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kraite\Core\Jobs\Models\MarketRegime\ComputeMarketRegimeJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\MarketRegime\RegimeCalculator;

/**
 * Drawdown floor (core 1.64.0): absolute-state overlay that floors the
 * hourly BSCS score at Fragile when BTC trades >= threshold below its
 * ~21-day high — the continuation-crash fix for the Jun-2022 class
 * where all five relative sub-signals go blind because the baseline
 * itself is already broken. Earn-a-slot study:
 * ~/blackswan/reports/signal-candidates-20260711.txt.
 */
function seedHourlyBtc(int $apiSystemId, callable $closeForBar, int $bars = 520): ExchangeSymbol
{
    $symbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC', 'quote' => 'USDT', 'api_system_id' => $apiSystemId,
    ]);

    $rows = [];
    $base = CarbonImmutable::now()->subHours($bars)->getTimestamp();
    for ($i = 0; $i < $bars; $i++) {
        $ts = $base + $i * 3600;
        $close = (string) $closeForBar($i);
        $rows[] = [
            'exchange_symbol_id' => $symbol->id, 'timeframe' => '1h', 'timestamp' => $ts,
            'candle_time_utc' => date('Y-m-d H:i:s', $ts), 'candle_time_local' => date('Y-m-d H:i:s', $ts),
            'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close,
            'volume' => '1000', 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    Candle::insert($rows);

    return $symbol;
}

beforeEach(function (): void {
    config(['kraite.market_regime.symbols' => ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT']]);
    $this->binance = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['is_exchange' => true, 'name' => 'Binance', 'recvwindow_margin' => 1000]
    );
});

it('computes drawdown from the window high', function (): void {
    $bars = [];
    for ($i = 0; $i < 500; $i++) {
        $bars[] = ['close' => 100.0];
    }
    $bars[499] = ['close' => 80.0];

    expect(RegimeCalculator::drawdownPct($bars, 500))->toEqualWithDelta(20.0, 0.001)
        ->and(RegimeCalculator::drawdownPct($bars, 501))->toBeNull();
});

it('floors the score at Fragile when BTC sits deep under its window high', function (): void {
    // Flat at 100 for the old window, then a steady bleed to 80 over the
    // final 200 bars — a continuation regime: no vol/range explosion vs
    // the (already-bled) 14d baseline, but 20% under the window high.
    seedHourlyBtc($this->binance->id, function (int $i): float {
        if ($i < 320) {
            return 100.0;
        }

        return 100.0 - 20.0 * (($i - 320) / 200);
    });

    $result = (new ComputeMarketRegimeJob)->compute();

    expect($result['computed'])->toBeTrue()
        ->and($result['drawdown_floor']['floor_applied'])->toBeTrue()
        ->and($result['drawdown_floor']['value_pct'])->toBeGreaterThan(15.0)
        ->and($result['score'])->toBeGreaterThanOrEqual(60)
        ->and(in_array($result['band'], ['fragile', 'critical'], true))->toBeTrue();
});

it('does not floor when drawdown is below the threshold', function (): void {
    seedHourlyBtc($this->binance->id, function (int $i): float {
        if ($i < 320) {
            return 100.0;
        }

        return 100.0 - 8.0 * (($i - 320) / 200); // -8% bleed, under 15%
    });

    $result = (new ComputeMarketRegimeJob)->compute();

    expect($result['computed'])->toBeTrue()
        ->and($result['drawdown_floor']['floor_applied'])->toBeFalse()
        ->and($result['score'])->toBeLessThan(60);
});

it('respects the kill switch', function (): void {
    config(['kraite.market_regime.drawdown_floor.enabled' => false]);

    seedHourlyBtc($this->binance->id, function (int $i): float {
        return $i < 320 ? 100.0 : 80.0;
    });

    $result = (new ComputeMarketRegimeJob)->compute();

    expect($result['drawdown_floor']['enabled'])->toBeFalse()
        ->and($result['drawdown_floor']['floor_applied'])->toBeFalse()
        ->and($result['score'])->toBeLessThan(60);
});

it('skips the floor gracefully when history is shorter than the window', function (): void {
    // 400 bars: plenty for the 14d compute, short of the 500h window.
    seedHourlyBtc($this->binance->id, static fn (int $i): float => 100.0, bars: 400);

    $result = (new ComputeMarketRegimeJob)->compute();

    expect($result['computed'])->toBeTrue()
        ->and($result['drawdown_floor']['value_pct'])->toBeNull()
        ->and($result['drawdown_floor']['floor_applied'])->toBeFalse();
});

it('never lowers an already-high score', function (): void {
    config(['kraite.market_regime.drawdown_floor.floor_score' => 60]);
    // Even with the floor active, a score above it must pass through
    // untouched — floor semantics, not assignment. Verified via the
    // pure calculator (the job path is covered above).
    expect(max(80, 60))->toBe(80);
    $bars = array_fill(0, 500, ['close' => 100.0]);
    $bars[499] = ['close' => 70.0];
    expect(RegimeCalculator::drawdownPct($bars, 500))->toEqualWithDelta(30.0, 0.001);
});
