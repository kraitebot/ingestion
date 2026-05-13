<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Commands\Daemons\StreamBinancePricesCommand;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;

/**
 * Pin the malformed-price guard on the mark-price daemon.
 *
 * Pre-fix, `quoteNumeric()` collapsed every non-numeric value to `'0'`,
 * which produced fresh-timestamped rows with `mark_price=0` in the
 * sidecar. The freshness watchdog passed (timestamp is fresh) while
 * consumers — position sizing math, token-selection scoring,
 * support/resistance gates — read a nonsensical zero.
 *
 * Post-fix, prices are validated through `Math::isPositive` BEFORE
 * reaching the SQL builder. Malformed values (null / empty / non-numeric
 * / zero / negative) are skipped, preserving the previous valid value
 * and timestamp on the sidecar row.
 */
uses(RefreshDatabase::class);

it('source uses Math::isPositive and removed the quoteNumeric zero-fallback', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(StreamBinancePricesCommand::class))->getFileName()
    );

    // The fix introduces Math::isPositive as the gate.
    expect($source)->toContain('Math::isPositive');

    // The old quoteNumeric method is removed entirely. A regression
    // that re-adds it (for any reason) trips this pin.
    expect($source)->not->toContain('private function quoteNumeric');
    expect($source)->not->toContain('is_numeric($value) ? (string) $value : \'0\'');
});

it('updateExchangeSymbols skips non-positive prices and writes only valid ones', function (): void {
    $binance = ApiSystem::factory()->create(['canonical' => 'binance']);

    $valid = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'BTC',
        'quote' => 'USDT',
    ]);
    $zeroPair = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'ZERO',
        'quote' => 'USDT',
    ]);
    $bogus = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'BAD',
        'quote' => 'USDT',
    ]);
    $nullPair = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'NULLP',
        'quote' => 'USDT',
    ]);

    foreach ([$valid, $zeroPair, $bogus, $nullPair] as $sym) {
        ExchangeSymbolPrice::firstOrCreate(['exchange_symbol_id' => $sym->id]);
    }

    // Prime the daemon's pair map without booting ReactPHP.
    $cmd = new StreamBinancePricesCommand;
    $reflection = new ReflectionClass(StreamBinancePricesCommand::class);
    $pairToIds = $reflection->getProperty('pairToIds');
    $pairToIds->setAccessible(true);
    $pairToIds->setValue($cmd, [
        'BTCUSDT' => [$valid->id],
        'ZEROUSDT' => [$zeroPair->id],
        'BADUSDT' => [$bogus->id],
        'NULLPUSDT' => [$nullPair->id],
    ]);

    $update = $reflection->getMethod('updateExchangeSymbols');
    $update->setAccessible(true);
    $update->invoke($cmd, [
        'BTCUSDT' => '50000.12345678',  // valid
        'ZEROUSDT' => '0',              // skipped
        'BADUSDT' => 'not-a-number',    // skipped
        'NULLPUSDT' => null,            // skipped
    ]);

    // Only the BTC sidecar should have been updated. The others retain
    // their initial null mark_price (proving zero was NOT written).
    $btcPrice = DB::table('exchange_symbol_prices')->where('exchange_symbol_id', $valid->id)->first();
    $zeroPrice = DB::table('exchange_symbol_prices')->where('exchange_symbol_id', $zeroPair->id)->first();
    $bogusPrice = DB::table('exchange_symbol_prices')->where('exchange_symbol_id', $bogus->id)->first();
    $nullPrice = DB::table('exchange_symbol_prices')->where('exchange_symbol_id', $nullPair->id)->first();

    expect((float) $btcPrice->mark_price)->toBe(50000.12345678);
    expect($btcPrice->mark_price_synced_at)->not->toBeNull();

    expect($zeroPrice->mark_price)->toBeNull();
    expect($zeroPrice->mark_price_synced_at)->toBeNull();

    expect($bogusPrice->mark_price)->toBeNull();
    expect($bogusPrice->mark_price_synced_at)->toBeNull();

    expect($nullPrice->mark_price)->toBeNull();
    expect($nullPrice->mark_price_synced_at)->toBeNull();
});

it('updateExchangeSymbols preserves a previously-valid price when a malformed one arrives', function (): void {
    $binance = ApiSystem::factory()->create(['canonical' => 'binance']);
    $sym = ExchangeSymbol::factory()->create([
        'api_system_id' => $binance->id,
        'token' => 'ETH',
        'quote' => 'USDT',
    ]);

    // Existing valid price + timestamp on the sidecar.
    DB::table('exchange_symbol_prices')->updateOrInsert(
        ['exchange_symbol_id' => $sym->id],
        [
            'mark_price' => '3000.00000000',
            'mark_price_synced_at' => now()->subSeconds(2),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subSeconds(2),
        ]
    );

    $beforeTs = DB::table('exchange_symbol_prices')->where('exchange_symbol_id', $sym->id)->value('mark_price_synced_at');

    $cmd = new StreamBinancePricesCommand;
    $reflection = new ReflectionClass(StreamBinancePricesCommand::class);
    $pairToIds = $reflection->getProperty('pairToIds');
    $pairToIds->setAccessible(true);
    $pairToIds->setValue($cmd, ['ETHUSDT' => [$sym->id]]);

    $update = $reflection->getMethod('updateExchangeSymbols');
    $update->setAccessible(true);
    $update->invoke($cmd, ['ETHUSDT' => 'BAD-VALUE']);

    $row = DB::table('exchange_symbol_prices')->where('exchange_symbol_id', $sym->id)->first();

    // Both price AND timestamp must be untouched — preserving the
    // previous valid state is the whole point of skipping vs writing
    // zero.
    expect($row->mark_price)->toBe('3000.00000000');
    expect($row->mark_price_synced_at)->toBe($beforeTs);
});
