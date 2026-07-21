<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\Bitget\PlacePositionTpslJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

it('defaults replacement ids for queued payloads created before the property existed', function (): void {
    $job = (new ReflectionClass(PlacePositionTpslJob::class))->newInstanceWithoutConstructor();

    expect($job->replacedOrderIds)->toBe([]);
});

/**
 * Regression guard for the THETAUSDT cancel on 2026-04-26 (position 423).
 *
 * `PlacePositionTpslJob::computeApiable()` placed both TP and SL on Bitget
 * successfully (response captured both `take_profit_id` and `stop_loss_id`,
 * Order rows were created with non-null `exchange_order_id`). The step then
 * went into `doubleCheck()` which unconditionally re-queried Bitget via
 * `getPositions()`. That follow-up call was rate-limited (HTTP 429), and
 * because `doubleCheck()` runs OUTSIDE the `compute()` try/catch that wires
 * `handleApiException`, the exception killed the step instead of being
 * routed through the rate-limit retry path. The position was cancelled and
 * the already-placed exchange orders had to be cleaned up by the rollback
 * workflow.
 *
 * Two invariants must hold:
 *
 * 1. If `computeApiable()` already captured both `exchange_order_id`s,
 *    `doubleCheck()` MUST short-circuit `true` without making any further
 *    API call. The 99% production path never needs the re-query.
 *
 * 2. When a re-query IS needed (Bitget eventual consistency returned a
 *    null id at place time), the call MUST be wrapped so a transient
 *    failure returns `false` (triggering a bounded doubleCheck retry)
 *    instead of letting the raw exception escape the step.
 */
function buildBitgetTpslJobFixture(?string $tpExchangeId, ?string $slExchangeId): array
{
    $token = 'BTGT'.mb_strtoupper(Str::random(4));

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
        'bitget_account_mode' => 'classic',
    ]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => 'active',
        'opening_price' => '36.71000000',
        'quantity' => '0.42000000',
        'profit_percentage' => '0.360',
        'leverage' => 20,
    ]);

    // Anchor limit order — required so position->lastLimitOrder() returns non-null.
    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => '24.22000000',
        'quantity' => '6.72000000',
        'exchange_order_id' => '9999998888',
    ]));

    $profitOrder = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'price' => '36.84000000',
        'quantity' => '0.42000000',
        'exchange_order_id' => $tpExchangeId,
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
        'price' => '23.61000000',
        'quantity' => '0.42000000',
        'exchange_order_id' => $slExchangeId,
        'is_algo' => true,
    ]));

    return [
        'positionId' => $position->id,
        'profitOrder' => $profitOrder->refresh(),
        'stopLossOrder' => $stopLossOrder->refresh(),
    ];
}

it('short-circuits doubleCheck without any API call when both TP and SL exchange ids are populated', function (): void {
    $fixture = buildBitgetTpslJobFixture(
        tpExchangeId: '1432340348721946625',
        slExchangeId: '1432340348768083968',
    );

    $job = new PlacePositionTpslJob($fixture['positionId']);
    $job->profitOrder = $fixture['profitOrder'];
    $job->stopLossOrder = $fixture['stopLossOrder'];

    // The fast path must return true without ever reaching
    // `queryPositionForTpslIds()`. If the slow path were entered, the
    // unmocked `getPositions()` call would attempt a real HTTP request
    // (forbidden by `Http::preventStrayRequests()` in tests) and this
    // assertion would fail with a thrown exception instead of `true`.
    expect($job->doubleCheck())->toBeTrue(
        'doubleCheck() must short-circuit and return true when both order rows '
        .'already carry an exchange_order_id from computeApiable() — re-querying '
        .'Bitget here was the cause of the THETAUSDT 2026-04-26 cancel.'
    );
});

it('returns false instead of propagating when the slow-path query throws (e.g. transient 429)', function (): void {
    $fixture = buildBitgetTpslJobFixture(
        tpExchangeId: '1432340348721946625',
        slExchangeId: null, // Forces the slow path: re-query is needed.
    );

    $job = new PlacePositionTpslJob($fixture['positionId']);
    $job->profitOrder = $fixture['profitOrder'];
    $job->stopLossOrder = $fixture['stopLossOrder'];

    // The slow path enters `queryPositionForTpslIds()`. With no HTTP fakes
    // configured, `Http::preventStrayRequests()` forces the underlying call
    // to throw — the exact transient-failure shape we need to verify the
    // catch block swallows. Without the catch the exception would escape
    // the step (the THETAUSDT failure mode).
    expect($job->doubleCheck())->toBeFalse(
        'A transient failure during the slow-path re-query must be swallowed '
        .'so the step framework retries doubleCheck — not propagated as a step failure.'
    );
});

