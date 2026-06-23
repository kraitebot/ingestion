<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\VerifyPriceAlignmentJob;
use Kraite\Core\Jobs\Lifecycles\ExchangeSymbol\VerifyPriceAlignmentsJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\Kraite;

/**
 * A non-Binance symbol that lists the same asset under a different contract unit
 * (KuCoin/Bybit FLOKI = 1/1000 of Binance 1000FLOKI) carries a replicated
 * mark_price wrong by the contract ratio. The refresh price-alignment check
 * measures the symbol's own live price against its Binance same-asset sibling
 * and switches off anything that doesn't match within tolerance, keeping it out
 * of trading (scopeTradeable requires is_price_aligned=true).
 */
uses()->group('feature', 'exchange-symbols', 'price-alignment');

function setupPriceAlignmentAtomic(string $bybitLivePrice, string $binanceMark = '0.0237'): array
{
    Kraite::updateOrCreate(['id' => 1], [
        'email' => 'admin@test.com',
        'bybit_api_key' => 'TESTKEY',
        'bybit_api_secret' => 'TESTSECRET',
        'notification_channels' => ['mail'],
        'allow_opening_positions' => true,
    ]);

    $binanceSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bybitSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bybit', 'name' => 'Bybit']);

    $binance = ExchangeSymbol::factory()->create([
        'api_system_id' => $binanceSystem->id,
        'token' => '1000FLOKI',
        'quote' => 'USDT',
        'symbol_id' => 72,
    ]);

    // Binance reference price lives on the price sidecar.
    ExchangeSymbolPrice::updateOrCreate(
        ['exchange_symbol_id' => $binance->id],
        ['mark_price' => $binanceMark],
    );

    $bybit = ExchangeSymbol::factory()->create([
        'api_system_id' => $bybitSystem->id,
        'token' => 'FLOKI',
        'quote' => 'USDT',
        'symbol_id' => 72,
        'is_price_aligned' => true,
        'is_manually_enabled' => true,
    ]);

    // Bybit /v5/market/tickers shape: result.list[0].markPrice
    Http::fake([
        '*' => Http::response(['result' => ['list' => [['markPrice' => $bybitLivePrice]]]], 200),
    ]);

    $job = new VerifyPriceAlignmentJob($bybit->id);
    $job->assignExceptionHandler();

    return [$job, $bybit];
}

it('disables a unit-divergent symbol whose live price is ~1000x off Binance', function (): void {
    // Bybit FLOKI live ~0.0000237 vs Binance 1000FLOKI 0.0237 → ratio ~0.001.
    [$job, $bybit] = setupPriceAlignmentAtomic('0.0000237');

    $result = $job->computeApiable();

    $bybit->refresh();

    expect($result['disabled'] ?? false)->toBeTrue()
        ->and($bybit->is_price_aligned)->toBeFalse()
        ->and($bybit->is_manually_enabled)->toBeFalse();
});

it('keeps a same-unit symbol aligned and enabled when its price matches Binance', function (): void {
    // Same contract unit (an alias like XBT == BTC) — live price ≈ Binance.
    [$job, $bybit] = setupPriceAlignmentAtomic('0.02361');

    $result = $job->computeApiable();

    $bybit->refresh();

    expect($result['aligned'] ?? false)->toBeTrue()
        ->and($bybit->is_price_aligned)->toBeTrue()
        ->and($bybit->is_manually_enabled)->toBeTrue();
});

it('parent selects only naming-divergent symbol_id siblings, not same-name ones', function (): void {
    $binanceSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bybitSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bybit', 'name' => 'Bybit']);

    // Naming-divergent (FLOKI ↔ 1000FLOKI, shared symbol_id 72) → selected.
    ExchangeSymbol::factory()->create(['api_system_id' => $binanceSystem->id, 'token' => '1000FLOKI', 'quote' => 'USDT', 'symbol_id' => 72]);
    $divergent = ExchangeSymbol::factory()->create(['api_system_id' => $bybitSystem->id, 'token' => 'FLOKI', 'quote' => 'USDT', 'symbol_id' => 72]);

    // Same-name (SOL ↔ SOL, shared symbol_id 5) → NOT selected.
    ExchangeSymbol::factory()->create(['api_system_id' => $binanceSystem->id, 'token' => 'SOL', 'quote' => 'USDT', 'symbol_id' => 5]);
    $sameName = ExchangeSymbol::factory()->create(['api_system_id' => $bybitSystem->id, 'token' => 'SOL', 'quote' => 'USDT', 'symbol_id' => 5]);

    $ids = (new VerifyPriceAlignmentsJob)->namingDivergentCandidateIds();

    expect($ids->all())->toContain($divergent->id)
        ->and($ids->all())->not->toContain($sameName->id);
});
