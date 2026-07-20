<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\Order\Binance\CancelOrphanAlgoOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\User;

/*
 * The Binance ghost-algo scrub cancels unknown stop/TP orders on the
 * symbol of a bot position before re-placing its own. On an account
 * shared with the user (allow_other_orders=true) an unknown algo order
 * may be the USER's own stop — the scrub must not touch it.
 */

uses(RefreshDatabase::class);

function ghostAlgoPosition(bool $allowOtherOrders): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $account = Account::factory()->create([
        'user_id' => User::factory()->create()->id,
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'ghost-key',
        'binance_api_secret' => 'ghost-secret',
        'allow_other_orders' => $allowOtherOrders,
        'allow_other_positions' => $allowOtherOrders,
    ]);

    $exchangeSymbol = Kraite\Core\Models\ExchangeSymbol::factory()->create([
        'api_system_id' => $account->api_system_id,
        'token' => 'BTC',
        'quote' => 'USDT',
    ]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'BTCUSDT',
        'status' => 'active',
    ]);
}

function runGhostAlgoScrub(Position $position): array
{
    $job = new CancelOrphanAlgoOrdersJob($position->id);

    $compute = Closure::bind(function (): array {
        return $this->computeApiable();
    }, $job, $job::class);

    return $compute();
}

test('with allow_other_orders=true, an unknown algo order on the symbol is left alone', function (): void {
    $position = ghostAlgoPosition(allowOtherOrders: true);
    $symbol = (string) $position->parsed_trading_pair;

    Http::fake([
        '*/fapi/v1/openAlgoOrders*' => Http::response([
            ['algoId' => 'users-own-stop-1', 'symbol' => $symbol, 'orderType' => 'STOP_MARKET', 'triggerPrice' => '1.00'],
        ], 200),
        '*/fapi/v1/algoOrder*' => Http::response(['code' => 200], 200),
    ]);

    $result = runGhostAlgoScrub($position);

    expect($result['cancelled_count'])->toBe(0);

    Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE'
        && str_contains($r->url(), '/fapi/v1/algoOrder'));
});

test('with allow_other_orders=false, the unknown algo order is scrubbed as before', function (): void {
    $position = ghostAlgoPosition(allowOtherOrders: false);
    $symbol = (string) $position->parsed_trading_pair;

    Http::fake([
        '*/fapi/v1/openAlgoOrders*' => Http::response([
            ['algoId' => 'ghost-42', 'symbol' => $symbol, 'orderType' => 'STOP_MARKET', 'triggerPrice' => '1.00'],
        ], 200),
        '*/fapi/v1/algoOrder*' => Http::response(['code' => 200], 200),
    ]);

    $result = runGhostAlgoScrub($position);

    expect($result['cancelled_count'])->toBe(1);

    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE'
        && str_contains($r->url(), '/fapi/v1/algoOrder'));
});

test('known bot algo ids are never cancelled regardless of the flag', function (): void {
    $position = ghostAlgoPosition(allowOtherOrders: false);
    $symbol = (string) $position->parsed_trading_pair;

    $position->orders()->create([
        'type' => 'STOP-MARKET',
        'status' => 'NEW',
        'side' => 'SELL',
        'is_algo' => true,
        'exchange_order_id' => 'bot-algo-7',
    ]);

    Http::fake([
        '*/fapi/v1/openAlgoOrders*' => Http::response([
            ['algoId' => 'bot-algo-7', 'symbol' => $symbol, 'orderType' => 'STOP_MARKET', 'triggerPrice' => '1.00'],
        ], 200),
        '*/fapi/v1/algoOrder*' => Http::response(['code' => 200], 200),
    ]);

    $result = runGhostAlgoScrub($position);

    expect($result['cancelled_count'])->toBe(0);

    Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE'
        && str_contains($r->url(), '/fapi/v1/algoOrder'));
});
