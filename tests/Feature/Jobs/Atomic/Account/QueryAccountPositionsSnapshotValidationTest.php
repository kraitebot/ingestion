<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\Account\QueryAccountPositionsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;

it('does not replace the last trusted snapshot with an HTTP 200 vendor error', function (): void {
    Http::fake([
        '*/api/v2/mix/position/all-position*' => Http::response(json_encode([
            'code' => '40014',
            'msg' => 'invalid api key',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'bitget_api_key' => 'TESTKEY',
        'bitget_api_secret' => 'TESTSECRET',
        'bitget_passphrase' => 'TESTPASS',
        'bitget_account_mode' => 'classic',
    ]);
    $trustedPositions = [
        'BTCUSDT:LONG' => [
            'symbol' => 'BTCUSDT',
            'positionSide' => 'LONG',
            'positionAmt' => '1',
        ],
    ];
    ApiSnapshot::storeFor($account, 'account-positions', $trustedPositions);

    $job = new QueryAccountPositionsJob($account->id);

    expect(ApiSnapshot::getFrom($account, 'account-positions'))->toEqual($trustedPositions);

    expect(fn () => $job->computeApiable())
        ->toThrow(RequestException::class, 'Bitget API error (code 40014): invalid api key')
        ->and(ApiSnapshot::getFrom($account, 'account-positions'))
        ->toEqual($trustedPositions);
});

it('stores a valid empty positions response', function (): void {
    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response('[]'),
    ]);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'TESTKEY',
        'binance_api_secret' => 'TESTSECRET',
    ]);
    ApiSnapshot::storeFor($account, 'account-positions', [
        'BTCUSDT:LONG' => [
            'symbol' => 'BTCUSDT',
            'positionSide' => 'LONG',
            'positionAmt' => '1',
        ],
    ]);

    $job = new QueryAccountPositionsJob($account->id);
    $job->computeApiable();

    expect(ApiSnapshot::getFrom($account, 'account-positions'))->toBe([]);
});

it('stores a valid empty Bitget unified positions response when the list is null', function (): void {
    Http::fake([
        '*/api/v3/position/current-position*' => Http::response([
            'code' => '00000',
            'msg' => 'success',
            'data' => ['list' => null],
        ]),
    ]);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'trading_quote' => 'USDT',
        'bitget_api_key' => 'TESTKEY',
        'bitget_api_secret' => 'TESTSECRET',
        'bitget_passphrase' => 'TESTPASS',
        'bitget_account_mode' => 'unified',
    ]);
    ApiSnapshot::storeFor($account, 'account-positions', [
        'BTCUSDT:LONG' => [
            'symbol' => 'BTCUSDT',
            'positionSide' => 'LONG',
            'positionAmt' => '1',
        ],
    ]);

    expect(ApiSnapshot::getFrom($account, 'account-positions'))->toHaveKey('BTCUSDT:LONG');

    $job = new QueryAccountPositionsJob($account->id);
    $job->computeApiable();

    expect(ApiSnapshot::getFrom($account, 'account-positions'))->toBe([]);
});
