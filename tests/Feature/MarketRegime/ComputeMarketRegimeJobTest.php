<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kraite\Core\Jobs\Models\MarketRegime\ComputeMarketRegimeJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketRegimeSnapshot;
use Kraite\Core\Models\Symbol;

/**
 * Phase 1 contract for the BSCS compute pipeline:
 *
 *   1. Job loads 14 days of 1h klines per symbol from the `candles` table.
 *   2. Computes the 5 sub-signals + composite score.
 *   3. Writes one row to `market_regime_snapshots` with all sub-signal
 *      raw values, fired flags, btc_close, and inputs_meta.
 *   4. Denormalises score / band / synced_at onto the `kraite` singleton.
 *   5. Phase 1 invariant: `bscs_block_active` MUST stay false regardless
 *      of score — gating is Phase 2 work and the column is observed but
 *      not yet enforced. A regression here flips production into "no
 *      opens" the moment a score crosses 80, which is exactly what the
 *      4-week observation period is designed to avoid.
 *   6. Audit log: a `modelLog` entry on the Kraite row capturing the
 *      score change for the forensic timeline.
 */
function seedFifteenCalmDaysOfBtcKlines(int $exchangeSymbolId): void
{
    // 15 days × 24 hourly bars = 360 bars (defensive past the 14d window
    // the calculator strictly needs). All bars near-identical so every
    // sub-signal lands well below threshold → score should resolve to 0.
    $tsBase = CarbonImmutable::now()->subDays(15)->getTimestamp();
    $rows = [];
    for ($hour = 0; $hour < 360; $hour++) {
        $ts = $tsBase + ($hour * 3600);
        $rows[] = [
            'exchange_symbol_id' => $exchangeSymbolId,
            'timeframe' => '1h',
            'timestamp' => $ts,
            'candle_time_utc' => date('Y-m-d H:i:s', $ts),
            'candle_time_local' => date('Y-m-d H:i:s', $ts),
            'open' => '100.00',
            'high' => '100.05',
            'low' => '99.95',
            'close' => '100.00',
            'volume' => '1000',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    Candle::insert($rows);
}

function makeBinanceSymbolForRegime(string $token): ExchangeSymbol
{
    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $symbol = Symbol::factory()->create(['token' => $token]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'symbol_id' => $symbol->id,
        'api_system_id' => $binance->id,
    ]);
}

it('writes a snapshot row with all 5 sub-signals when 14d of klines are available', function (): void {
    $btc = makeBinanceSymbolForRegime('BTC');
    seedFifteenCalmDaysOfBtcKlines($btc->id);

    // Need ALL 4 alts seeded too — calculator skips short alt series but
    // corr_regime needs at least 2 alongside BTC to produce a value, and
    // the snapshot row should carry a real corr value either way.
    foreach (['ETH', 'SOL', 'BNB', 'XRP'] as $altToken) {
        $alt = ExchangeSymbol::factory()->create([
            'token' => $altToken,
            'quote' => 'USDT',
            'api_system_id' => $btc->api_system_id,
        ]);
        seedFifteenCalmDaysOfBtcKlines($alt->id);
    }

    $job = new ComputeMarketRegimeJob;
    $result = $job->compute();

    expect($result['computed'])->toBeTrue();

    $snapshot = MarketRegimeSnapshot::find($result['snapshot_id']);
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->bscs_score)->toBeInt()
        ->and($snapshot->vol_expansion_value)->not->toBeNull()
        ->and($snapshot->range_blowout_value)->not->toBeNull()
        ->and($snapshot->corr_regime_value)->not->toBeNull()
        ->and($snapshot->rejection_pct_value)->not->toBeNull()
        ->and($snapshot->fut_vol_value)->not->toBeNull()
        ->and($snapshot->btc_close)->not->toBeNull()
        ->and($snapshot->inputs_meta)->toBeArray();
});

