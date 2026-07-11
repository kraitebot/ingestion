<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Lifecycles\Order\Bitget\PrepareOrderCorrectionJob as BitgetPrepareOrderCorrectionJob;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * F10 regression (code-review 04-P1): the observer's correction dedupe
 * queried the BASE PrepareOrderCorrectionJob class while the insert
 * stored the JobProxy-RESOLVED class. For Bitget the resolver returns
 * the Bitget override, so a pending Bitget correction was invisible to
 * the dedupe and every observer cycle on a still-drifted order enqueued
 * another chain — racing place-pos-tpsl overwrites on live TP/SL legs.
 * The dedupe now searches the resolved class.
 */
function buildDriftableOrderForExchange(string $canonical, string $token): Order
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => ucfirst($canonical),
    ]);
    $symbol = Symbol::factory()->create(['token' => $token]);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
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
        'price' => '0.33000000',
        'quantity' => '186.60000000',
        'reference_price' => '0.33000000',
        'reference_quantity' => '186.60000000',
        'reference_status' => 'NEW',
        'exchange_order_id' => '424242',
    ]);
}

function countCorrectionsFor(Order $order, string $class): int
{
    return Steps::usingPrefix('trading', fn (): int => Step::query()
        ->where('class', $class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.orderId') = ?", [$order->id])
        ->count());
}

it('dedupes repeated Bitget drift updates against the RESOLVED correction class', function (): void {
    $order = buildDriftableOrderForExchange('bitget', 'BGDEDUP');
    $order->position->updateSaving(['status' => 'syncing']);

    // First drift observation — dispatches the Bitget override class.
    $order->updateSaving(['price' => '0.33500000']);

    expect(countCorrectionsFor($order, BitgetPrepareOrderCorrectionJob::class))->toBe(1);

    // Drift persists across another sync write while the correction is
    // still pending — the dedupe must see the pending Bitget row.
    $order->updateSaving(['price' => '0.33600000']);

    expect(countCorrectionsFor($order, BitgetPrepareOrderCorrectionJob::class))->toBe(1)
        // And nothing ever lands under the base class for a Bitget account.
        ->and(countCorrectionsFor($order, PrepareOrderCorrectionJob::class))->toBe(0);
});

it('still dedupes repeated Binance drift updates against the base class', function (): void {
    $order = buildDriftableOrderForExchange('binance', 'BNDEDUP');
    $order->position->updateSaving(['status' => 'syncing']);

    $order->updateSaving(['price' => '0.33500000']);
    $order->updateSaving(['price' => '0.33600000']);

    expect(countCorrectionsFor($order, PrepareOrderCorrectionJob::class))->toBe(1);
});