it('returns false when only the TP exchange id is missing (slow path required)', function (): void {
    $fixture = buildBitgetTpslJobFixture(
        tpExchangeId: null,
        slExchangeId: '1432340348768083968',
    );

    $job = new PlacePositionTpslJob($fixture['positionId']);
    $job->profitOrder = $fixture['profitOrder'];
    $job->stopLossOrder = $fixture['stopLossOrder'];

    expect($job->doubleCheck())->toBeFalse(
        'When either exchange_order_id is null the slow path must run; with no '
        .'HTTP fake configured, the catch returns false rather than propagating.'
    );
});

it('returns false when both Order properties are unset', function (): void {
    $fixture = buildBitgetTpslJobFixture(
        tpExchangeId: '1432340348721946625',
        slExchangeId: '1432340348768083968',
    );

    $job = new PlacePositionTpslJob($fixture['positionId']);
    // Intentionally leave profitOrder and stopLossOrder null — the case where
    // doubleCheck runs before computeApiable populated them (e.g. an
    // out-of-order step retry). Must not crash, must return false.

    expect($job->doubleCheck())->toBeFalse(
        'When the Order properties are null doubleCheck must return false '
        .'without crashing or attempting any API call.'
    );
});

it('rehydrates both protection orders when the dispatcher reconstructs the job', function (): void {
    $fixture = buildBitgetTpslJobFixture(
        tpExchangeId: 'TP-EXCHANGE-REHYDRATED',
        slExchangeId: 'SL-EXCHANGE-REHYDRATED',
    );

    $job = new PlacePositionTpslJob(
        $fixture['positionId'],
        $fixture['profitOrder']->id,
        $fixture['stopLossOrder']->id,
    );

    expect($job->profitOrder?->id)->toBe($fixture['profitOrder']->id)
        ->and($job->stopLossOrder?->id)->toBe($fixture['stopLossOrder']->id)
        ->and($job->doubleCheck())->toBeTrue();
});

it('persists protection identity before HTTP and uses returned IDs without querying positions', function (): void {
    $fixture = buildBitgetTpslJobFixture(tpExchangeId: null, slExchangeId: null);
    $position = Position::findOrFail($fixture['positionId']);
    $fixture['profitOrder']->delete();
    $fixture['stopLossOrder']->delete();

    KraiteModel::query()->findOrFail(1)->update([
        'bitget_api_key' => 'ADMIN-KEY',
        'bitget_api_secret' => 'ADMIN-SECRET',
        'bitget_passphrase' => 'ADMIN-PASSPHRASE',
    ]);
    $position->account->update([
        'bitget_api_key' => 'ACCOUNT-KEY',
        'bitget_api_secret' => 'ACCOUNT-SECRET',
        'bitget_passphrase' => 'ACCOUNT-PASSPHRASE',
    ]);
    $step = Step::create([
        'class' => PlacePositionTpslJob::class,
        'queue' => 'positions',
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'arguments' => ['positionId' => $position->id],
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/market/symbol-price')) {
            return Http::response([
                'code' => '00000',
                'data' => [['symbol' => $request['symbol'], 'markPrice' => '36.70']],
            ]);
        }

        if (str_contains($request->url(), '/place-pos-tpsl')) {
            return Http::response([
                'code' => '00000',
                'data' => [
                    [
                        'orderId' => 'TP-EXCHANGE-DIRECT',
                        'stopSurplusClientOid' => $request['stopSurplusClientOid'],
                        'stopLossClientOid' => '',
                    ],
                    [
                        'orderId' => 'SL-EXCHANGE-DIRECT',
                        'stopSurplusClientOid' => '',
                        'stopLossClientOid' => $request['stopLossClientOid'],
                    ],
                ],
            ]);
        }

        throw new RuntimeException('Position query must not run when placement returned both IDs.');
    });

    $job = new PlacePositionTpslJob($position->id);
    $job->step = $step;
    $result = $job->computeApiable();

    $orders = $position->orders()
        ->whereIn('type', ['PROFIT-LIMIT', 'STOP-MARKET'])
        ->get()
        ->keyBy('type');
    $persistedArguments = $step->fresh()->arguments;

    expect($orders)->toHaveCount(2)
        ->and($orders['PROFIT-LIMIT']->exchange_order_id)->toBe('TP-EXCHANGE-DIRECT')
        ->and($orders['STOP-MARKET']->exchange_order_id)->toBe('SL-EXCHANGE-DIRECT')
        ->and($persistedArguments['profitOrderId'])->toBe($orders['PROFIT-LIMIT']->id)
        ->and($persistedArguments['stopLossOrderId'])->toBe($orders['STOP-MARKET']->id)
        ->and($result['take_profit_id'])->toBe('TP-EXCHANGE-DIRECT')
        ->and($result['stop_loss_id'])->toBe('SL-EXCHANGE-DIRECT');

    Http::assertSentCount(2);
});
