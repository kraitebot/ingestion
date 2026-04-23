<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

/**
 * Pins the detection side of the order-modification workflow.
 *
 * Manual modifications on the exchange (user hits "Edit order" in Binance's
 * UI and changes qty or price) are only detectable via the sync-orders
 * cycle: apiSync() pulls the new values from the exchange and writes them
 * to the DB, at which point the `updated` observer compares `price` /
 * `quantity` against `reference_price` / `reference_quantity` and — when
 * they diverge — dispatches `PrepareOrderCorrectionJob` to restore the
 * original values.
 *
 * CRITICAL: the observer check MUST fire while the position is in
 * `syncing` status, because that is the ONLY window where the drift is
 * observable — sync flips to `syncing` at the top, writes the drifted
 * values, and flips back to `active` via complete(). Once the DB has been
 * written, a subsequent sync sees no dirty fields, so no observer event
 * fires, so no drift is ever detected.
 */
function buildActivePositionWithLimitOrder(string $referencePrice, string $referenceQuantity): Order
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
        'type' => 'LIMIT',
        'side' => 'SELL',
        'position_side' => 'SHORT',
        'status' => 'NEW',
        'price' => $referencePrice,
        'quantity' => $referenceQuantity,
        'reference_price' => $referencePrice,
        'reference_quantity' => $referenceQuantity,
        'reference_status' => 'NEW',
        'exchange_order_id' => '8888777',
    ]);
}

it('dispatches PrepareOrderCorrectionJob when a price drift is detected mid-sync', function (): void {
    $order = buildActivePositionWithLimitOrder(
        referencePrice: '0.33000000',
        referenceQuantity: '186.60000000',
    );
    $position = $order->position;

    // Simulate the middle of a sync: SyncPositionOrdersJob flips the
    // position to 'syncing' at the top of computeApiable before calling
    // apiSync() on each order.
    $position->updateSaving(['status' => 'syncing']);

    // Make sure no correction step exists before the modification.
    expect(Step::query()
        ->where('class', PrepareOrderCorrectionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->exists())->toBeFalse();

    // Simulate what apiSyncDefault() does after fetching a user-modified
    // value from Binance: write the new price (reference_price stays at
    // the pre-modification value until we explicitly bump it).
    $order->updateSaving(['price' => '0.33500000']);

    // The observer's checkForOrderModification must see
    // price (0.33500000) != reference_price (0.33000000) and dispatch
    // the correction job — even though the position is currently `syncing`.
    $dispatched = Step::query()
        ->where('class', PrepareOrderCorrectionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->exists();

    expect($dispatched)->toBeTrue();
});

it('dispatches PrepareOrderCorrectionJob when quantity drifts mid-sync', function (): void {
    $order = buildActivePositionWithLimitOrder(
        referencePrice: '0.33000000',
        referenceQuantity: '186.60000000',
    );
    $position = $order->position;

    $position->updateSaving(['status' => 'syncing']);

    $order->updateSaving(['quantity' => '200.00000000']);

    $dispatched = Step::query()
        ->where('class', PrepareOrderCorrectionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->exists();

    expect($dispatched)->toBeTrue();
});

it('does not dispatch when opening/waping (those windows are legitimately mutating)', function (string $skippedStatus): void {
    $order = buildActivePositionWithLimitOrder(
        referencePrice: '0.33000000',
        referenceQuantity: '186.60000000',
    );
    $position = $order->position;

    $position->updateSaving(['status' => $skippedStatus]);

    $order->updateSaving(['price' => '0.40000000']);

    $dispatched = Step::query()
        ->where('class', PrepareOrderCorrectionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->exists();

    expect($dispatched)->toBeFalse();
})->with([
    'opening' => ['opening'],
    'waping' => ['waping'],
]);

it('still dispatches when position is plain active and drift is observed', function (): void {
    $order = buildActivePositionWithLimitOrder(
        referencePrice: '0.33000000',
        referenceQuantity: '186.60000000',
    );

    // Position remains 'active' — no sync in progress.
    $order->updateSaving(['price' => '0.40000000']);

    $dispatched = Step::query()
        ->where('class', PrepareOrderCorrectionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->exists();

    expect($dispatched)->toBeTrue();
});
