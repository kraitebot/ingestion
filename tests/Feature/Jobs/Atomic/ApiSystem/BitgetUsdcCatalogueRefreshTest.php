<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\ApiSystem\UpsertExchangeSymbolsFromExchangeJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Symbol;

function bitgetUsdcCatalogueContract(
    string $symbol,
    string $baseCoin,
    string $quoteCoin,
    string $priceEndStep,
    string $pricePlace,
    string $volumePlace,
    string $minNotional = '5'
): array {
    return [
        'symbol' => $symbol,
        'baseCoin' => $baseCoin,
        'quoteCoin' => $quoteCoin,
        'supportMarginCoins' => [$quoteCoin],
        'minTradeNum' => '0.001',
        'priceEndStep' => $priceEndStep,
        'volumePlace' => $volumePlace,
        'pricePlace' => $pricePlace,
        'sizeMultiplier' => '0.001',
        'symbolType' => 'perpetual',
        'minTradeUSDT' => $minNotional,
        'symbolStatus' => 'normal',
        'offTime' => '-1',
        'deliveryTime' => '',
        'launchTime' => '1700000000000',
    ];
}

function bitgetUsdcCatalogueEnvelope(array $contracts, string $code = '00000', string $message = 'success'): array
{
    return [
        'code' => $code,
        'msg' => $message,
        'requestTime' => 1_770_000_000_000,
        'data' => $contracts,
    ];
}

function bitgetUsdcCatalogueApiSystem(): ApiSystem
{
    Kraite::findOrFail(1)->update([
        'bitget_api_key' => 'CATALOGUE_TEST_KEY',
        'bitget_api_secret' => 'CATALOGUE_TEST_SECRET',
        'bitget_passphrase' => 'CATALOGUE_TEST_PASSPHRASE',
    ]);

    return ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget USDC Catalogue Test',
    ]);
}

it('requests both Bitget futures products and persists separate USDT and USDC contracts', function (): void {
    $apiSystem = bitgetUsdcCatalogueApiSystem();
    $symbol = Symbol::factory()->create(['token' => 'BTC']);

    expect(ExchangeSymbol::query()
        ->whereBelongsTo($apiSystem)
        ->where('token', 'BTC')
        ->exists())->toBeFalse();

    Http::fakeSequence()
        ->push(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract('BTCUSDT', 'BTC', 'USDT', '1', '1', '4'),
        ]), 200)
        ->push(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract('BTCPERP', 'BTC', 'USDC', '5', '2', '3', '10'),
        ]), 200);

    $result = (new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id))->computeApiable();
    $rows = ExchangeSymbol::query()
        ->whereBelongsTo($apiSystem)
        ->where('token', 'BTC')
        ->orderBy('quote')
        ->get();

    expect($result['upserted'])->toBe(2)
        ->and($result['total_from_api'])->toBe(2)
        ->and($result['marked_for_delisting'])->toBe(0)
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('quote')->all())->toBe(['USDC', 'USDT'])
        ->and($rows->every(fn (ExchangeSymbol $row): bool => $row->symbol_id === $symbol->id))->toBeTrue();

    $usdc = $rows->sole(fn (ExchangeSymbol $row): bool => $row->quote === 'USDC');
    $usdt = $rows->sole(fn (ExchangeSymbol $row): bool => $row->quote === 'USDT');

    expect($usdc->asset)->toBe('BTCPERP')
        ->and($usdc->quote)->toBe('USDC')
        ->and($usdc->price_precision)->toBe(2)
        ->and($usdc->quantity_precision)->toBe(3)
        ->and($usdc->tick_size)->toBe('0.050000000000000000')
        ->and($usdc->min_notional)->toBe('10.00000000')
        ->and($usdt->asset)->toBe('BTCUSDT')
        ->and($usdt->quote)->toBe('USDT')
        ->and($usdt->tick_size)->toBe('0.100000000000000000')
        ->and($usdt->min_notional)->toBe('5.00000000');

    $productTypes = Http::recorded()
        ->map(static fn (array $exchange): ?string => $exchange[0]['productType'])
        ->values()
        ->all();

    expect($productTypes)->toBe(['USDT-FUTURES', 'USDC-FUTURES']);
});

it('preserves Bitget USDC tick sizes smaller than one hundred-millionth', function (): void {
    $apiSystem = bitgetUsdcCatalogueApiSystem();
    Symbol::factory()->create(['token' => 'PEPE']);

    expect(ExchangeSymbol::query()
        ->whereBelongsTo($apiSystem)
        ->where('token', 'PEPE')
        ->exists())->toBeFalse();

    Http::fakeSequence()
        ->push(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract('PEPEUSDT', 'PEPE', 'USDT', '1', '8', '0'),
        ]), 200)
        ->push(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract('PEPEPERP', 'PEPE', 'USDC', '1', '10', '0'),
        ]), 200);

    (new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id))->computeApiable();

    $usdc = ExchangeSymbol::query()
        ->whereBelongsTo($apiSystem)
        ->where('token', 'PEPE')
        ->where('quote', 'USDC')
        ->sole();

    expect($usdc->tick_size)->toBe('0.000000000100000000');
});

