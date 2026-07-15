<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\VerifyPriceAlignmentJob;
use Kraite\Core\Jobs\Lifecycles\ExchangeSymbol\VerifyPriceAlignmentsJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\NotificationMessageBuilder;

/**
 * A non-Binance symbol that lists the same asset under a different contract unit
 * (KuCoin/Bybit FLOKI = 1/1000 of Binance 1000FLOKI) carries a replicated
 * mark_price wrong by the contract ratio. The refresh price-alignment check
 * measures the symbol's own live price against its Binance same-asset sibling
 * and switches off anything that doesn't match within tolerance, keeping it out
 * of trading (scopeTradeable requires is_price_aligned=true).
 */
uses()->group('feature', 'exchange-symbols', 'price-alignment');

function setupPriceAlignmentAtomic(
    string $bybitLivePrice,
    string $binanceMark = '0.0237',
    bool $binanceIsDelisted = false,
): array {
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
        'is_marked_for_delisting' => $binanceIsDelisted,
        'delivery_at' => $binanceIsDelisted ? now()->subMinute() : null,
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

    return [$job, $bybit, $binance];
}

it('blocks a unit-divergent symbol without changing the sysadmin flag', function (): void {
    // Bybit FLOKI live ~0.0000237 vs Binance 1000FLOKI 0.0237 → ratio ~0.001.
    [$job, $bybit] = setupPriceAlignmentAtomic('0.0000237');

    $result = $job->computeApiable();

    $bybit->refresh();

    expect($result['disabled'] ?? false)->toBeTrue()
        ->and($bybit->is_price_aligned)->toBeFalse()
        ->and($bybit->is_manually_enabled)->toBeTrue();
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

it('skips the atomic comparison when the only Binance same-asset reference is delisted', function (): void {
    [$job, $bybit] = setupPriceAlignmentAtomic(
        bybitLivePrice: '0.0000237',
        binanceIsDelisted: true,
    );

    $result = $job->computeApiable();

    $bybit->refresh();

    expect($result['skipped'] ?? null)->toBe('no Binance reference price')
        ->and($bybit->is_price_aligned)->toBeTrue()
        ->and($bybit->is_manually_enabled)->toBeTrue();

    Http::assertNothingSent();
});

it('rechecks a warning-only Binance reference before atomic comparison', function (): void {
    [$job, $bybit, $binance] = setupPriceAlignmentAtomic(
        bybitLivePrice: '0.0000237',
        binanceIsDelisted: true,
    );
    $binance->updateQuietly(['delivery_at' => null]);

    $result = $job->computeApiable();

    expect($result['skipped'] ?? null)->toBe('no Binance reference price')
        ->and($bybit->fresh()->is_price_aligned)->toBeTrue();

    Http::assertNothingSent();
});

it('uses a delisted Binance reference when the target symbol carries an open position', function (): void {
    [$job, $bybit] = setupPriceAlignmentAtomic(
        bybitLivePrice: '0.0000237',
        binanceIsDelisted: true,
    );

    Position::factory()->create([
        'exchange_symbol_id' => $bybit->id,
        'status' => 'active',
    ]);

    $result = $job->computeApiable();

    expect($result['disabled'] ?? false)->toBeTrue()
        ->and($bybit->fresh()->is_price_aligned)->toBeFalse();

    Http::assertSentCount(1);
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

it('parent never selects delisted naming-divergent symbols', function (): void {
    // Production incident 2026-07-11: Bitget's dead TON/USDT row (flagged
    // is_marked_for_delisting after the TON→GRAM rebrand) still shares its
    // symbol_id with Binance's renamed GRAM sibling, so the name diverges and
    // the parent kept selecting it — Bitget answered 40034 twice an hour,
    // 36 failed steps. Delisted rows must never reach the live price check.
    $binanceSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bitgetSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget', 'name' => 'Bitget']);

    ExchangeSymbol::factory()->create(['api_system_id' => $binanceSystem->id, 'token' => 'GRAM', 'quote' => 'USDT', 'symbol_id' => 900]);
    $delisted = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitgetSystem->id, 'token' => 'TON', 'quote' => 'USDT', 'symbol_id' => 900,
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
    ]);
    $alive = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitgetSystem->id, 'token' => '1000TON', 'quote' => 'USDT', 'symbol_id' => 900,
        'is_marked_for_delisting' => false,
    ]);

    $ids = (new VerifyPriceAlignmentsJob)->namingDivergentCandidateIds();

    expect($ids->all())->not->toContain($delisted->id)
        ->and($ids->all())->toContain($alive->id);
});

