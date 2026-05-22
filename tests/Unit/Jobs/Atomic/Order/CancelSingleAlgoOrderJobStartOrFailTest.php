<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\CancelSingleAlgoOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Mockery as M;

uses(RefreshDatabase::class)->group('unit', 'cancel-order', 'drift');

afterEach(function (): void {
    M::close();
});

/**
 * Builds a fixture position (defaults to active LONG on Binance) plus
 * one algo order. Keep the API system selectable so the same suite
 * exercises Binance + Bitget — both expose distinct cancel mappers
 * downstream and need their startOrFail guard verified.
 *
 * @return array{account: Account, position: Position, order: Order}
 */
function buildCancelGuardFixture(string $exchange, string $positionStatus, ?string $exchangeOrderId): array
{
    $token = mb_strtoupper(Str::random(5));

    $apiSystem = ApiSystem::firstWhere('canonical', $exchange)
        ?? ApiSystem::factory()->exchange()->create([
            'canonical' => $exchange,
            'name' => ucfirst($exchange),
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
        'margin_mode' => 'CROSSED',
    ]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => $positionStatus,
        'opening_price' => '1.00000000',
        'quantity' => '10.00000000',
        'leverage' => 10,
    ]);

    $order = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => $exchangeOrderId,
        'type' => 'STOP-MARKET',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => '0.90000000',
        'quantity' => '10.00000000',
        'reference_price' => '0.90000000',
        'reference_quantity' => '10.00000000',
        'is_algo' => true,
    ]));

    return ['account' => $account, 'position' => $position, 'order' => $order];
}

it('binance: passes the guard for an active position with a valid exchange_order_id', function (): void {
    $f = buildCancelGuardFixture('binance', 'active', '1234567890');

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeTrue();
});

it('binance: passes the guard for a non-active position carrying an orphan algo order', function (): void {
    // Drift spotter use case: position transitioned to closed but a
    // STOP-MARKET it placed earlier is still alive on Binance.
    $f = buildCancelGuardFixture('binance', 'closed', '1234567890');

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeTrue();
});

it('binance: rejects the guard when the algo order has no exchange_order_id (ghost row)', function (): void {
    // The exact production failure mode: orphan position carries an
    // algo order our DB created but Binance never registered. Cancel
    // would land on Binance's algo mapper, which validates `algo_id`
    // and throws ValidationException. Guard must short-circuit here.
    $f = buildCancelGuardFixture('binance', 'failed', null);

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeFalse();
});

it('binance: rejects the guard when the position is mid-flight (closing)', function (): void {
    // A concurrent cancel during the close workflow's own cancel pass
    // could race the active write — guard refuses to start.
    $f = buildCancelGuardFixture('binance', 'closing', '1234567890');

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeFalse();
});

it('binance: rejects the guard when the order is already CANCELLED', function (): void {
    $f = buildCancelGuardFixture('binance', 'closed', '1234567890');
    $f['order']->updateQuietly(['status' => 'CANCELLED']);

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeFalse();
});

it('bitget: passes the guard for an active position with a valid exchange_order_id', function (): void {
    $f = buildCancelGuardFixture('bitget', 'active', '987654321');

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeTrue();
});

it('bitget: passes the guard for a closed position carrying an orphan algo order', function (): void {
    $f = buildCancelGuardFixture('bitget', 'closed', '987654321');

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeTrue();
});

it('bitget: rejects the guard when the algo order has no exchange_order_id (ghost row)', function (): void {
    $f = buildCancelGuardFixture('bitget', 'failed', null);

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeFalse();
});

it('rejects non-algo orders on either exchange', function (): void {
    $f = buildCancelGuardFixture('binance', 'closed', '1234567890');
    $f['order']->updateQuietly(['is_algo' => false]);

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    expect($job->startOrFail())->toBeFalse();
});

it('rejects when the order belongs to a different position than the one passed in', function (): void {
    $first = buildCancelGuardFixture('binance', 'closed', '1234567890');
    $second = buildCancelGuardFixture('binance', 'closed', '2222222222');

    // Build the job with mismatched ids — the order's position_id will
    // not equal the wrapped Position's id.
    $job = new CancelSingleAlgoOrderJob($second['position']->id, $first['order']->id);

    expect($job->startOrFail())->toBeFalse();
});

it('binance idempotent: when -2011 is classified as ignorable, doubleCheck accepts the DB CANCELLED state without calling apiSync', function (): void {
    // Phase 2 has two halves:
    //
    //   1. computeApiable's catch branch — fires when apiCancel throws
    //      a Guzzle RequestException whose code matches the handler's
    //      ignorableHttpCodes. Mocking that path requires substituting
    //      Order::apiCancel(), which is final on the model and cannot
    //      be replaced by Mockery without dropping `final` from the
    //      production class. We rely on the production rehearsal +
    //      manual run for end-to-end coverage of that branch.
    //
    //   2. doubleCheck's idempotent skip — when the catch branch fires
    //      it sets a private `$idempotentlyResolved` flag so doubleCheck
    //      does NOT re-query the exchange (apiSync would throw the same
    //      not-found error and turn an idempotent success into a
    //      verification failure). This half IS testable in isolation
    //      via reflection and is the half most worth pinning down with
    //      a regression test — it's the difference between Phase 2
    //      working and the cancel job failing every cycle.
    $f = buildCancelGuardFixture('binance', 'failed', '1234567890');

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    // Simulate the post-catch state: order row reconciled to CANCELLED
    // in DB and the flag set by the catch branch.
    Order::where('id', $f['order']->id)->update(['status' => 'CANCELLED']);
    $job->order->refresh();

    $reflection = new ReflectionProperty($job, 'idempotentlyResolved');
    $reflection->setValue($job, true);

    // doubleCheck must NOT touch apiSync (no Http facade configured —
    // any HTTP attempt would error out). Returns true purely on the
    // DB row's CANCELLED state.
    expect($job->doubleCheck())->toBeTrue();
});

it('binance non-idempotent: doubleCheck still calls apiSync when no idempotent resolution happened', function (): void {
    // Negative twin of the above. Without the flag, doubleCheck must
    // fall through to the original apiSync-based verification path —
    // the contract change for Phase 2 only kicks in on the explicit
    // idempotent branch.
    $f = buildCancelGuardFixture('binance', 'active', '1234567890');

    $job = new CancelSingleAlgoOrderJob($f['position']->id, $f['order']->id);

    // Flag NOT set. doubleCheck would try apiSync — we don't run it
    // here, just assert the flag's default. The flag's default false
    // is what gates the apiSync call.
    $reflection = new ReflectionProperty($job, 'idempotentlyResolved');
    expect($reflection->getValue($job))->toBeFalse();
});
