<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Observers\OrderObserver;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

function countTradingSteps(string $class, int $positionId): int
{
    return Steps::usingPrefix('trading', fn (): int => (int) Step::query()
        ->where('class', $class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$positionId])
        ->count());
}

/**
 * Pins the locked-row re-check invariant inside OrderObserver dispatch
 * sites. The observer's pre-lock entry guard at OrderObserver::updated()
 * line 164 catches the steady case (position already non-active when the
 * event arrives), but it does not catch the race where worker A passes
 * the entry guard, blocks waiting for the position-row lock, and only
 * sees the stale `$position` model when the lock is finally released.
 * Without re-reading the locked row, worker A would dispatch a close /
 * replacement / WAP step against a position that worker B has already
 * moved to a terminal lifecycle.
 *
 * These tests bypass the public updated() entry by invoking the private
 * dispatch methods directly via reflection — that simulates worker A
 * having already passed the entry guard while a concurrent worker
 * advances the DB row to a non-active state.
 */
function buildObserverLockTestPosition(string $token): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
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
    ]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'active',
        'quantity' => '100',
        'opening_price' => '1.0',
        'total_limit_orders' => 4,
    ]);
}

function invokePrivate(object $instance, string $method, array $args): void
{
    $ref = new ReflectionClass($instance);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    $m->invoke($instance, ...$args);
}

it('dispatchClosePosition skips when locked position has moved to a non-active state', function (): void {
    $position = buildObserverLockTestPosition('CLOSELOCK');
    $model = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'status' => 'FILLED',
        'reference_status' => 'NEW',
        'price' => '1.05',
        'quantity' => '100',
    ]));

    // Concurrent worker B advances the position to a terminal lifecycle.
    // Use a raw query so the in-memory $position model stays stale.
    DB::table('positions')->where('id', $position->id)->update(['status' => 'cancelling']);

    invokePrivate(new OrderObserver, 'dispatchClosePosition', [$model, $position]);

    expect(countTradingSteps(ClosePositionJob::class, $position->id))->toBe(0);
});

it('dispatchPositionReplacement skips when locked position has moved to a non-active state', function (): void {
    $position = buildObserverLockTestPosition('REPLLOCK');
    $model = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'CANCELLED',
        'reference_status' => 'NEW',
        'price' => '0.95',
        'quantity' => '50',
    ]));

    DB::table('positions')->where('id', $position->id)->update(['status' => 'closed']);

    invokePrivate(new OrderObserver, 'dispatchPositionReplacement', [$model, $position]);

    expect(countTradingSteps(PreparePositionReplacementJob::class, $position->id))->toBe(0);
});

it('dispatchApplyWap skips when locked position has moved to a non-active state', function (): void {
    $position = buildObserverLockTestPosition('WAPLOCK');
    $model = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'FILLED',
        'reference_status' => 'NEW',
        'price' => '0.97',
        'quantity' => '60',
    ]));

    DB::table('positions')->where('id', $position->id)->update(['status' => 'closing']);

    invokePrivate(new OrderObserver, 'dispatchApplyWap', [$model, $position]);

    expect(countTradingSteps(ApplyWapJob::class, $position->id))->toBe(0);
});

it('dispatchClosePosition still creates a step on the steady-state active path', function (): void {
    $position = buildObserverLockTestPosition('CLOSEOK');
    $model = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'status' => 'FILLED',
        'reference_status' => 'NEW',
        'price' => '1.05',
        'quantity' => '100',
    ]));

    invokePrivate(new OrderObserver, 'dispatchClosePosition', [$model, $position]);

    expect(countTradingSteps(ClosePositionJob::class, $position->id))->toBe(1);
});
