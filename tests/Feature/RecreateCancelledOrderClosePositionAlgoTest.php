<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\RecreateCancelledOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * STOP-MARKET orders on Binance (and any closePosition-style algo order
 * on other exchanges that follow the same convention) carry
 * quantity = 0 at placement — they close whatever is open at trigger
 * time, regardless of fill history. The RecreateCancelledOrderJob's
 * "remaining quantity > 0" gate is correct for LIMIT orders but must
 * not reject these closePosition-style algo orders.
 */
function buildRecreationFixture(array $overrides = []): Order
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

    return Order::create(array_merge([
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'CANCELLED',
        'price' => '0.42440000',
        'reference_price' => '0.42440000',
        'quantity' => '0',
        'reference_quantity' => '0',
        'reference_status' => 'NEW',
        'is_algo' => true,
        'exchange_order_id' => '4000001152477657',
    ], $overrides));
}

it('allows recreation of a cancelled closePosition-style algo order (is_algo + reference_quantity=0)', function (): void {
    $order = buildRecreationFixture();

    $job = new RecreateCancelledOrderJob($order->position_id, $order->id);

    expect($job->startOrFail())->toBeTrue();
});

it('rejects a non-algo order that has reference_quantity = 0 (nothing legitimate to recreate)', function (): void {
    $order = buildRecreationFixture([
        'type' => 'LIMIT',
        'is_algo' => false,
        'quantity' => '0',
        'reference_quantity' => '0',
    ]);

    $job = new RecreateCancelledOrderJob($order->position_id, $order->id);

    expect($job->startOrFail())->toBeFalse();
});

it('allows recreation of a regular LIMIT with reference_quantity > 0', function (): void {
    $order = buildRecreationFixture([
        'type' => 'LIMIT',
        'is_algo' => false,
        'quantity' => '10.0',
        'reference_quantity' => '10.0',
    ]);

    $job = new RecreateCancelledOrderJob($order->position_id, $order->id);

    expect($job->startOrFail())->toBeTrue();
});
