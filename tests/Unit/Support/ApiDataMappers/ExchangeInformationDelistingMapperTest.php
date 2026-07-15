<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Bybit\BybitApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Kucoin\KucoinApiDataMapper;

function exchangeInformationResponse(array $body): Response
{
    return new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR));
}

it('retains inactive Binance perpetuals so status changes can be reconciled', function (): void {
    $rows = (new BinanceApiDataMapper)->resolveQueryMarketDataResponse(exchangeInformationResponse([
        'symbols' => [
            [
                'symbol' => 'BTCUSDT',
                'baseAsset' => 'BTC',
                'quoteAsset' => 'USDT',
                'marginAsset' => 'USDT',
                'contractType' => 'PERPETUAL',
                'status' => 'BREAK',
                'deliveryDate' => 4133404800000,
                'onboardDate' => 1569398400000,
                'pricePrecision' => 2,
                'quantityPrecision' => 3,
                'filters' => [],
            ],
        ],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['exchangeStatus'])->toBe('BREAK')
        ->and($rows[0]['isTrading'])->toBeFalse()
        ->and($rows[0]['isDelisted'])->toBeFalse();
});

it('retains ineligible Binance perpetuals as catalogue evidence', function (): void {
    $rows = (new BinanceApiDataMapper)->resolveQueryMarketDataResponse(exchangeInformationResponse([
        'symbols' => [[
            'symbol' => 'USDCUSDT',
            'baseAsset' => 'USDC',
            'quoteAsset' => 'USDT',
            'marginAsset' => 'USDT',
            'contractType' => 'PERPETUAL',
            'status' => 'TRADING',
            'deliveryDate' => 4133404800000,
            'pricePrecision' => 4,
            'quantityPrecision' => 0,
            'filters' => [],
        ]],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['isEligible'])->toBeFalse()
        ->and($rows[0]['isTrading'])->toBeTrue();
});

it('maps Bitget listing and removal timestamps without discarding inactive contracts', function (): void {
    $offTime = now()->subHour()->getTimestampMs();
    $rows = (new BitgetApiDataMapper)->resolveQueryMarketDataResponse(exchangeInformationResponse([
        'data' => [
            [
                'symbol' => 'ETHUSDT',
                'baseCoin' => 'ETH',
                'quoteCoin' => 'USDT',
                'symbolType' => 'perpetual',
                'symbolStatus' => 'normal',
                'launchTime' => '1700000000000',
                'offTime' => '-1',
                'deliveryTime' => '',
                'pricePlace' => '2',
                'volumePlace' => '3',
                'priceEndStep' => '1',
            ],
            [
                'symbol' => 'DATAUSDT',
                'baseCoin' => 'DATA',
                'quoteCoin' => 'USDT',
                'symbolType' => 'perpetual',
                'symbolStatus' => 'off',
                'launchTime' => '1710000000000',
                'offTime' => (string) $offTime,
                'deliveryTime' => '',
                'pricePlace' => '4',
                'volumePlace' => '0',
                'priceEndStep' => '1',
            ],
        ],
    ]));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['onboardDate'])->toBe(1700000000000)
        ->and($rows[0]['deliveryDate'])->toBeNull()
        ->and($rows[0]['isTrading'])->toBeTrue()
        ->and($rows[1]['exchangeStatus'])->toBe('off')
        ->and($rows[1]['deliveryDate'])->toBe($offTime)
        ->and($rows[1]['isTrading'])->toBeFalse()
        ->and($rows[1]['isDelisted'])->toBeTrue();
});

it('preserves explicit Bybit closed state when present in a response', function (): void {
    $rows = (new BybitApiDataMapper)->resolveQueryMarketDataResponse(exchangeInformationResponse([
        'result' => ['list' => [[
            'symbol' => 'DATAUSDT',
            'baseCoin' => 'DATA',
            'quoteCoin' => 'USDT',
            'settleCoin' => 'USDT',
            'contractType' => 'LinearPerpetual',
            'status' => 'Closed',
            'deliveryTime' => (string) now()->subHour()->getTimestampMs(),
            'launchTime' => '1710000000000',
            'priceScale' => '4',
            'priceFilter' => [],
            'lotSizeFilter' => ['qtyStep' => '1'],
        ]]],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['exchangeStatus'])->toBe('Closed')
        ->and($rows[0]['isTrading'])->toBeFalse()
        ->and($rows[0]['isDelisted'])->toBeTrue();
});

it('keeps KuCoin perpetual lifecycle rows and excludes dated contracts', function (): void {
    $expireDate = now()->addDay()->getTimestampMs();
    $rows = (new KucoinApiDataMapper)->resolveQueryMarketDataResponse(exchangeInformationResponse([
        'data' => [
            [
                'symbol' => 'DATAUSDTM',
                'baseCurrency' => 'DATA',
                'quoteCurrency' => 'USDT',
                'settleCurrency' => 'USDT',
                'status' => 'Closed',
                'expireDate' => $expireDate,
                'firstOpenDate' => 1710000000000,
                'isInverse' => false,
                'type' => 'FFWCSX',
                'tickSize' => '0.0001',
                'lotSize' => '1',
            ],
            [
                'symbol' => 'BTCUSDT-30SEP26',
                'baseCurrency' => 'BTC',
                'quoteCurrency' => 'USDT',
                'settleCurrency' => 'USDT',
                'status' => 'Open',
                'expireDate' => $expireDate,
                'isInverse' => false,
                'type' => 'FFICSX',
                'tickSize' => '0.1',
                'lotSize' => '1',
            ],
        ],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['pair'])->toBe('DATAUSDTM')
        ->and($rows[0]['deliveryDate'])->toBe($expireDate)
        ->and($rows[0]['isTrading'])->toBeFalse()
        ->and($rows[0]['isDelisted'])->toBeTrue();
});
