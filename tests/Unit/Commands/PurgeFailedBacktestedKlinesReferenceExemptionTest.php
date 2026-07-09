<?php

declare(strict_types=1);

use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;

function makeRejectedSymbolWithCandles(array $attributes): ExchangeSymbol
{
    $symbol = ExchangeSymbol::factory()->create(array_merge(
        ['backtesting_review_status' => 'rejected'],
        $attributes,
    ));

    Candle::factory()->count(3)->create(['exchange_symbol_id' => $symbol->id]);

    return $symbol;
}

test('purges candles for ordinary rejected symbols', function (): void {
    $ordinary = makeRejectedSymbolWithCandles(['token' => 'DOGE', 'quote' => 'USDT', 'asset' => 'DOGEUSDT']);

    $this->artisan('kraite:cron-purge-failed-backtested-klines')->assertSuccessful();

    expect(Candle::where('exchange_symbol_id', $ordinary->id)->count())->toBe(0);
});

test('never purges the BTC reference token, even when rejected', function (): void {
    $btc = makeRejectedSymbolWithCandles(['token' => 'BTC', 'quote' => 'USDT', 'asset' => 'BTCUSDT']);
    $ordinary = makeRejectedSymbolWithCandles(['token' => 'DOGE', 'quote' => 'USDT', 'asset' => 'DOGEUSDT']);

    $this->artisan('kraite:cron-purge-failed-backtested-klines')->assertSuccessful();

    expect(Candle::where('exchange_symbol_id', $btc->id)->count())->toBe(3)
        ->and(Candle::where('exchange_symbol_id', $ordinary->id)->count())->toBe(0);
});

test('never purges market-regime basket members, even when rejected', function (): void {
    config()->set('kraite.market_regime.symbols', ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT']);

    $eth = makeRejectedSymbolWithCandles(['token' => 'ETH', 'quote' => 'USDT', 'asset' => 'ETHUSDT']);

    $this->artisan('kraite:cron-purge-failed-backtested-klines')->assertSuccessful();

    expect(Candle::where('exchange_symbol_id', $eth->id)->count())->toBe(3);
});

test('a rejected symbol without an asset value is still purged', function (): void {
    $nullAsset = makeRejectedSymbolWithCandles(['token' => 'SKY', 'quote' => 'USDT', 'asset' => null]);

    $this->artisan('kraite:cron-purge-failed-backtested-klines')->assertSuccessful();

    expect(Candle::where('exchange_symbol_id', $nullAsset->id)->count())->toBe(0);
});

test('approved and unreviewed symbols keep their candles', function (): void {
    $approved = ExchangeSymbol::factory()->create(['token' => 'LTC', 'quote' => 'USDT', 'asset' => 'LTCUSDT', 'backtesting_review_status' => 'approved']);
    $unreviewed = ExchangeSymbol::factory()->create(['token' => 'ETC', 'quote' => 'USDT', 'asset' => 'ETCUSDT', 'backtesting_review_status' => null]);
    Candle::factory()->count(2)->create(['exchange_symbol_id' => $approved->id]);
    Candle::factory()->count(2)->create(['exchange_symbol_id' => $unreviewed->id]);

    $this->artisan('kraite:cron-purge-failed-backtested-klines')->assertSuccessful();

    expect(Candle::where('exchange_symbol_id', $approved->id)->count())->toBe(2)
        ->and(Candle::where('exchange_symbol_id', $unreviewed->id)->count())->toBe(2);
});
