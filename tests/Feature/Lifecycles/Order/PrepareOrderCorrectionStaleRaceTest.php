<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Order\Bitget\PrepareOrderCorrectionJob as BitgetPrepareOrderCorrectionJob;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareOrderCorrectionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Skipped;
use StepDispatcher\Support\Steps;

/**
 * @param  class-string<PrepareOrderCorrectionJob|BitgetPrepareOrderCorrectionJob>  $jobClass
 * @return array{job: PrepareOrderCorrectionJob|BitgetPrepareOrderCorrectionJob, step: Step, order: Order, position: Position}
 */
function buildStaleCorrectionRace(string $canonical, string $jobClass): array
{
    $token = 'SCR'.mb_strtoupper(Str::random(5));
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => Str::headline($canonical),
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
        'parsed_trading_pair' => $token.'USDT',
        'direction' => 'SHORT',
        'status' => 'active',
    ]);
    $order = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'stale-correction-'.Str::random(8),
        'type' => 'PROFIT-LIMIT',
        'side' => 'BUY',
        'position_side' => 'SHORT',
        'status' => 'FILLED',
        'reference_status' => 'NEW',
        'price' => '4.10499999',
        'reference_price' => '4.10500000',
        'quantity' => '20.99000000',
        'reference_quantity' => '20.99000000',
        'is_algo' => false,
    ]));

    $step = Steps::usingPrefix('trading', fn (): Step => Step::create([
        'class' => $jobClass,
        'queue' => 'priority',
        'priority' => 'high',
        'relatable_type' => $position->getMorphClass(),
        'relatable_id' => $position->id,
        'arguments' => [
            'positionId' => $position->id,
            'orderId' => $order->id,
            'message' => 'Production partial-fill race regression',
        ],
    ]));

    $job = new $jobClass($position->id, $order->id);
    $job->step = $step;

    return compact('job', 'step', 'order', 'position');
}

it('records a correction that became terminal before pickup as skipped', function (string $canonical, string $jobClass): void {
    $fixture = buildStaleCorrectionRace($canonical, $jobClass);

    expect($fixture['step']->state)->toBeInstanceOf(Pending::class)
        ->and($fixture['order']->status)->toBe('FILLED')
        ->and($fixture['order']->getRawOriginal('price'))->toBe('4.10499999')
        ->and($fixture['order']->getRawOriginal('reference_price'))->toBe('4.10500000');

    Steps::usingPrefix('trading', fn () => $fixture['job']->handle());

    $freshStep = Steps::usingPrefix('trading', fn (): Step => $fixture['step']->fresh());

    expect($freshStep->state)->toBeInstanceOf(Skipped::class)
        ->and($freshStep->child_block_uuid)->toBeNull()
        ->and($fixture['position']->fresh()->status)->toBe('active')
        ->and($fixture['order']->fresh()->status)->toBe('FILLED');
})->with([
    'Binance correction' => ['binance', PrepareOrderCorrectionJob::class],
    'Bitget correction' => ['bitget', BitgetPrepareOrderCorrectionJob::class],
]);
