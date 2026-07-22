<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\ExchangeSymbol\FetchKlinesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;

/**
 * `--reference-set` mode of `kraite:cron-fetch-klines`.
 *
 * Targets the BSCS reference basket (config('kraite.market_regime.symbols'),
 * default BTC + ETH/SOL/BNB/XRP) for the specified `--canonical` exchange
 * and `--timeframe`. Used by the new 15-minute schedule that keeps fresh
 * klines available for the cascade-detection cron without fetching all
 * 600 Binance symbols every 15 min.
 *
 * Distinct from `--only-active-positions` which depends on what's open
 * RIGHT NOW; the reference set is fixed, while removed rows are omitted unless
 * an open position still requires operational monitoring.
 */
beforeEach(function (): void {
    Step::query()->delete();

    $this->binance = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'is_active' => true,
            'is_exchange' => true,
            'name' => 'Binance',
            'recvwindow_margin' => 1000,
        ]
    );

    // Seed all 5 reference symbols on Binance.
    foreach (['BTC', 'ETH', 'SOL', 'BNB', 'XRP'] as $token) {
        ExchangeSymbol::factory()->create([
            'api_system_id' => $this->binance->id,
            'token' => $token,
            'quote' => 'USDT',
        ]);
    }

    // Pin the config so test assertions match the symbol list exactly,
    // independent of any environment override that might add tokens later.
    config(['kraite.market_regime.symbols' => [
        'BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT',
    ]]);
});

it('creates one FetchKlinesJob step per reference symbol on the specified timeframe', function (): void {
    $this->artisan('kraite:cron-fetch-klines', [
        '--reference-set' => true,
        '--canonical' => 'binance',
        '--timeframe' => '15m',
    ])->assertExitCode(0);

    // 5 symbols × 1 timeframe = 5 steps. No correlation/elasticity steps
    // — the BSCS pipeline doesn't need per-reference correlation outputs.
    $steps = Step::where('class', FetchKlinesJob::class)->get();
    expect($steps)->toHaveCount(5);

    $tokens = $steps->map(function (Step $step) {
        $args = is_array($step->arguments) ? $step->arguments : [];
        $exchangeSymbolId = $args['exchangeSymbolId'] ?? null;

        return ExchangeSymbol::find($exchangeSymbolId)?->token;
    })->sort()->values()->all();

    expect($tokens)->toBe(['BNB', 'BTC', 'ETH', 'SOL', 'XRP']);
});

it('passes the requested timeframe through to every step', function (): void {
    $this->artisan('kraite:cron-fetch-klines', [
        '--reference-set' => true,
        '--canonical' => 'binance',
        '--timeframe' => '15m',
    ])->assertExitCode(0);

    $steps = Step::where('class', FetchKlinesJob::class)->get();

    foreach ($steps as $step) {
        $args = is_array($step->arguments) ? $step->arguments : [];
        expect($args['timeframe'])->toBe('15m');
    }
});

it('skips reference symbols that are not present on the target exchange (graceful degradation)', function (): void {
    // Drop SOL from the seed → 4 symbols remain. Command should fan out
    // to the 4 found ones and silently skip the missing one.
    ExchangeSymbol::where('api_system_id', $this->binance->id)
        ->where('token', 'SOL')
        ->get()
        ->each
        ->delete();

    $this->artisan('kraite:cron-fetch-klines', [
        '--reference-set' => true,
        '--canonical' => 'binance',
        '--timeframe' => '15m',
    ])->assertExitCode(0);

    $tokens = Step::where('class', FetchKlinesJob::class)
        ->get()
        ->map(function (Step $step) {
            $args = is_array($step->arguments) ? $step->arguments : [];

            return ExchangeSymbol::find($args['exchangeSymbolId'] ?? null)?->token;
        })
        ->sort()
        ->values()
        ->all();

    expect($tokens)->toBe(['BNB', 'BTC', 'ETH', 'XRP']);
});

it('does not fan out to non-reference symbols even when they exist on the exchange', function (): void {
    // Seed an extra non-reference symbol (LINK) — must NOT show up in the
    // generated steps. Reference set is config-driven, fixed.
    ExchangeSymbol::factory()->create([
        'api_system_id' => $this->binance->id,
        'token' => 'LINK',
        'quote' => 'USDT',
    ]);

    $this->artisan('kraite:cron-fetch-klines', [
        '--reference-set' => true,
        '--canonical' => 'binance',
        '--timeframe' => '15m',
    ])->assertExitCode(0);

    $tokens = Step::where('class', FetchKlinesJob::class)
        ->get()
        ->map(function (Step $step) {
            $args = is_array($step->arguments) ? $step->arguments : [];

            return ExchangeSymbol::find($args['exchangeSymbolId'] ?? null)?->token;
        })
        ->all();

    expect($tokens)->not->toContain('LINK');
});

it('exits cleanly when the canonical has no reference symbols at all', function (): void {
    // Spawn a fresh canonical (e.g. a new exchange we haven't onboarded yet)
    // with zero exchange_symbols rows. Command should warn and exit 0,
    // not throw.
    $newExchange = ApiSystem::factory()->exchange()->create([
        'canonical' => 'newexchange',
        'name' => 'New Exchange',
    ]);

    $this->artisan('kraite:cron-fetch-klines', [
        '--reference-set' => true,
        '--canonical' => $newExchange->canonical,
        '--timeframe' => '15m',
    ])->assertExitCode(0);

    expect(Step::where('class', FetchKlinesJob::class)->count())->toBe(0);
});

it('requires --canonical when --reference-set is used (config-driven scope needs a target)', function (): void {
    // Reference-set without canonical is ambiguous (multiple exchanges have
    // the same tokens). Command should fail loudly rather than fan out to
    // every exchange and waste rate-limit budget.
    $this->artisan('kraite:cron-fetch-klines', [
        '--reference-set' => true,
        '--timeframe' => '15m',
    ])->assertExitCode(1);
});

it('skips removed reference symbols unless an open position still needs monitoring', function (): void {
    $removedWithoutPosition = ExchangeSymbol::query()
        ->where('api_system_id', $this->binance->id)
        ->where('token', 'SOL')
        ->firstOrFail();
    $removedWithoutPosition->update([
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
        'is_manually_enabled' => false,
    ]);

    $removedWithPosition = ExchangeSymbol::query()
        ->where('api_system_id', $this->binance->id)
        ->where('token', 'XRP')
        ->firstOrFail();
    $removedWithPosition->update([
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
        'is_manually_enabled' => false,
    ]);
    Position::factory()->long()->create([
        'exchange_symbol_id' => $removedWithPosition->id,
        'parsed_trading_pair' => 'XRPUSDT',
        'status' => 'active',
    ]);

    $this->artisan('kraite:cron-fetch-klines', [
        '--reference-set' => true,
        '--canonical' => 'binance',
        '--timeframe' => '15m',
    ])->assertExitCode(0);

    $scheduledIds = Step::query()
        ->where('class', FetchKlinesJob::class)
        ->get()
        ->map(fn (Step $step): mixed => data_get($step->arguments, 'exchangeSymbolId'));

    expect($scheduledIds)->not->toContain($removedWithoutPosition->id)
        ->and($scheduledIds)->toContain($removedWithPosition->id);
});
