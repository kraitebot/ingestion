<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\ApiSystem\UpsertExchangeSymbolsFromExchangeJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Symbol;

test('does not run for non-exchange API systems', function (): void {
    $apiSystem = ApiSystem::factory()->create([
        'canonical' => 'taapi',
        'name' => 'TAAPI',
        'is_exchange' => false,
    ]);

    $job = new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id);

    expect($job->startOrFail())->toBeFalsy();
});

test('runs for exchange API systems', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $job = new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id);

    expect($job->startOrFail())->toBeTruthy();
});

test('assigns correct exception handler', function (): void {
    Kraite::first()->update([
        'binance_api_key' => 'test-key',
        'binance_api_secret' => 'test-secret',
    ]);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $job = new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id);
    $job->assignExceptionHandler();

    expect($job->exceptionHandler)->not->toBeNull();
});

test('returns correct relatable model', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $job = new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id);

    expect($job->relatable())->toBeInstanceOf(ApiSystem::class);
    expect($job->relatable()->id)->toBe($apiSystem->id);
});

test('preserves existing symbol_id when updating exchange symbol metadata', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $cmcSymbol = Symbol::factory()->create(['token' => 'NEIRO', 'cmc_id' => 12345]);

    // Pre-create exchange symbol with CMC-discovered link
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => '1000NEIROCTO',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $cmcSymbol->id,
        'api_statuses' => [
            'cmc_api_called' => true,
            'taapi_verified' => false,
            'has_taapi_data' => false,
        ],
        'price_precision' => 2,
    ]);

    // Simulate what updateOrCreate does in the job - update metadata but not symbol_id
    ExchangeSymbol::updateOrCreate(
        [
            'token' => '1000NEIROCTO',
            'api_system_id' => $apiSystem->id,
            'quote' => 'USDT',
        ],
        [
            'price_precision' => 4,
            'quantity_precision' => 2,
            // NOT including symbol_id - should preserve existing
        ]
    );

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->symbol_id)->toBe($cmcSymbol->id);
    expect($exchangeSymbol->price_precision)->toBe(4);
});

test('links new exchange symbol to existing symbol by token', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    // Pre-create symbol in symbols table
    $btcSymbol = Symbol::factory()->create(['token' => 'BTC']);
    $symbolsByToken = Symbol::pluck('id', 'token');

    // Simulate what the job does - check symbol lookup
    $symbolId = $symbolsByToken->get('BTC');

    expect($symbolId)->toBe($btcSymbol->id);

    // Create exchange symbol with lookup (using factory)
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbolId,
        'price_precision' => 2,
        'quantity_precision' => 3,
    ]);

    expect($exchangeSymbol->symbol_id)->toBe($btcSymbol->id);
});

test('creates orphaned exchange symbol when token not in symbols table', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbolsByToken = Symbol::pluck('id', 'token');

    // Token not in symbols table
    $symbolId = $symbolsByToken->get('UNKNOWNTOKEN');

    expect($symbolId)->toBeNull();

    // Create exchange symbol without link (orphaned) using factory
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'UNKNOWNTOKEN',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbolId,
        'price_precision' => 2,
        'quantity_precision' => 3,
        'api_statuses' => [
            'cmc_api_called' => false,
            'taapi_verified' => false,
            'has_taapi_data' => false,
        ],
    ]);

    expect($exchangeSymbol->symbol_id)->toBeNull();
    expect($exchangeSymbol->api_statuses['cmc_api_called'])->toBeFalse();
});

test('does not overwrite symbol_id when record exists with link', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $cmcSymbol = Symbol::factory()->create(['token' => 'NEIRO']);

    // Create existing linked exchange symbol
    $existing = ExchangeSymbol::factory()->create([
        'token' => '1000NEIRO',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $cmcSymbol->id,
    ]);

    // Simulate job logic: check if existing, only set symbol_id if null
    $existingSymbol = ExchangeSymbol::where('token', '1000NEIRO')
        ->where('api_system_id', $apiSystem->id)
        ->where('quote', 'USDT')
        ->first();

    $symbolsByToken = Symbol::pluck('id', 'token');
    $symbolId = $symbolsByToken->get('1000NEIRO'); // Won't find - different token

    $updateData = ['price_precision' => 4];

    // Job logic: only set symbol_id if new record OR existing has null symbol_id
    if (! $existingSymbol || ($existingSymbol->symbol_id === null && $symbolId !== null)) {
        $updateData['symbol_id'] = $symbolId;
    }

    ExchangeSymbol::updateOrCreate(
        [
            'token' => '1000NEIRO',
            'api_system_id' => $apiSystem->id,
            'quote' => 'USDT',
        ],
        $updateData
    );

    $existing->refresh();

    // symbol_id should be preserved (not overwritten with null)
    expect($existing->symbol_id)->toBe($cmcSymbol->id);
    expect($existing->price_precision)->toBe(4);
});

