<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\Account\InspectRegistrationAccountStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\User;

uses(RefreshDatabase::class)->group('feature', 'registration');

function registrationInspectionAccount(): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => User::factory()->create()->id,
        'portfolio_quote' => 'USDT',
        'trading_quote' => 'USDT',
        'bitget_api_key' => 'registration-inspection-key',
        'bitget_api_secret' => 'registration-inspection-secret',
        'bitget_passphrase' => 'registration-inspection-passphrase',
        'bitget_account_mode' => 'unified',
    ]);
}

it('reads both Bitget stablecoin balances from a worker-safe registration step', function (): void {
    $account = registrationInspectionAccount();

    Http::fake([
        'api.bitget.com/api/v3/account/assets*' => Http::response([
            'code' => '00000',
            'data' => [
                'accountEquity' => '300.50',
                'assets' => [
                    ['coin' => 'USDT', 'equity' => '200.25', 'available' => '180.00'],
                    ['coin' => 'USDC', 'equity' => '100.25', 'available' => '95.00'],
                ],
            ],
        ]),
    ]);

    $result = (new InspectRegistrationAccountStep(
        accountId: $account->id,
        mode: InspectRegistrationAccountStep::MODE_BALANCES,
        balanceQuotes: ['USDT', 'USDC', 'BFUSD'],
    ))->computeApiable();

    expect($result)->toBe([
        'account_id' => $account->id,
        'mode' => InspectRegistrationAccountStep::MODE_BALANCES,
        'assets' => [
            'USDT' => ['balance' => '200.25', 'available' => '180.00'],
            'USDC' => ['balance' => '100.25', 'available' => '95.00'],
        ],
    ]);

    Http::assertSentCount(2);
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/api/v3/account/assets'));
});

it('detects existing positions and orders from the worker before activation', function (): void {
    $account = registrationInspectionAccount();

    Http::fake(function (HttpRequest $request) {
        return match (true) {
            str_contains($request->url(), '/api/v3/position/current-position') => Http::response([
                'code' => '00000',
                'data' => ['list' => [[
                    'symbol' => 'BTCUSDT',
                    'posSide' => 'long',
                    'holdMode' => 'hedge_mode',
                    'total' => '0.01',
                ]]],
            ]),
            str_contains($request->url(), '/api/v3/trade/unfilled-orders') => Http::response([
                'code' => '00000',
                'data' => ['list' => [[
                    'orderId' => 'registration-order-1',
                    'symbol' => 'ETHUSDT',
                    'orderType' => 'limit',
                    'price' => '2500',
                    'qty' => '0.1',
                    'orderStatus' => 'live',
                ]]],
            ]),
            default => Http::response(['message' => 'Unexpected test URL'], 500),
        };
    });

    $result = (new InspectRegistrationAccountStep(
        accountId: $account->id,
        mode: InspectRegistrationAccountStep::MODE_ACTIVITY,
    ))->computeApiable();

    expect($result)->toBe([
        'account_id' => $account->id,
        'mode' => InspectRegistrationAccountStep::MODE_ACTIVITY,
        'has_own_positions' => true,
        'has_own_orders' => true,
    ]);

    Http::assertSentCount(2);
});
