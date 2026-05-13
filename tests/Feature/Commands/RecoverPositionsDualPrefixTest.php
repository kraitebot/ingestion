<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Commands\RecoverPositionsCommand;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\Steps;

/**
 * Pins the dual-prefix safety contract for kraite:recover-positions.
 *
 * `StepDispatcher::deactivate()` and `Step::query()` are both prefix-scoped
 * via `RuntimeContext::current()`. Pre-fix, recovery only froze and cancelled
 * the default `steps` prefix — leaving `trading_steps` (which owns every
 * trading workflow on the system) running while recovery was mutating
 * positions and orders. Post-fix, recovery operates on BOTH prefixes:
 *
 *   - deactivate (and later activate) the dispatcher in default + trading
 *   - cancel in-flight rows referencing wiped position/order ids in BOTH
 *     `steps` and `trading_steps`
 */
function makeDualPrefixTestPosition(): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'DPTEST']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'DPTEST',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'is_active' => true,
    ]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'active',
        'quantity' => '100',
        'opening_price' => '1.0',
    ]);
}

it('cancelInflightStepsReferencing cancels rows in BOTH default and trading prefixes', function (): void {
    $position = makeDualPrefixTestPosition();

    $defaultStepId = (int) Step::query()->insertGetId([
        'class' => 'SomeDefaultJob',
        'queue' => 'default',
        'state' => Pending::class,
        'arguments' => json_encode(['positionId' => $position->id]),
        'block_uuid' => (string) Str::uuid(),
        'workflow_id' => null,
        'index' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tradingStepId = Steps::usingPrefix('trading', fn (): int => (int) Step::query()->insertGetId([
        'class' => 'SomeTradingJob',
        'queue' => 'positions',
        'state' => Pending::class,
        'arguments' => json_encode(['positionId' => $position->id]),
        'block_uuid' => (string) Str::uuid(),
        'workflow_id' => null,
        'index' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $command = new RecoverPositionsCommand;
    $ref = new ReflectionClass($command);
    $method = $ref->getMethod('cancelInflightStepsReferencing');
    $method->setAccessible(true);

    $cancelledCount = $method->invoke($command, [$position->id], []);

    expect($cancelledCount)->toBeGreaterThanOrEqual(2);

    $defaultRow = Step::query()->find($defaultStepId);
    $tradingRow = Steps::usingPrefix('trading', fn () => Step::query()->find($tradingStepId));

    expect($defaultRow->state)->toBeInstanceOf(Cancelled::class)
        ->and($tradingRow->state)->toBeInstanceOf(Cancelled::class);
});