it('denormalises score, band, and synced_at onto the kraite singleton', function (): void {
    $btc = makeBinanceSymbolForRegime('BTC');
    seedFifteenCalmDaysOfBtcKlines($btc->id);

    $job = new ComputeMarketRegimeJob;
    $result = $job->compute();

    $kraite = Kraite::find(1);
    expect($kraite->bscs_score)->toBe($result['score'])
        ->and($kraite->bscs_band)->toBe($result['band'])
        ->and($kraite->bscs_synced_at)->not->toBeNull();
});

it('Phase 1 invariant: bscs_block_active stays false regardless of score or pre-state', function (): void {
    // Drive a state where Phase 2 logic WOULD set bscs_block_active=true:
    //   - threshold lowered to 1 (so any non-zero score crosses)
    //   - block_active pre-set to true (so a Phase 2-style "preserve previous"
    //     accident would also fail this test)
    // Then run the compute job. The Phase 1 contract is "observe only" —
    // bscs_block_active must come back FALSE no matter what. The 4-week
    // observation window per spec requires this; if the gate flips during
    // Phase 1, opens silently halt the moment a score crosses threshold and
    // the calibration data is poisoned by trading flow changes.
    Kraite::find(1)->updateSaving([
        'bscs_block_threshold' => 1,
        'bscs_block_active' => true,
    ]);

    $btc = makeBinanceSymbolForRegime('BTC');
    seedFifteenCalmDaysOfBtcKlines($btc->id);

    $job = new ComputeMarketRegimeJob;
    $job->compute();

    $kraite = Kraite::find(1);
    expect((bool) $kraite->bscs_block_active)->toBeFalse(
        'Phase 1 must not enable bscs_block_active. Pre-state was true + threshold was 1; '
        .'compute must reset to false unconditionally until Phase 2 wires HasTradingGuards.'
    );
});

it('skips the snapshot when fewer than 14 days of BTC history exist', function (): void {
    $btc = makeBinanceSymbolForRegime('BTC');
    // Seed only 200 hourly bars — well under the 14d × 24 = 336 minimum.
    $tsBase = CarbonImmutable::now()->subHours(200)->getTimestamp();
    $rows = [];
    for ($hour = 0; $hour < 200; $hour++) {
        $ts = $tsBase + ($hour * 3600);
        $rows[] = [
            'exchange_symbol_id' => $btc->id,
            'timeframe' => '1h',
            'timestamp' => $ts,
            'candle_time_utc' => date('Y-m-d H:i:s', $ts),
            'candle_time_local' => date('Y-m-d H:i:s', $ts),
            'open' => '100', 'high' => '100', 'low' => '100', 'close' => '100',
            'volume' => '1000',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    Candle::insert($rows);

    $beforeCount = MarketRegimeSnapshot::count();

    $job = new ComputeMarketRegimeJob;
    $result = $job->compute();

    expect($result['computed'])->toBeFalse()
        ->and($result['reason'])->toBe('insufficient_btc_history')
        ->and(MarketRegimeSnapshot::count())->toBe($beforeCount);
});

it('writes a modelLog entry on the kraite row capturing the score change', function (): void {
    $btc = makeBinanceSymbolForRegime('BTC');
    seedFifteenCalmDaysOfBtcKlines($btc->id);

    $job = new ComputeMarketRegimeJob;
    $job->compute();

    // Either the dedicated modelLog event ('bscs_recompute') OR the
    // attribute-change logs from updateSaving on the singleton's bscs_*
    // columns satisfy the audit-trail requirement. Assert at least one
    // log entry was written for the kraite row in the compute window.
    $modelLogs = \Kraite\Core\Models\ModelLog::query()
        ->where('loggable_type', Kraite::class)
        ->where('loggable_id', 1)
        ->where('created_at', '>=', now()->subMinute())
        ->get();

    expect($modelLogs)->not->toBeEmpty();
});
