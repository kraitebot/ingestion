<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Order\Bitget\CalculateWapAndModifyProfitOrderJob as BitgetWapJob;
use Kraite\Core\Jobs\Atomic\Order\Bitget\ModifyAlgoOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * F12/F15 regression (code-review 04-P1 / 05-P1): Bitget's paired
 * `place-pos-tpsl` call overwrites BOTH TP and SL legs atomically, so
 * sibling selection is exposure-critical. Recreate workflows retain
 * historical CANCELLED rows; the unfiltered `first()` picked the OLDEST
 * row, feeding an obsolete trigger back into the live opposite leg.
 * Both helpers must select the newest LIVE (activeOnExchange) sibling.
 */
function buildBitgetPositionWithStaleAndLiveSl(): array
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'BGSIB']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BGSIB',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);
    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'active',
        'total_limit_orders' => 4,
    ]);

    // Historical stop from a prior recreate cycle — obsolete trigger price.
    $staleSl = Order::create([
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'price' => '0.80000000',
        'quantity' => '10',
        'exchange_order_id' => 'old-111',
    ]);
    $staleSl->updateSaving(['status' => 'CANCELLED']);

    // The live replacement stop.
    $liveSl = Order::create([
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'price' => '0.85000000',
        'quantity' => '10',
        'exchange_order_id' => 'new-222',
    ]);

    // The TP leg whose drift/WAP triggers the paired overwrite.
    $tp = Order::create([
        'position_id' => $position->id,
        'type' => 'PROFIT-LIMIT',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'price' => '1.10000000',
        'quantity' => '10',
        'exchange_order_id' => 'tp-333',
    ]);

    return [$position, $tp, $staleSl, $liveSl];
}

it('ModifyAlgoOrderJob selects the newest live sibling, never the cancelled historical one', function (): void {
    [$position, $tp, $staleSl, $liveSl] = buildBitgetPositionWithStaleAndLiveSl();

    $job = new ModifyAlgoOrderJob($position->id, $tp->id);
    $sibling = $job->findSiblingAlgoOrder();

    expect($sibling)->not->toBeNull()
        ->and($sibling->id)->toBe($liveSl->id)
        ->and($sibling->price)->not->toBe($staleSl->price);
});

it('Bitget WAP selects the newest live stop, never the cancelled historical one', function (): void {
    [$position] = buildBitgetPositionWithStaleAndLiveSl();

    $job = new BitgetWapJob($position->id);
    $sibling = $job->findSiblingStopLossOrder();

    expect($sibling)->not->toBeNull()
        ->and((string) $sibling->price)->toContain('0.85');
});

it('returns null instead of a dead sibling when no live leg exists', function (): void {
    [$position, $tp, $staleSl, $liveSl] = buildBitgetPositionWithStaleAndLiveSl();
    $liveSl->updateSaving(['status' => 'CANCELLED']);

    $job = new ModifyAlgoOrderJob($position->id, $tp->id);

    expect($job->findSiblingAlgoOrder())->toBeNull();
});
