<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\User;

/*
 * End-to-end: the system-health watchdog must keep the account's
 * protection flags aligned with live exchange evidence BEFORE running
 * orphan cleanup:
 *
 *   - user opens their own position/order on a Kraite-exclusive account
 *     → flags auto-enable, NOTHING is closed or cancelled
 *   - user activity is gone → flags auto-disable (exclusive mode back)
 *   - a Kraite leftover (recently closed locally, still on exchange)
 *     is NOT user activity — it still gets cleaned up
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();
    Illuminate\Support\Once::flush();

    Kraite::updateOrCreate(['id' => 1], [
        'allow_opening_positions' => true,
        'is_cooling_down' => false,
        'timeframes' => ['1h'],
    ]);
});

function watchdogBitgetAccount(array $overrides = []): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    return Account::factory()->create(array_merge([
        'user_id' => User::factory()->create()->id,
        'api_system_id' => $apiSystem->id,
        'is_active' => true,
        'portfolio_quote' => 'USDT',
        'trading_quote' => 'USDT',
        'bitget_api_key' => 'watchdog-key',
        'bitget_api_secret' => 'watchdog-secret',
        'bitget_passphrase' => 'watchdog-pass',
        'bitget_account_mode' => 'classic',
        'allow_other_positions' => false,
        'allow_other_orders' => false,
    ], $overrides));
}

/**
 * @param  array<int, array<string, mixed>>  $positions  v2 raw position rows
 * @param  array<int, array<string, mixed>>  $orders  v2 raw entrusted rows
 */
function fakeBitgetExchangeState(array $positions, array $orders): void
{
    Http::fake(function (HttpRequest $request) use ($positions, $orders) {
        return match (true) {
            str_contains($request->url(), '/api/v2/mix/order/orders-pending') => Http::response([
                'code' => '00000',
                'data' => ['entrustedList' => $orders],
            ], 200),
            str_contains($request->url(), '/api/v2/mix/position/all-position') => Http::response([
                'code' => '00000',
                'data' => $positions,
            ], 200),
            str_contains($request->url(), '/api/v2/mix/order/close-positions') => Http::response([
                'code' => '00000', 'data' => ['successList' => []],
            ], 200),
            str_contains($request->url(), '/api/v2/mix/order/cancel-order') => Http::response([
                'code' => '00000', 'data' => [],
            ], 200),
            default => Http::response(['code' => '00000', 'data' => []], 200),
        };
    });
}

test('user-opened position on a clean account enables protection instead of closing it', function (): void {
    $account = watchdogBitgetAccount();

    fakeBitgetExchangeState(
        positions: [[
            'symbol' => 'DOGEUSDT', 'total' => '250', 'holdSide' => 'long',
            'posMode' => 'hedge_mode', 'marginCoin' => 'USDT',
        ]],
        orders: [],
    );

    $this->artisan('kraite:cron-check-system-health');

    expect($account->fresh()->allow_other_positions)->toBeTrue()
        ->and($account->fresh()->allow_other_orders)->toBeTrue();

    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/api/v2/mix/order/close-positions'));
});

test('user limit order alone enables BOTH protection flags and is not cancelled', function (): void {
    $account = watchdogBitgetAccount();

    fakeBitgetExchangeState(
        positions: [],
        orders: [[
            'orderId' => 'user-limit-77', 'symbol' => 'BTCUSDT', 'orderType' => 'limit',
            'price' => '40000', 'side' => 'buy',
        ]],
    );

    $this->artisan('kraite:cron-check-system-health');

    expect($account->fresh()->allow_other_positions)->toBeTrue()
        ->and($account->fresh()->allow_other_orders)->toBeTrue();

    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/api/v2/mix/order/cancel-order'));
});

test('protection flags auto-disable once the user activity is gone', function (): void {
    $account = watchdogBitgetAccount([
        'allow_other_positions' => true,
        'allow_other_orders' => true,
    ]);

    fakeBitgetExchangeState(positions: [], orders: []);

    $this->artisan('kraite:cron-check-system-health');

    expect($account->fresh()->allow_other_positions)->toBeFalse()
        ->and($account->fresh()->allow_other_orders)->toBeFalse();
});

test('a Kraite leftover position is still cleaned up and does not count as user activity', function (): void {
    $account = watchdogBitgetAccount([
        'allow_other_positions' => true,
        'allow_other_orders' => true,
        'on_hedge_mode' => true,
    ]);

    // Kraite position recently closed locally but still alive on the exchange.
    $exchangeSymbol = \Kraite\Core\Models\ExchangeSymbol::factory()->create([
        'api_system_id' => $account->api_system_id,
        'token' => 'ETH',
        'quote' => 'USDT',
    ]);
    $leftover = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'ETHUSDT',
        'status' => 'closed',
    ]);
    $pair = (string) $leftover->parsed_trading_pair;

    fakeBitgetExchangeState(
        positions: [[
            'symbol' => $pair, 'total' => '3', 'holdSide' => 'long',
            'posMode' => 'hedge_mode', 'marginCoin' => 'USDT',
        ]],
        orders: [],
    );

    $this->artisan('kraite:cron-check-system-health');

    // Leftover closed on the exchange; flags dropped back to exclusive mode.
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/api/v2/mix/order/close-positions'));
    expect($account->fresh()->allow_other_positions)->toBeFalse();
});
