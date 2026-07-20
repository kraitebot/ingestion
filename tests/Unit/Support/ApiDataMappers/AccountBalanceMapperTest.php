<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Bybit\BybitApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Kucoin\KucoinApiDataMapper;

function balanceMapperAccount(array $attributes = []): Account
{
    return new Account(array_merge([
        'portfolio_quote' => 'USDC',
        'trading_quote' => 'USDT',
    ], $attributes));
}

function jsonBalanceResponse(array $payload): Response
{
    return new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
}

it('maps Binance balance using the account portfolio quote and exposes total and available balances', function (): void {
    $mapper = new BinanceApiDataMapper;
    $account = balanceMapperAccount();

    $balance = $mapper->resolveGetBalanceResponse(jsonBalanceResponse([
        ['asset' => 'USDT', 'balance' => '100.00', 'availableBalance' => '60.00', 'crossWalletBalance' => '100.00', 'crossUnPnl' => '1.00'],
        ['asset' => 'USDC', 'balance' => '200.00', 'availableBalance' => '150.00', 'crossWalletBalance' => '198.00', 'crossUnPnl' => '2.00'],
    ]), $account);

    expect($balance)
        ->toMatchArray([
            'total-wallet-balance' => '200.00',
            'wallet-balance' => '200.00',
            'available-balance' => '150.00',
            'cross-wallet-balance' => '198.00',
            'cross-unrealized-pnl' => '2.00',
        ]);
});

it('maps Bybit balance using the account portfolio quote and exposes total and available balances', function (): void {
    $mapper = new BybitApiDataMapper;
    $account = balanceMapperAccount();

    $balance = $mapper->resolveGetBalanceResponse(jsonBalanceResponse([
        'result' => [
            'list' => [[
                'coin' => [
                    ['coin' => 'USDT', 'walletBalance' => '100.00', 'locked' => '10.00', 'unrealisedPnl' => '1.00'],
                    ['coin' => 'USDC', 'walletBalance' => '200.00', 'locked' => '20.00', 'unrealisedPnl' => '2.00'],
                ],
            ]],
        ],
    ]), $account);

    expect($balance)
        ->toMatchArray([
            'total-wallet-balance' => '200.00',
            'wallet-balance' => '200.00',
            'available-balance' => '180.00000000',
            'cross-wallet-balance' => '200.00',
            'cross-unrealized-pnl' => '2.00',
        ]);
});

it('prepares KuCoin balance queries with portfolio quote and exposes total and available balances', function (): void {
    $mapper = new KucoinApiDataMapper;
    $account = balanceMapperAccount();

    expect($mapper->prepareGetBalanceProperties($account)->get('options.currency'))->toBe('USDC');

    $balance = $mapper->resolveGetBalanceResponse(jsonBalanceResponse([
        'code' => '200000',
        'data' => [
            'accountEquity' => 250.75,
            'marginBalance' => 240.50,
            'availableBalance' => 200.25,
            'unrealisedPNL' => 10.25,
            'currency' => 'USDC',
        ],
    ]), $account);

    expect($balance)
        ->toMatchArray([
            'total-wallet-balance' => '250.75',
            'wallet-balance' => '240.5',
            'available-balance' => '200.25',
            'cross-wallet-balance' => '250.75',
            'cross-unrealized-pnl' => '10.25',
        ]);
});

it('prepares Bitget balance queries per portfolio quote and exposes total and available balances', function (): void {
    $mapper = new BitgetApiDataMapper;
    $account = balanceMapperAccount();

    expect($mapper->prepareGetBalanceProperties($account)->get('options.productType'))->toBe('USDC-FUTURES');

    $balance = $mapper->resolveGetBalanceResponse(jsonBalanceResponse([
        'code' => '00000',
        'data' => [
            ['marginCoin' => 'USDT', 'accountEquity' => '100.00', 'available' => '60.00', 'unrealizedPL' => '1.00'],
            ['marginCoin' => 'USDC', 'accountEquity' => '200.00', 'available' => '150.00', 'unrealizedPL' => '2.00'],
        ],
    ]), $account);

    expect($balance)
        ->toMatchArray([
            'total-wallet-balance' => '200.00',
            'wallet-balance' => '200.00',
            'available-balance' => '150.00',
            'cross-wallet-balance' => '200.00',
            'cross-unrealized-pnl' => '2.00',
        ]);
});

it('maps Bitget unified (v3 assets) balance for the portfolio quote with zero PnL', function (): void {
    $mapper = new BitgetApiDataMapper;
    $account = balanceMapperAccount();

    $balance = $mapper->resolveGetBalanceResponse(jsonBalanceResponse([
        'code' => '00000',
        'data' => [
            'accountEquity' => '311.50',
            'unrealisedPnl' => '3.00',
            'assets' => [
                ['coin' => 'USDT', 'equity' => '100.00', 'available' => '60.00'],
                ['coin' => 'USDC', 'equity' => '211.50', 'available' => '150.00'],
            ],
        ],
    ]), $account);

    expect($balance)
        ->toMatchArray([
            'total-wallet-balance' => '211.50',
            'wallet-balance' => '211.50',
            'available-balance' => '150.00',
            'cross-wallet-balance' => '211.50',
            'cross-unrealized-pnl' => '0',
        ]);
});

it('maps a Bitget unified balance to zeros when the quote coin is not held', function (): void {
    $mapper = new BitgetApiDataMapper;
    $account = balanceMapperAccount();

    $balance = $mapper->resolveGetBalanceResponse(jsonBalanceResponse([
        'code' => '00000',
        'data' => [
            'accountEquity' => '100.00',
            'assets' => [
                ['coin' => 'USDT', 'equity' => '100.00', 'available' => '60.00'],
            ],
        ],
    ]), $account);

    expect($balance)
        ->toMatchArray([
            'total-wallet-balance' => '0',
            'wallet-balance' => '0',
            'available-balance' => '0',
            'cross-wallet-balance' => '0',
            'cross-unrealized-pnl' => '0',
        ]);
});

it('maps a Bitget unified balance with an empty assets list to zeros', function (): void {
    $mapper = new BitgetApiDataMapper;
    $account = balanceMapperAccount();

    $balance = $mapper->resolveGetBalanceResponse(jsonBalanceResponse([
        'code' => '00000',
        'data' => ['accountEquity' => '0', 'assets' => []],
    ]), $account);

    expect($balance['total-wallet-balance'])->toBe('0')
        ->and($balance['available-balance'])->toBe('0');
});

it('falls back to trading quote when portfolio quote is empty', function (): void {
    $mapper = new BinanceApiDataMapper;
    $account = balanceMapperAccount([
        'portfolio_quote' => null,
        'trading_quote' => 'BTC',
    ]);

    $balance = $mapper->resolveGetBalanceResponse(jsonBalanceResponse([
        ['asset' => 'USDT', 'balance' => '100.00', 'availableBalance' => '60.00'],
        ['asset' => 'BTC', 'balance' => '0.50', 'availableBalance' => '0.25'],
    ]), $account);

    expect($balance['total-wallet-balance'])->toBe('0.50')
        ->and($balance['available-balance'])->toBe('0.25');
});
