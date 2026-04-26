<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\Bitget\ModifyAlgoOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Math;

/**
 * Regression guard for the Bitget drift-correction failure observed
 * 2026-04-26: a manually-slid TP on FETUSDT (position 421) could not be
 * pushed back to its reference price because Bitget's pos_profit /
 * pos_loss orders cannot be cancelled+recreated individually. The
 * exchange's `cancel-plan-order` returns a silent no-op
 * (`successList:[], failureList:[]`) for these orders — they're attached
 * to the position and the only restore path that works is
 * `modify-tpsl-order`.
 *
 * `ModifyAlgoOrderJob` (Bitget-only) replaces the cancel+recreate workflow
 * for Bitget algo orders. It calls `modifyTpslOrder` with the Order's
 * `reference_price` as the new trigger price and syncs the local row back
 * to match.
 */
function buildBitgetDriftedAlgoOrderFixture(string $type, ?string $referencePrice = '0.20870000', ?string $currentPrice = '0.20690000'): array
{
    $token = 'DRFT'.mb_strtoupper(Str::random(4));

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'BitGet',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'stop_market_initial_percentage' => '2.50',
    ]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => 'active',
        'opening_price' => '0.20000000',
        'quantity' => '100.00000000',
        'profit_percentage' => '0.360',
    ]);

    $order = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => $type,
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => $currentPrice,
        'quantity' => '100.00000000',
        'reference_price' => $referencePrice,
        'reference_quantity' => '100.00000000',
        'exchange_order_id' => '1432341121446961152',
        'is_algo' => true,
    ]));

    return [
        'positionId' => $position->id,
        'orderId' => $order->id,
        'order' => $order->refresh(),
    ];
}

it('startOrFail returns true on a drifted Bitget PROFIT-LIMIT algo order', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture('PROFIT-LIMIT');

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);

    expect($job->startOrFail())->toBeTrue();
});

it('startOrFail returns true on a drifted Bitget STOP-MARKET algo order', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture('STOP-MARKET');

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);

    expect($job->startOrFail())->toBeTrue();
});

it('startOrFail returns false when price already matches reference_price (no drift)', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture(
        type: 'PROFIT-LIMIT',
        referencePrice: '0.20870000',
        currentPrice: '0.20870000',
    );

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);

    expect($job->startOrFail())->toBeFalse(
        'No drift means there is nothing to modify — startOrFail must abort.'
    );
});

it('startOrFail returns false when reference_price is null', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture(
        type: 'PROFIT-LIMIT',
        referencePrice: null,
    );

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);

    expect($job->startOrFail())->toBeFalse(
        'Cannot modify back to a reference price that does not exist.'
    );
});

it('startOrFail returns false when the order is not is_algo', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture('PROFIT-LIMIT');
    $fixture['order']->updateSaving(['is_algo' => false]);

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);

    expect($job->startOrFail())->toBeFalse(
        'ModifyAlgoOrderJob is for position-level TP/SL only; non-algo orders '
        .'use the regular apiModify path.'
    );
});

it('startOrFail returns false when the order belongs to a different position', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture('PROFIT-LIMIT');

    $otherSymbolToken = 'OTH'.mb_strtoupper(Str::random(4));
    $otherSymbol = Symbol::factory()->create(['token' => $otherSymbolToken]);
    $otherExchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $otherSymbolToken,
        'quote' => 'USDT',
        'api_system_id' => $fixture['order']->position->account->api_system_id,
        'symbol_id' => $otherSymbol->id,
    ]);
    $otherPosition = Position::factory()->long()->create([
        'account_id' => $fixture['order']->position->account_id,
        'exchange_symbol_id' => $otherExchangeSymbol->id,
        'status' => 'active',
    ]);

    $job = new ModifyAlgoOrderJob($otherPosition->id, $fixture['orderId']);

    expect($job->startOrFail())->toBeFalse();
});

it('startOrFail returns false when the position is no longer active', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture('PROFIT-LIMIT');
    $fixture['order']->position->updateSaving(['status' => 'closed']);

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);

    expect($job->startOrFail())->toBeFalse();
});

it('complete syncs price back to reference_price so OrderObserver no longer sees drift', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture('PROFIT-LIMIT');

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);
    $job->complete();

    $fixture['order']->refresh();

    expect(Math::equal((string) $fixture['order']->price, (string) $fixture['order']->reference_price))->toBeTrue(
        'After modify, local price must equal reference_price — otherwise the '
        .'observer would re-detect drift on the next sync and re-trigger correction.'
    );
});

it('findSiblingAlgoOrder returns the SL when the modified leg is the TP', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture('PROFIT-LIMIT');

    $sl = Order::withoutEvents(fn () => Order::create([
        'position_id' => $fixture['order']->position_id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => '0.18500000',
        'quantity' => '100.00000000',
        'exchange_order_id' => '9999933333',
        'is_algo' => true,
    ]));

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);
    $sibling = $job->findSiblingAlgoOrder();

    expect($sibling)->not->toBeNull()
        ->and($sibling->id)->toBe($sl->id)
        ->and($sibling->type)->toBe('STOP-MARKET');
});

it('findSiblingAlgoOrder returns null when the sibling does not exist', function (): void {
    $fixture = buildBitgetDriftedAlgoOrderFixture('PROFIT-LIMIT');

    $job = new ModifyAlgoOrderJob($fixture['positionId'], $fixture['orderId']);

    expect($job->findSiblingAlgoOrder())->toBeNull(
        'Cannot derive an unchanged-leg price without the sibling — '
        .'computeApiable must surface this rather than silently sending a malformed request.'
    );
});
