<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsOrderModify;

/**
 * Pin computeOrderModifyPrice() — the order-type-aware "what does
 * 'price' mean for THIS shape" mapper. Drift detection, modification
 * verification, and TP-fill detection all read this value back from
 * the exchange. A regression that returns price for STOP_MARKET (which
 * always carries price=0 on Binance) ships as the drift loop seeing
 * "0 vs reference_price=10" on every STOP_MARKET sync — false drift
 * cascades, cancel/recreate storms.
 */
function makeOrderModifyMapper(): object
{
    return new class
    {
        use MapsOrderModify {
            computeOrderModifyPrice as public;
        }
    };
}

it('LIMIT: returns the order price field', function (): void {
    $m = makeOrderModifyMapper();

    expect($m->computeOrderModifyPrice([
        'type' => 'LIMIT',
        'price' => '0.10',
        'stopPrice' => '0',
        'avgPrice' => '0',
    ]))->toBe('0.10');
});

it('MARKET: returns avgPrice when filled (avgPrice > 0)', function (): void {
    $m = makeOrderModifyMapper();

    expect($m->computeOrderModifyPrice([
        'type' => 'MARKET',
        'price' => '0',
        'avgPrice' => '0.123',
    ]))->toBe('0.123');
});

it('MARKET: returns 0 when not yet filled (avgPrice=0)', function (): void {
    $m = makeOrderModifyMapper();

    expect($m->computeOrderModifyPrice([
        'type' => 'MARKET',
        'price' => '0',
        'avgPrice' => '0',
    ]))->toBe('0');
});

it('STOP_MARKET: returns stopPrice (Binance always sends price=0 for stops)', function (string $stopType): void {
    $m = makeOrderModifyMapper();

    expect($m->computeOrderModifyPrice([
        'type' => $stopType,
        'price' => '0',
        'stopPrice' => '0.05',
    ]))->toBe('0.05');
})->with([
    'STOP_MARKET' => ['STOP_MARKET'],
    'STOP_LIMIT' => ['STOP_LIMIT'],
    'STOP' => ['STOP'],
]);

it('TAKE_PROFIT family: returns stopPrice', function (string $tpType): void {
    $m = makeOrderModifyMapper();

    expect($m->computeOrderModifyPrice([
        'type' => $tpType,
        'price' => '0',
        'stopPrice' => '0.20',
    ]))->toBe('0.20');
})->with([
    'TAKE_PROFIT' => ['TAKE_PROFIT'],
    'TAKE_PROFIT_LIMIT' => ['TAKE_PROFIT_LIMIT'],
    'TAKE_PROFIT_MARKET' => ['TAKE_PROFIT_MARKET'],
]);

it('TRAILING_STOP_MARKET: returns activatePrice when set, else falls back to stopPrice', function (): void {
    $m = makeOrderModifyMapper();

    expect($m->computeOrderModifyPrice([
        'type' => 'TRAILING_STOP_MARKET',
        'price' => '0',
        'stopPrice' => '0.15',
        'activatePrice' => '0.18',
    ]))->toBe('0.18');

    expect($m->computeOrderModifyPrice([
        'type' => 'TRAILING_STOP_MARKET',
        'price' => '0',
        'stopPrice' => '0.15',
        'activatePrice' => '0',
    ]))->toBe('0.15');
});

it('default branch: returns price when > 0, else stopPrice', function (): void {
    $m = makeOrderModifyMapper();

    expect($m->computeOrderModifyPrice([
        'type' => 'UNKNOWN_TYPE',
        'price' => '0.42',
        'stopPrice' => '0.05',
    ]))->toBe('0.42');

    expect($m->computeOrderModifyPrice([
        'type' => 'UNKNOWN_TYPE',
        'price' => '0',
        'stopPrice' => '0.05',
    ]))->toBe('0.05');
});
