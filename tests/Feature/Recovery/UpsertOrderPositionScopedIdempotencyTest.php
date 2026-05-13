<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pins the position-scoped idempotency key for `upsertOrder()`.
 *
 * Pre-fix, `Order::query()->where('exchange_order_id', $id)->exists()`
 * was global. Two accounts on different exchanges (or even the same
 * exchange's regular vs algo id spaces) carrying the same numeric
 * exchange_order_id would silently drop the second insert. Within a
 * single position the id space IS unambiguous; that is the right scope.
 */
it('AbstractPositionRecoverer::upsertOrder scopes the dedupe SELECT by position_id', function (): void {
    $source = file_get_contents(
        base_path('vendor/kraitebot/core/src/Support/Recovery/AbstractPositionRecoverer.php')
    );

    expect($source)->toMatch("/->where\('position_id',\s*\\\$position->id\)\s*\\n\\s*->where\('exchange_order_id'/s");
});

it('two positions can carry orders with the same exchange_order_id at the model level', function (): void {
    $apiSystemA = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $apiSystemB = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'COLLIDE']);
    $exA = ExchangeSymbol::factory()->create([
        'token' => 'COLLIDE',
        'quote' => 'USDT',
        'api_system_id' => $apiSystemA->id,
        'symbol_id' => $symbol->id,
    ]);
    $exB = ExchangeSymbol::factory()->create([
        'token' => 'COLLIDE',
        'quote' => 'USDT',
        'api_system_id' => $apiSystemB->id,
        'symbol_id' => $symbol->id,
    ]);

    $accA = Account::factory()->create(['api_system_id' => $apiSystemA->id]);
    $accB = Account::factory()->create(['api_system_id' => $apiSystemB->id]);

    $posA = Position::factory()->long()->create([
        'account_id' => $accA->id,
        'exchange_symbol_id' => $exA->id,
        'status' => 'active',
    ]);
    $posB = Position::factory()->long()->create([
        'account_id' => $accB->id,
        'exchange_symbol_id' => $exB->id,
        'status' => 'active',
    ]);

    $sharedExchangeOrderId = '777888999';

    $orderA = Order::withoutEvents(fn () => Order::create([
        'position_id' => $posA->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'NEW',
        'price' => '0.95',
        'quantity' => '50',
        'exchange_order_id' => $sharedExchangeOrderId,
    ]));

    $orderB = Order::withoutEvents(fn () => Order::create([
        'position_id' => $posB->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'NEW',
        'price' => '0.95',
        'quantity' => '50',
        'exchange_order_id' => $sharedExchangeOrderId,
    ]));

    expect($orderA->id)->not->toBe($orderB->id)
        ->and($orderA->exchange_order_id)->toBe($orderB->exchange_order_id);

    // The position-scoped dedupe contract: scoping by position_id makes
    // these two rows non-colliding. The recoverer's SELECT now respects
    // that scope.
    $existsForA = Order::query()
        ->where('position_id', $posA->id)
        ->where('exchange_order_id', $sharedExchangeOrderId)
        ->exists();

    $existsForB = Order::query()
        ->where('position_id', $posB->id)
        ->where('exchange_order_id', $sharedExchangeOrderId)
        ->exists();

    expect($existsForA)->toBeTrue()
        ->and($existsForB)->toBeTrue();
});
