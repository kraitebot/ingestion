<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper;
use Kraite\Core\Support\Apis\REST\BitgetApi;

/**
 * Regression guard for the missing `closing_price` on Bitget closes
 * (observed 2026-04-26 — position 420 ETHUSDT manually closed; logs
 * showed: "Failed to get closing price for position 420: Method
 * prepareQueryTokenTradesProperties does not exist for this API.").
 *
 * Two cross-exchange interface contracts must hold for Bitget:
 *
 * 1. `BitgetApiDataMapper` MUST expose `prepareQueryTokenTradesProperties`
 *    and `resolveQueryTradeResponse` — same method names the other
 *    exchange mappers (Binance / Bybit / KuCoin) use. The trade-fetch
 *    chain in `Position::apiQueryTokenTrades()` calls these names by
 *    convention; mismatched names silently break `closing_price`
 *    population in `UpdateRemainingClosingDataJob`.
 *
 * 2. `BitgetApi` MUST expose `accountTrades(ApiProperties)` — same
 *    method name `BinanceApi::accountTrades()` uses. The position-level
 *    interactor calls `$account->withApi()->accountTrades(...)` blindly
 *    by name; without it, the call fails with "Method accountTrades does
 *    not exist for this API" the same way.
 */
function buildBitgetTradeQueryFixture(): Position
{
    $token = 'CLT'.mb_strtoupper(Str::random(4));

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'BitGet',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'asset' => $token.'USDT',
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
    ]);
}

it('exposes prepareQueryTokenTradesProperties on the Bitget mapper (cross-exchange interface name)', function (): void {
    expect(method_exists(BitgetApiDataMapper::class, 'prepareQueryTokenTradesProperties'))->toBeTrue(
        'Bitget mapper must use the same method name as Binance/Bybit/KuCoin so the '
        .'`UpdateRemainingClosingDataJob` trade-query chain works for Bitget closes.'
    );
});

it('exposes resolveQueryTradeResponse on the Bitget mapper (cross-exchange interface name)', function (): void {
    expect(method_exists(BitgetApiDataMapper::class, 'resolveQueryTradeResponse'))->toBeTrue(
        'Bitget mapper must use the same method name the other exchange mappers use; '
        .'the position-level trade fetch resolves the response by this name only.'
    );
});

it('exposes accountTrades on BitgetApi (cross-exchange interface name)', function (): void {
    expect(method_exists(BitgetApi::class, 'accountTrades'))->toBeTrue(
        'BitgetApi must expose `accountTrades` — Position::apiQueryTokenTrades() calls it '
        .'by name on the proxied API, identical to BinanceApi.'
    );
});

it('parses a Bitget fillList close response into the flat shape extractClosingPriceFromTrades expects', function (): void {
    $mapper = new BitgetApiDataMapper;

    // Real-world Bitget V2 fillList shape — newest-first order, with
    // `side: "buy"` on BOTH open and close fills (hedge-mode: side stays
    // as the original opening direction; tradeSide is the open/close
    // discriminator). Mapper reverses to oldest-first AND flips the close
    // fill's side so the cross-exchange `extractClosingPriceFromTrades`
    // matches `closeSide=SELL` for LONG.
    $response = new Response(
        200,
        ['Content-Type' => 'application/json'],
        (string) json_encode([
            'code' => '00000',
            'data' => [
                'fillList' => [
                    [
                        'tradeId' => 'close-1',
                        'symbol' => 'ETHUSDT',
                        'orderId' => 'order-close',
                        'price' => '2360.50',
                        'baseVolume' => '0.01',
                        'side' => 'buy',
                        'tradeSide' => 'close',
                        'cTime' => '1730000600000',
                    ],
                    [
                        'tradeId' => 'open-1',
                        'symbol' => 'ETHUSDT',
                        'orderId' => 'order-open',
                        'price' => '2343.95',
                        'baseVolume' => '0.01',
                        'side' => 'buy',
                        'tradeSide' => 'open',
                        'cTime' => '1730000000000',
                    ],
                ],
                'endId' => 'close-1',
            ],
            'msg' => 'success',
        ]),
    );

    $result = $mapper->resolveQueryTradeResponse($response);

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2)
        ->and($result[0]['tradeSide'])->toBe('open')
        ->and($result[0]['side'])->toBe('buy')
        ->and($result[1]['tradeSide'])->toBe('close')
        ->and($result[1]['price'])->toBe('2360.50')
        ->and($result[1]['side'])->toBe('sell');
});

it('builds Bitget trade-query properties scoped to the position symbol', function (): void {
    $position = buildBitgetTradeQueryFixture();
    $mapper = new BitgetApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position);

    expect($properties->get('options.productType'))->toBe('USDT-FUTURES')
        ->and($properties->get('options.symbol'))->toBe($position->exchangeSymbol->asset);
});
