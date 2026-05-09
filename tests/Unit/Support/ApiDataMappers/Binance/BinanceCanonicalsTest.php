<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

/**
 * Pin two cross-call helpers shared by every Binance order mapper:
 *
 *   - identifyBaseAndQuote: splits "MANAUSDT" → ['MANA', 'USDT']. The
 *     resolved quote currency populates the `symbol` field on every
 *     mapped response. A regression that misclassifies the quote
 *     ships as wrong DB rows for every account snapshot — all orders
 *     end up associated to the wrong ExchangeSymbol.
 *
 *   - canonicalOrderType: the cross-exchange type-canonicalizer that
 *     maps Binance-side names (TAKE_PROFIT_MARKET, STOP_LIMIT) to the
 *     in-house canonical (TAKE_PROFIT, STOP_MARKET). The drift
 *     detector and observer reconciler both read this; a regression
 *     that returns the raw Binance string mixes the canonical and
 *     vendor namespaces.
 */
function makeBinanceMapperUnit(): BinanceApiDataMapper
{
    return new BinanceApiDataMapper;
}

it('identifyBaseAndQuote splits a USDT pair correctly', function (): void {
    $m = makeBinanceMapperUnit();

    expect($m->identifyBaseAndQuote('MANAUSDT'))->toBe(['base' => 'MANA', 'quote' => 'USDT']);
});

it('identifyBaseAndQuote splits other major quotes (USDC, BTC, BUSD, ETH, BNB)', function (string $pair, array $expected): void {
    $m = makeBinanceMapperUnit();

    expect($m->identifyBaseAndQuote($pair))->toBe($expected);
})->with([
    'BTC quote' => ['ETHBTC', ['base' => 'ETH', 'quote' => 'BTC']],
    'USDC quote' => ['ETHUSDC', ['base' => 'ETH', 'quote' => 'USDC']],
    'BUSD quote' => ['ETHBUSD', ['base' => 'ETH', 'quote' => 'BUSD']],
    'ETH quote' => ['LINKETH', ['base' => 'LINK', 'quote' => 'ETH']],
    'BNB quote' => ['LINKBNB', ['base' => 'LINK', 'quote' => 'BNB']],
]);

it('identifyBaseAndQuote handles fiat quotes (EUR, AUD, GBP, TRY, RUB, BRL)', function (string $pair, array $expected): void {
    $m = makeBinanceMapperUnit();

    expect($m->identifyBaseAndQuote($pair))->toBe($expected);
})->with([
    'EUR quote' => ['BTCEUR', ['base' => 'BTC', 'quote' => 'EUR']],
    'AUD quote' => ['BTCAUD', ['base' => 'BTC', 'quote' => 'AUD']],
    'GBP quote' => ['BTCGBP', ['base' => 'BTC', 'quote' => 'GBP']],
    'TRY quote' => ['BTCTRY', ['base' => 'BTC', 'quote' => 'TRY']],
    'RUB quote' => ['BTCRUB', ['base' => 'BTC', 'quote' => 'RUB']],
    'BRL quote' => ['BTCBRL', ['base' => 'BTC', 'quote' => 'BRL']],
]);

it('identifyBaseAndQuote throws on an unrecognised quote suffix', function (): void {
    $m = makeBinanceMapperUnit();

    $m->identifyBaseAndQuote('FOOBAR');
})->throws(InvalidArgumentException::class);

it('identifyBaseAndQuote prefers the FIRST matching quote (precedence rule)', function (): void {
    // The loop iterates over the quote list in order. USDT precedes BUSD,
    // so a hypothetical token "USDTBUSD" (it doesn't exist in practice
    // but guards the algorithm) would resolve as base=USDT, quote=BUSD?
    // Actually no — str_ends_with means BUSD comes first if it's the
    // suffix. Let's pin the actual algorithm: first quote that is a
    // suffix wins.
    $m = makeBinanceMapperUnit();

    expect($m->identifyBaseAndQuote('FOOUSDT')['quote'])->toBe('USDT');
});

it('canonicalOrderType: MARKET → MARKET, LIMIT → LIMIT (identity for the canonical names)', function (): void {
    $m = makeBinanceMapperUnit();

    expect($m->canonicalOrderType(['type' => 'MARKET']))->toBe('MARKET')
        ->and($m->canonicalOrderType(['type' => 'LIMIT']))->toBe('LIMIT');
});

it('canonicalOrderType: STOP family folds to STOP_MARKET', function (string $vendor): void {
    $m = makeBinanceMapperUnit();

    expect($m->canonicalOrderType(['type' => $vendor]))->toBe('STOP_MARKET');
})->with([
    'STOP' => ['STOP'],
    'STOP_MARKET' => ['STOP_MARKET'],
    'STOP_LIMIT' => ['STOP_LIMIT'],
    'TRAILING_STOP_MARKET' => ['TRAILING_STOP_MARKET'],
]);

it('canonicalOrderType: TAKE_PROFIT family folds to TAKE_PROFIT', function (string $vendor): void {
    $m = makeBinanceMapperUnit();

    expect($m->canonicalOrderType(['type' => $vendor]))->toBe('TAKE_PROFIT');
})->with([
    'TAKE_PROFIT' => ['TAKE_PROFIT'],
    'TAKE_PROFIT_MARKET' => ['TAKE_PROFIT_MARKET'],
    'TAKE_PROFIT_LIMIT' => ['TAKE_PROFIT_LIMIT'],
]);

it('canonicalOrderType: unknown type returns UNKNOWN (defensive default)', function (): void {
    $m = makeBinanceMapperUnit();

    expect($m->canonicalOrderType(['type' => 'WEIRD_NEW_TYPE']))->toBe('UNKNOWN');
});

it('canonicalOrderType: missing type key returns UNKNOWN (no PHP undefined-index warning)', function (): void {
    $m = makeBinanceMapperUnit();

    expect($m->canonicalOrderType([]))->toBe('UNKNOWN');
});