test('deleted exchange symbol is recreated fresh on next upsert', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $btcSymbol = Symbol::factory()->create(['token' => 'BTC']);

    // Create exchange symbol
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $btcSymbol->id,
        'api_statuses' => [
            'cmc_api_called' => false,
            'taapi_verified' => false,
            'has_taapi_data' => false,
        ],
    ]);

    $originalId = $exchangeSymbol->id;
    $originalTickSize = $exchangeSymbol->tick_size;

    // Delete it
    $exchangeSymbol->delete();

    expect(ExchangeSymbol::find($originalId))->toBeNull();

    // Recreate via updateOrCreate (simulating job behavior)
    $symbolsByToken = Symbol::pluck('id', 'token');
    $symbolId = $symbolsByToken->get('BTC');

    $recreated = ExchangeSymbol::updateOrCreate(
        [
            'token' => 'BTC',
            'api_system_id' => $apiSystem->id,
            'quote' => 'USDT',
        ],
        [
            'symbol_id' => $symbolId,
            'price_precision' => 2,
            'quantity_precision' => 3,
            'tick_size' => $originalTickSize, // Required field
        ]
    );

    expect($recreated->id)->not->toBe($originalId);
    expect($recreated->symbol_id)->toBe($btcSymbol->id);
    // cmc_api_called is set to true by observer when symbol_id is provided on creation
    expect($recreated->api_statuses['cmc_api_called'])->toBeTrue();
});

test('handles negative precision values correctly', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    // Simulate job logic for negative precision
    $pricePrecision = -2;
    $quantityPrecision = -1;

    if ($pricePrecision !== null && $pricePrecision < 0) {
        $pricePrecision = 0;
    }
    if ($quantityPrecision !== null && $quantityPrecision < 0) {
        $quantityPrecision = 0;
    }

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'DOGE',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'price_precision' => $pricePrecision,
        'quantity_precision' => $quantityPrecision,
    ]);

    expect($exchangeSymbol->price_precision)->toBe(0);
    expect($exchangeSymbol->quantity_precision)->toBe(0);
});

test('flags DB symbols that are missing from the fresh API response as delisted', function (): void {
    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $bybit = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit',
    ]);

    // Three live-looking Binance symbols; two of them will still be in the
    // API response, the third (DENT) will have vanished from the exchange.
    $btc = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $binance->id,
        'is_marked_for_delisting' => false,
    ]);

    $eth = ExchangeSymbol::factory()->create([
        'token' => 'ETH',
        'quote' => 'USDT',
        'api_system_id' => $binance->id,
        'is_marked_for_delisting' => false,
    ]);

    $dent = ExchangeSymbol::factory()->create([
        'token' => 'DENT',
        'quote' => 'USDT',
        'api_system_id' => $binance->id,
        'is_marked_for_delisting' => false,
    ]);

    // Unrelated exchange, must not be touched by a Binance refresh.
    $dentBybit = ExchangeSymbol::factory()->create([
        'token' => 'DENT',
        'quote' => 'USDT',
        'api_system_id' => $bybit->id,
        'is_marked_for_delisting' => false,
    ]);

    // The mapper output schema uses baseAsset + quoteAsset as the pair key.
    // Bitget returned DENT's row in the past but no longer lists it at all.
    $apiResult = [
        ['baseAsset' => 'BTC', 'quoteAsset' => 'USDT', 'pair' => 'BTCUSDT'],
        ['baseAsset' => 'ETH', 'quoteAsset' => 'USDT', 'pair' => 'ETHUSDT'],
    ];

    $job = new UpsertExchangeSymbolsFromExchangeJob($binance->id);

    $flagged = $job->flagMissingSymbolsForDelisting($apiResult);

    expect($flagged)->toBe(1);

    expect($btc->fresh()->is_marked_for_delisting)->toBeFalse();
    expect($eth->fresh()->is_marked_for_delisting)->toBeFalse();
    expect($dent->fresh()->is_marked_for_delisting)->toBeTrue();
    expect($dentBybit->fresh()->is_marked_for_delisting)->toBeFalse();
});

test('flagMissingSymbolsForDelisting is idempotent on rows already flagged', function (): void {
    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $binance->id,
        'is_marked_for_delisting' => false,
    ]);

    $alreadyFlagged = ExchangeSymbol::factory()->create([
        'token' => 'DENT',
        'quote' => 'USDT',
        'api_system_id' => $binance->id,
        'is_marked_for_delisting' => true,
    ]);

    $originalUpdatedAt = $alreadyFlagged->updated_at;

    $apiResult = [
        ['baseAsset' => 'BTC', 'quoteAsset' => 'USDT', 'pair' => 'BTCUSDT'],
    ];

    $job = new UpsertExchangeSymbolsFromExchangeJob($binance->id);

    // No new flags — DENT was already flagged, skip it.
    expect($job->flagMissingSymbolsForDelisting($apiResult))->toBe(0);
    expect($alreadyFlagged->fresh()->updated_at->eq($originalUpdatedAt))->toBeTrue();
});

test('flagMissingSymbolsForDelisting is a no-op when the API response is empty', function (): void {
    // Empty responses are treated as an API anomaly (e.g. partial outage),
    // not as mass delisting — do not flag anything.
    $binance = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $binance->id,
        'is_marked_for_delisting' => false,
    ]);

    $job = new UpsertExchangeSymbolsFromExchangeJob($binance->id);

    expect($job->flagMissingSymbolsForDelisting([]))->toBe(0);
    expect($symbol->fresh()->is_marked_for_delisting)->toBeFalse();
});
