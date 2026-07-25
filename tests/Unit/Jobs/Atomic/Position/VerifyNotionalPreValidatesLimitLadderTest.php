<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\Position\VerifyOrderNotionalForMarketOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Trading\Kraite;

/**
 * Regression guard for the USELESS #64 incident: the market filled, the limit
 * ladder then failed min_notional, and the cancel workflow was forced to
 * unwind the orphaned entry at a realized loss. The fix simulates the DCA
 * ladder inside VerifyOrderNotionalForMarketOrderJob so an infeasible ladder
 * aborts the workflow before PlaceMarketOrderJob runs.
 *
 * These tests assert the contract at two levels:
 *
 *   1. The ladder calculator throws for the exact USELESS-shaped scenario, so
 *      calling it from the pre-gate produces the desired abort. The original
 *      incident's root cause was a corrupt `limit_quantity_multipliers` JSON
 *      column on the exchange_symbol (`[0.2, 0.2, 2, 2]` instead of the
 *      default `[2, 2, 2, 2]`) — rung #1 quantity shrank to 83 and its
 *      notional at the SHORT entry price of ~0.04466 came in at $3.71,
 *      below the $5 min_notional floor. That's the shape we pin here.
 *   2. The pre-gate method references the calculator by name, so future edits
 *      can't silently regress the call site.
 */
it('the limit ladder calculator rejects a USELESS-shaped rung before any market placement', function (): void {
    $symbol = new ExchangeSymbol;
    $symbol->parsed_trading_pair = 'USELESSUSDT';
    $symbol->min_notional = '5.00000000';
    $symbol->percentage_gap_short = '9.50';
    $symbol->percentage_gap_long = '8.50';
    $symbol->min_price = null;
    $symbol->max_price = null;
    $symbol->tick_size = '0.0000001';
    $symbol->step_size = '1';
    $symbol->price_precision = 7;
    $symbol->quantity_precision = 0;

    expect(function () use ($symbol): void {
        Kraite::calculateLimitOrdersData(
            totalLimitOrders: 4,
            direction: 'SHORT',
            referencePrice: '0.0407820',
            marketOrderQty: '415',
            exchangeSymbol: $symbol,
            limitQuantityMultipliers: [0.2, 0.2, 2, 2],
        );
    })->toThrow(RuntimeException::class, 'below min_notional');
});

it('VerifyOrderNotionalForMarketOrderJob calls the ladder calculator inside computeApiable', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(VerifyOrderNotionalForMarketOrderJob::class))->getFileName()
    );

    expect($source)->toContain('Kraite::calculateLimitOrdersData(');
    expect($source)->toContain('referencePrice: $markPrice');
    expect($source)->toContain('marketOrderQty: $marketOrderQuantity');
});

it('sizes and validates the market order from the fresh REST snapshot instead of a stale sidecar price', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance notional snapshot',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'SNAP']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => 'SNAP',
        'quote' => 'USDT',
        'mark_price' => '99',
        'price_precision' => 2,
        'quantity_precision' => 3,
        'tick_size' => '0.01',
        'min_price' => '0.01',
        'max_price' => '1000',
        'min_notional' => '5',
        'limit_quantity_multipliers' => [2, 2, 2, 2],
    ]);
    ExchangeSymbolPrice::updateOrCreate(
        ['exchange_symbol_id' => $exchangeSymbol->id],
        ['mark_price' => '1', 'mark_price_synced_at' => now()->subMinute()],
    );
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);
    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
        'margin' => '50',
        'leverage' => 20,
        'total_limit_orders' => 4,
    ]);

    Http::fake([
        '*' => Http::response(['markPrice' => '10'], 200),
    ]);

    $result = (new VerifyOrderNotionalForMarketOrderJob($position->id))->computeApiable();
    $exchangeSymbol->unsetRelation('priceRow');

    expect($result['mark_price'])->toBe('10')
        ->and($result['market_order_quantity'])->toBe('3.125')
        ->and($exchangeSymbol->mark_price)->toBe('10.00000000')
        ->and($exchangeSymbol->priceRow?->mark_price)->toBe('10.00000000')
        ->and($exchangeSymbol->getRawOriginal('mark_price'))->toBe('99');
});
