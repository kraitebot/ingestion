<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

function buildActivePositionWithProfitOrder(): Order
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'API3']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'API3',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);
    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
        'status' => 'active',
        'total_limit_orders' => 4,
    ]);

    return Order::create([
        'position_id' => $position->id,
        'type' => 'PROFIT-LIMIT',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'NEW',
        'price' => '0.30',
        'quantity' => '10',
        'exchange_order_id' => '5551111',
    ]);
}

it('stamps filled_at on the first NEW -> FILLED transition', function (): void {
    $order = buildActivePositionWithProfitOrder();

    expect($order->filled_at)->toBeNull();

    $this->freezeTime();
    $expectedFilledAt = now();

    $order->updateSaving(['status' => 'FILLED']);

    $order->refresh();

    expect($order->filled_at)->not->toBeNull();
    expect($order->filled_at->timestamp)->toBe($expectedFilledAt->timestamp);
});

it('does not re-bump filled_at on subsequent saves that keep status = FILLED', function (): void {
    $order = buildActivePositionWithProfitOrder();

    $this->freezeTime();
    $order->updateSaving(['status' => 'FILLED']);
    $order->refresh();

    $originalFilledAt = $order->filled_at;
    expect($originalFilledAt)->not->toBeNull();

    // Travel 30 seconds forward and save the order again — simulates the
    // close workflow's post-close re-sync hitting the same row.
    $this->travel(30)->seconds();

    $order->updateSaving(['price' => '0.31']);
    $order->refresh();

    // filled_at must NOT have advanced.
    expect($order->filled_at->timestamp)->toBe($originalFilledAt->timestamp);
});

it('does not set filled_at on non-FILLED saves', function (): void {
    $order = buildActivePositionWithProfitOrder();

    $order->updateSaving(['price' => '0.32']);
    $order->refresh();

    expect($order->filled_at)->toBeNull();
});