it('does not persist or delist any symbol when either Bitget catalogue request fails', function (string $failedProduct): void {
    $apiSystem = bitgetUsdcCatalogueApiSystem();
    $existingUsdt = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'KEEPUSDT',
        'quote' => 'USDT',
        'is_marked_for_delisting' => false,
        'delivery_at' => null,
    ]);
    $existingUsdc = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'KEEPUSDC',
        'quote' => 'USDC',
        'is_marked_for_delisting' => false,
        'delivery_at' => null,
    ]);

    Http::fake(static function (Request $request) use ($failedProduct) {
        $productType = $request['productType'];

        if ($productType === $failedProduct) {
            return Http::response(
                bitgetUsdcCatalogueEnvelope([], '40034', "failed {$failedProduct}"),
                200
            );
        }

        $quote = $productType === 'USDC-FUTURES' ? 'USDC' : 'USDT';
        $pair = $quote === 'USDC' ? 'NEWPERP' : 'NEWUSDT';

        return Http::response(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract($pair, 'NEW', $quote, '1', '2', '3'),
        ]), 200);
    });

    expect(fn (): array => (new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id))->computeApiable())
        ->toThrow(RequestException::class, "Bitget API error (code 40034): failed {$failedProduct}");

    expect(ExchangeSymbol::query()
        ->whereBelongsTo($apiSystem)
        ->where('token', 'NEW')
        ->exists())->toBeFalse()
        ->and($existingUsdt->fresh()->is_marked_for_delisting)->toBeFalse()
        ->and($existingUsdt->fresh()->delivery_at)->toBeNull()
        ->and($existingUsdc->fresh()->is_marked_for_delisting)->toBeFalse()
        ->and($existingUsdc->fresh()->delivery_at)->toBeNull();
})->with([
    'USDT acquisition fails' => ['USDT-FUTURES'],
    'USDC acquisition fails after USDT succeeds' => ['USDC-FUTURES'],
]);

it('treats an empty successful product catalogue as terminal before reconciliation', function (string $emptyProduct): void {
    $apiSystem = bitgetUsdcCatalogueApiSystem();
    $existingUsdt = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'KEEPUSDT',
        'quote' => 'USDT',
        'is_marked_for_delisting' => false,
        'delivery_at' => null,
    ]);
    $existingUsdc = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'KEEPUSDC',
        'quote' => 'USDC',
        'is_marked_for_delisting' => false,
        'delivery_at' => null,
    ]);

    Http::fake(static function (Request $request) use ($emptyProduct) {
        $productType = $request['productType'];

        if ($productType === $emptyProduct) {
            return Http::response(bitgetUsdcCatalogueEnvelope([]), 200);
        }

        $quote = $productType === 'USDC-FUTURES' ? 'USDC' : 'USDT';

        return Http::response(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract(
                $quote === 'USDC' ? 'NEWPERP' : 'NEWUSDT',
                'NEW',
                $quote,
                '1',
                '2',
                '3'
            ),
        ]), 200);
    });

    expect(fn (): array => (new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id))->computeApiable())
        ->toThrow(UnexpectedValueException::class, 'Bitget futures catalogue response is empty.');

    expect(ExchangeSymbol::query()
        ->whereBelongsTo($apiSystem)
        ->where('token', 'NEW')
        ->exists())->toBeFalse()
        ->and($existingUsdt->fresh()->is_marked_for_delisting)->toBeFalse()
        ->and($existingUsdt->fresh()->delivery_at)->toBeNull()
        ->and($existingUsdc->fresh()->is_marked_for_delisting)->toBeFalse()
        ->and($existingUsdc->fresh()->delivery_at)->toBeNull();
})->with([
    'USDT catalogue empty' => ['USDT-FUTURES'],
    'USDC catalogue empty after USDT succeeds' => ['USDC-FUTURES'],
]);

it('keeps both product families live across later complete Bitget refreshes', function (): void {
    $apiSystem = bitgetUsdcCatalogueApiSystem();

    Http::fakeSequence()
        ->push(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract('ETHUSDT', 'ETH', 'USDT', '1', '2', '3'),
        ]), 200)
        ->push(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract('ETHPERP', 'ETH', 'USDC', '1', '2', '3'),
        ]), 200)
        ->push(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract('ETHUSDT', 'ETH', 'USDT', '1', '2', '3'),
        ]), 200)
        ->push(bitgetUsdcCatalogueEnvelope([
            bitgetUsdcCatalogueContract('ETHPERP', 'ETH', 'USDC', '1', '2', '3'),
        ]), 200);

    $job = new UpsertExchangeSymbolsFromExchangeJob($apiSystem->id);
    $job->computeApiable();

    $rowsBefore = ExchangeSymbol::query()
        ->whereBelongsTo($apiSystem)
        ->where('token', 'ETH')
        ->orderBy('quote')
        ->get();

    expect($rowsBefore)->toHaveCount(2)
        ->and($rowsBefore->pluck('is_marked_for_delisting')->all())->toBe([false, false]);

    $result = $job->computeApiable();
    $rowsAfter = ExchangeSymbol::query()
        ->whereBelongsTo($apiSystem)
        ->where('token', 'ETH')
        ->orderBy('quote')
        ->get();

    expect($result['marked_for_delisting'])->toBe(0)
        ->and($rowsAfter)->toHaveCount(2)
        ->and($rowsAfter->pluck('quote')->all())->toBe(['USDC', 'USDT'])
        ->and($rowsAfter->pluck('is_marked_for_delisting')->all())->toBe([false, false])
        ->and($rowsAfter->pluck('delivery_at')->all())->toBe([null, null]);

    Http::assertSentCount(4);
});