it('parent selects a delisted naming-divergent symbol when it carries an open position', function (): void {
    $binanceSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bitgetSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget', 'name' => 'Bitget']);

    ExchangeSymbol::factory()->create([
        'api_system_id' => $binanceSystem->id,
        'token' => 'GRAMACTIVE',
        'quote' => 'USDT',
        'symbol_id' => 901,
    ]);
    $delisted = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitgetSystem->id,
        'token' => 'TONACTIVE',
        'quote' => 'USDT',
        'symbol_id' => 901,
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
    ]);
    Position::factory()->create([
        'exchange_symbol_id' => $delisted->id,
        'status' => 'active',
    ]);

    $ids = (new VerifyPriceAlignmentsJob)->namingDivergentCandidateIds();

    expect($ids->all())->toContain($delisted->id);
});

it('parent uses a delisted Binance sibling when the target symbol carries an open position', function (): void {
    $binanceSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bitgetSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget', 'name' => 'Bitget']);

    ExchangeSymbol::factory()->create([
        'api_system_id' => $binanceSystem->id,
        'token' => 'IPACTIVE',
        'quote' => 'USDT',
        'symbol_id' => 35627,
        'is_marked_for_delisting' => true,
        'delivery_at' => now()->subMinute(),
    ]);
    $target = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitgetSystem->id,
        'token' => 'DATAACTIVE',
        'quote' => 'USDT',
        'symbol_id' => 35627,
    ]);
    Position::factory()->create([
        'exchange_symbol_id' => $target->id,
        'status' => 'active',
    ]);

    $ids = (new VerifyPriceAlignmentsJob)->namingDivergentCandidateIds();

    expect($ids->all())->toContain($target->id);
});

it('parent never selects a symbol whose only Binance reference is delisted or past delivery', function (): void {
    $binanceSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $bitgetSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget', 'name' => 'Bitget']);

    ExchangeSymbol::factory()->create([
        'api_system_id' => $binanceSystem->id,
        'token' => 'IP',
        'quote' => 'USDT',
        'symbol_id' => 35626,
        'is_marked_for_delisting' => true,
    ]);
    $data = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitgetSystem->id,
        'token' => 'DATA',
        'quote' => 'USDT',
        'symbol_id' => 35626,
        'is_marked_for_delisting' => false,
    ]);
    ExchangeSymbol::factory()->create([
        'api_system_id' => $binanceSystem->id,
        'token' => 'OLD',
        'quote' => 'USDT',
        'symbol_id' => 45678,
        'is_marked_for_delisting' => false,
        'delivery_at' => now()->subMinute(),
    ]);
    $renamed = ExchangeSymbol::factory()->create([
        'api_system_id' => $bitgetSystem->id,
        'token' => 'NEW',
        'quote' => 'USDT',
        'symbol_id' => 45678,
        'is_marked_for_delisting' => false,
    ]);

    $ids = (new VerifyPriceAlignmentsJob)->namingDivergentCandidateIds();

    expect($ids->all())->not->toContain($data->id)
        ->and($ids->all())->not->toContain($renamed->id);
});

it('describes price divergence as a diagnosis to investigate rather than a proven unit mismatch', function (): void {
    $payload = NotificationMessageBuilder::build('exchange_symbol_price_misaligned', [
        'symbol' => 'DATAUSDT',
        'exchange' => 'bitget',
        'live_price' => 0.2688,
        'binance_price' => 0.29905,
        'ratio' => 0.898846,
    ]);

    expect($payload['emailMessage'])
        ->toContain('may indicate a different contract unit, a token rebrand, or genuine venue price divergence')
        ->not->toContain('which means it lists a different contract unit');
});
