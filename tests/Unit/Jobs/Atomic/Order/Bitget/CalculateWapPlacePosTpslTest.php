<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\Bitget\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Regression guard for the Bitget WAP failure observed live on
 * 2026-04-29 (APE/USDT, position #792 on account #4 Main BitGet):
 * `apiModifyTpsl` request rejected by Bitget with HTTP 400 / code
 * 400172 ("The trigger price cannot be empty"). The endpoint
 * `modify-tpsl-order` is broken for `pos_profit` / `pos_loss`
 * orders across every field-name combination that has been tried
 * (see `MapsModifyTpsl` trait docblock — verified live 2026-04-26).
 *
 * `Bitget\ModifyAlgoOrderJob` solved the same class of problem for
 * the drift-correction flow by switching to `place-pos-tpsl`, which
 * atomically overwrites both TP and SL while preserving the
 * existing plan-order IDs. The Bitget WAP path now follows the
 * same proven pattern: recalculate the new TP price, read the
 * sibling SL leg's current price unchanged, send both via
 * `place-pos-tpsl`.
 */
function buildBitgetWapTpSlPair(): array
{
    $token = 'WAP'.mb_strtoupper(Str::random(4));

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
        'on_hedge_mode' => true,
    ]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => 'waping',
        'opening_price' => '0.17150000',
        'quantity' => '182.30000000',
        'profit_percentage' => '0.350',
    ]);

    $profitOrder = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => '0.17210000',
        'quantity' => '182.30000000',
        'reference_price' => '0.17210000',
        'reference_quantity' => '182.30000000',
        'exchange_order_id' => '1433050040315666432',
        'is_algo' => true,
    ]));

    $stopLossOrder = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => '0.11020000',
        'quantity' => '182.30000000',
        'reference_price' => '0.11020000',
        'reference_quantity' => '182.30000000',
        'exchange_order_id' => '1433050040357609472',
        'is_algo' => true,
    ]));

    return [
        'positionId' => $position->id,
        'position' => $position->refresh(),
        'profitOrder' => $profitOrder->refresh(),
        'stopLossOrder' => $stopLossOrder->refresh(),
    ];
}

it('exposes findSiblingStopLossOrder which returns the SL leg attached to the position', function (): void {
    // The Bitget WAP rewrite needs the sibling SL price to send through
    // `place-pos-tpsl` unchanged (the endpoint is atomic on both legs).
    // Mirrors the same helper on Bitget\ModifyAlgoOrderJob.
    $fixture = buildBitgetWapTpSlPair();

    $job = new CalculateWapAndModifyProfitOrderJob($fixture['positionId']);

    expect(method_exists($job, 'findSiblingStopLossOrder'))->toBeTrue(
        'Bitget WAP job must expose findSiblingStopLossOrder so the place-pos-tpsl payload can carry the unchanged SL leg.'
    );

    $sibling = $job->findSiblingStopLossOrder();

    expect($sibling)->not->toBeNull()
        ->and($sibling->id)->toBe($fixture['stopLossOrder']->id)
        ->and($sibling->type)->toBe('STOP-MARKET')
        ->and($sibling->is_algo)->toBeTrue();
});

it('findSiblingStopLossOrder returns null when no SL leg exists on the position', function (): void {
    // Defensive: a position somehow without an SL leg cannot be WAP'd
    // via place-pos-tpsl (Bitget rejects single-leg payloads). Compute
    // surfaces this rather than sending a malformed request.
    $fixture = buildBitgetWapTpSlPair();
    $fixture['stopLossOrder']->delete();

    $job = new CalculateWapAndModifyProfitOrderJob($fixture['positionId']);

    expect($job->findSiblingStopLossOrder())->toBeNull();
});

it('keeps classic paired replacement while UTA uses individual strategy modification', function (): void {
    // Classic position-attached TP/SL legs still require place-pos-tpsl.
    // UTA creates standalone strategy legs, so WAP must modify only the
    // profit strategy and leave the stop strategy untouched.
    $source = file_get_contents(
        (new ReflectionClass(CalculateWapAndModifyProfitOrderJob::class))->getFileName()
    );

    expect($source)->toContain('BitgetAccountMode::Unified')
        ->and($source)->toContain('apiModifyTpsl(')
        ->and($source)->toContain('placePosTpsl');
});

it('source code uses preparePlacePosTpslProperties so both TP and SL legs are sent atomically', function (): void {
    // Bitget place-pos-tpsl is atomic on both legs — sending only one
    // would erase the other. The mapper helper bakes both
    // stopSurplusTriggerPrice (TP) and stopLossTriggerPrice (SL) into
    // the request properties.
    $source = file_get_contents(
        (new ReflectionClass(CalculateWapAndModifyProfitOrderJob::class))->getFileName()
    );

    expect($source)->toContain('preparePlacePosTpslProperties');
});
