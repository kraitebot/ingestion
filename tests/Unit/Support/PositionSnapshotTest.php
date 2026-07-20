<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Enums\PositionPresence;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\PositionSnapshot;
use Kraite\Core\Support\ValueObjects\ApiResponse;

function snapshotAccount(string $canonical): Account
{
    $account = new Account(['on_hedge_mode' => false]);
    $account->setRelation('apiSystem', new ApiSystem(['canonical' => $canonical]));

    return $account;
}

function snapshotPosition(Account $account, string $direction = 'LONG'): Position
{
    $position = new Position([
        'parsed_trading_pair' => 'BTCUSDT',
        'direction' => $direction,
    ]);
    $position->setRelation('account', $account);

    return $position;
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, array<string, mixed>>  $normalized
 */
function snapshotResponse(array $body, array $normalized): ApiResponse
{
    return new ApiResponse(
        new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)),
        $normalized,
    );
}

it('recognizes an exact open position across every supported exchange response envelope', function (
    string $canonical,
    array $body,
    array $normalized,
    string $direction,
): void {
    $account = snapshotAccount($canonical);

    $snapshot = PositionSnapshot::fromApiResponse(
        $account,
        snapshotResponse($body, $normalized),
    );

    expect($snapshot->presenceOf(snapshotPosition($account, $direction)))
        ->toBe(PositionPresence::Open);
})->with([
    'Binance hedge LONG' => [
        'binance',
        [['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.1']],
        ['BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.1']],
        'LONG',
    ],
    'Bitget one-way SHORT' => [
        'bitget',
        ['code' => '00000', 'data' => [['symbol' => 'BTCUSDT', 'total' => '2', 'holdSide' => 'short']]],
        ['BTCUSDT:BOTH' => ['symbol' => 'BTCUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '-2']],
        'SHORT',
    ],
    'Bitget UTA hedge LONG' => [
        'bitget',
        ['code' => '00000', 'data' => ['list' => [[
            'symbol' => 'BTCUSDT',
            'total' => '0.4',
            'posSide' => 'long',
            'holdMode' => 'hedge_mode',
        ]]]],
        ['BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.4']],
        'LONG',
    ],
    'Bybit one-way LONG' => [
        'bybit',
        ['retCode' => 0, 'result' => ['list' => [['symbol' => 'BTCUSDT', 'side' => 'Buy', 'size' => '3']]]],
        ['BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '3']],
        'LONG',
    ],
    'KuCoin SHORT' => [
        'kucoin',
        ['code' => '200000', 'data' => [['symbol' => 'XBTUSDTM', 'isOpen' => true, 'currentQty' => -4]]],
        ['BTCUSDT:SHORT' => ['symbol' => 'BTCUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-4']],
        'SHORT',
    ],
]);

it('does not treat an opposite-side position as the local position', function (): void {
    $account = snapshotAccount('binance');
    $snapshot = PositionSnapshot::fromApiResponse(
        $account,
        snapshotResponse(
            [['symbol' => 'BTCUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-1']],
            ['BTCUSDT:SHORT' => ['symbol' => 'BTCUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-1']],
        ),
    );

    expect($snapshot->presenceOf(snapshotPosition($account, 'LONG')))
        ->toBe(PositionPresence::Flat);
});

it('infers the direction of one-way BOTH positions from the signed quantity', function (): void {
    $account = snapshotAccount('binance');
    $snapshot = PositionSnapshot::fromValidatedResult([
        'BTCUSDT:BOTH' => [
            'symbol' => 'BTCUSDT',
            'positionSide' => 'BOTH',
            'positionAmt' => '-1.25',
        ],
    ]);

    expect($snapshot->presenceOf(snapshotPosition($account, 'SHORT')))->toBe(PositionPresence::Open)
        ->and($snapshot->presenceOf(snapshotPosition($account, 'LONG')))->toBe(PositionPresence::Flat);
});

it('treats a valid empty exchange response as flat', function (string $canonical, array $body): void {
    $account = snapshotAccount($canonical);
    $snapshot = PositionSnapshot::fromApiResponse($account, snapshotResponse($body, []));

    expect($snapshot->presenceOf(snapshotPosition($account)))
        ->toBe(PositionPresence::Flat);
})->with([
    'Binance' => ['binance', []],
    'Bitget' => ['bitget', ['code' => '00000', 'data' => []]],
    'Bitget UTA' => ['bitget', ['code' => '00000', 'data' => ['list' => []]]],
    'Bybit' => ['bybit', ['retCode' => 0, 'result' => ['list' => []]]],
    'KuCoin' => ['kucoin', ['code' => '200000', 'data' => []]],
]);

it('rejects malformed or unsuccessful HTTP 200 exchange envelopes', function (
    string $canonical,
    string $body,
): void {
    $account = snapshotAccount($canonical);
    $apiResponse = new ApiResponse(new Response(200, [], $body), []);

    $snapshot = PositionSnapshot::fromApiResponse($account, $apiResponse);

    expect($snapshot->presenceOf(snapshotPosition($account)))
        ->toBe(PositionPresence::Unknown);
})->with([
    'Binance malformed JSON' => ['binance', '{invalid'],
    'Binance object instead of list' => ['binance', '{"message":"failure"}'],
    'Bitget vendor error' => ['bitget', '{"code":"40014","msg":"invalid key"}'],
    'Bybit vendor error' => ['bybit', '{"retCode":10001,"retMsg":"error"}'],
    'KuCoin vendor error' => ['kucoin', '{"code":"400001","msg":"error"}'],
]);

it('rejects malformed normalized rows before they can become trusted snapshots', function (): void {
    $account = snapshotAccount('binance');
    $snapshot = PositionSnapshot::fromValidatedResult([
        'BTCUSDT:LONG' => [
            'symbol' => 'BTCUSDT',
            'positionSide' => 'LONG',
            'positionAmt' => 'not-a-number',
        ],
    ]);

    expect($snapshot->isValid())->toBeFalse()
        ->and($snapshot->presenceOf(snapshotPosition($account)))->toBe(PositionPresence::Unknown);
});

it('rejects a mapper result that silently drops an open row from a valid envelope', function (): void {
    $account = snapshotAccount('bitget');
    $snapshot = PositionSnapshot::fromApiResponse(
        $account,
        snapshotResponse(
            ['code' => '00000', 'data' => [[
                'symbol' => 'BTCUSDT',
                'total' => '2',
                'holdSide' => 'long',
            ]]],
            [],
        ),
    );

    expect($snapshot->isValid())->toBeFalse()
        ->and($snapshot->presenceOf(snapshotPosition($account)))->toBe(PositionPresence::Unknown);
});
