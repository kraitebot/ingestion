<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper;

/**
 * Pin Bitget mapper canonicalisers — the same contract as Binance's
 * but with a different vendor namespace. Plan orders carry `planType`
 * (pos_loss, pos_profit, normal_plan, track_plan); regular orders
 * carry `orderType` (market, limit) plus `triggerPrice` to mark a
 * stop. The mapper folds all of these into the in-house canonical
 * (STOP_MARKET / TAKE_PROFIT / MARKET / LIMIT).
 *
 * A regression that returns the raw vendor name ships as cross-
 * exchange types diverging — the same SL row reads STOP_MARKET on
 * Binance and pos_loss on Bitget, breaking every canonical-type
 * dispatcher branch.
 */
function makeBitgetMapperUnit(): BitgetApiDataMapper
{
    return new BitgetApiDataMapper;
}

it('identifyBaseAndQuote splits Bitget USDT pair', function (): void {
    $m = makeBitgetMapperUnit();

    expect($m->identifyBaseAndQuote('APEUSDT'))->toBe(['base' => 'APE', 'quote' => 'USDT']);
});

it('identifyBaseAndQuote handles USDC and USD quotes', function (string $pair, array $expected): void {
    $m = makeBitgetMapperUnit();

    expect($m->identifyBaseAndQuote($pair))->toBe($expected);
})->with([
    'USDC' => ['BTCUSDC', ['base' => 'BTC', 'quote' => 'USDC']],
    'USD' => ['BTCUSD', ['base' => 'BTC', 'quote' => 'USD']],
]);

it('maps Bitget USDC perpetual contract symbols in both directions', function (): void {
    $m = makeBitgetMapperUnit();

    expect($m->baseWithQuote('BTC', 'USDC'))->toBe('BTCPERP')
        ->and($m->identifyBaseAndQuote('BTCPERP'))->toBe(['base' => 'BTC', 'quote' => 'USDC']);
});

it('identifyBaseAndQuote throws on unrecognised quote', function (): void {
    $m = makeBitgetMapperUnit();
    $m->identifyBaseAndQuote('FOOEUR');
})->throws(InvalidArgumentException::class);

it('canonicalOrderType: pos_loss and loss_plan plan-types fold to STOP_MARKET', function (string $planType): void {
    $m = makeBitgetMapperUnit();

    expect($m->canonicalOrderType(['planType' => $planType]))->toBe('STOP_MARKET');
})->with([
    'pos_loss' => ['pos_loss'],
    'loss_plan' => ['loss_plan'],
]);

it('canonicalOrderType: pos_profit and profit_plan plan-types fold to TAKE_PROFIT', function (string $planType): void {
    $m = makeBitgetMapperUnit();

    expect($m->canonicalOrderType(['planType' => $planType]))->toBe('TAKE_PROFIT');
})->with([
    'pos_profit' => ['pos_profit'],
    'profit_plan' => ['profit_plan'],
]);

it('canonicalOrderType: normal_plan and track_plan fold to STOP_MARKET (default trigger semantics)', function (string $planType): void {
    $m = makeBitgetMapperUnit();

    expect($m->canonicalOrderType(['planType' => $planType]))->toBe('STOP_MARKET');
})->with([
    'normal_plan' => ['normal_plan'],
    'track_plan' => ['track_plan'],
]);

it('canonicalOrderType: unknown planType returns UNKNOWN', function (): void {
    $m = makeBitgetMapperUnit();

    expect($m->canonicalOrderType(['planType' => 'something_new']))->toBe('UNKNOWN');
});

it('canonicalOrderType: regular order with triggerPrice > 0 is STOP_MARKET', function (): void {
    // Bitget signals "this is a stop" via triggerPrice on regular
    // orders too, not just plan-orders. Cross-checking pins the
    // overlap: an order with both planType and orderType present
    // takes the planType branch first; only planType=='' falls
    // through to the regular branch where triggerPrice matters.
    $m = makeBitgetMapperUnit();

    expect($m->canonicalOrderType([
        'planType' => '',
        'orderType' => 'limit',
        'triggerPrice' => '0.05',
    ]))->toBe('STOP_MARKET');
});

it('canonicalOrderType: regular market order (lowercase) folds to MARKET', function (): void {
    $m = makeBitgetMapperUnit();

    expect($m->canonicalOrderType([
        'planType' => '',
        'orderType' => 'market',
        'triggerPrice' => '0',
    ]))->toBe('MARKET');
});

it('canonicalOrderType: regular limit order folds to LIMIT', function (): void {
    $m = makeBitgetMapperUnit();

    expect($m->canonicalOrderType([
        'planType' => '',
        'orderType' => 'limit',
        'triggerPrice' => '0',
    ]))->toBe('LIMIT');
});

it('canonicalOrderType: empty payload returns UNKNOWN (defensive default)', function (): void {
    $m = makeBitgetMapperUnit();

    expect($m->canonicalOrderType([]))->toBe('UNKNOWN');
});
