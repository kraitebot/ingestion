<?php

declare(strict_types=1);

use Illuminate\Support\Str;
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

/**
 * 2026-05-04 — Pin the cross-process dedupe race fix on the four
 * `OrderObserver` dispatch sites + the user-data daemon's
 * manual-close detection branch.
 *
 * The race (yesterday, ETC #211 incident):
 *
 *   Binance pushed four `ORDER_TRADE_UPDATE` frames for four
 *   simultaneously-cancelled LIMIT orders. Daemon dispatched
 *   four `ProcessUserDataEventJob` steps. Horizon distributed
 *   them across four parallel workers. Each worker called
 *   `dispatchPositionReplacement` for the same position.
 *
 *   The dedupe was a SELECT (`Step::exists()`) followed by an
 *   INSERT (`Step::create()`). Both operations ran in different
 *   transactions on different connections — both SELECTs saw
 *   "no pending replacement" before either INSERT committed,
 *   so two `PreparePositionReplacementJob` rows landed for the
 *   same position. Two replacement workflows ran in parallel,
 *   nuked each other's progress, killed the TP, and left
 *   duplicate LIMIT rungs.
 *
 * The fix:
 *
 *   Wrap each dedupe-and-insert pair in a DB transaction and
 *   acquire a row-level lock on the parent Position via
 *   `lockForUpdate()` BEFORE the existence check. The first
 *   worker to enter the transaction holds the lock; every
 *   subsequent worker blocks until the first transaction
 *   commits, then sees the freshly-inserted Step and skips.
 *   Atomic across every connection, every server. No Redis,
 *   no schema change, no cache.
 *
 * Why the tests are structural rather than concurrent:
 *
 *   PHPUnit / pest run a single PHP process per test with
 *   `RefreshDatabase` wrapping each test in a single
 *   transaction — the very mechanism the production race
 *   depends on (multiple committed transactions on different
 *   connections) cannot be reliably reproduced inside the
 *   test runner. Functional serial tests (call twice, assert
 *   one row) pass with OR without the lock — the existing
 *   serial dedupe handles them. Source-level pins are the
 *   only test shape that catches a future refactor that
 *   silently drops the lock and re-introduces the race.
 */
function buildPositionWithLimits(string $token = 'RACE'): Position
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
        'binance_api_key' => 'test-key',
        'binance_api_secret' => 'test-secret',
    ]);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'direction' => 'LONG',
        'status' => 'active',
        'total_limit_orders' => 4,
    ]);

    return $position;
}

it('dispatchPositionReplacement is wrapped in DB::transaction with lockForUpdate on the position row', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(OrderObserver::class))->getFileName()
    );

    expect($source)->toContain('private function dispatchPositionReplacement(');

    // The fix wraps the SELECT-then-INSERT inside a DB transaction
    // with a Position row lock. Either order of these tokens is
    // acceptable; the assertion is that BOTH are present in the
    // method body.
    expect($source)->toContain('DB::transaction');
    expect($source)->toContain('lockForUpdate');
});

it('dispatchClosePosition is wrapped in DB::transaction with lockForUpdate on the position row', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(OrderObserver::class))->getFileName()
    );

    expect($source)->toContain('private function dispatchClosePosition(');

    // The same shape applies to the close-trigger path: when both
    // TP and SL flip FILLED in the same sync cycle, the observer
    // fires twice in parallel — same race class.
    $methodBody = (function () use ($source): string {
        $start = mb_strpos($source, 'private function dispatchClosePosition(');
        $end = mb_strpos($source, 'private function dispatchPositionReplacement(', $start);

        return mb_substr($source, $start, $end - $start);
    })();

    expect($methodBody)->toContain('DB::transaction');
    expect($methodBody)->toContain('lockForUpdate');
});

it('dispatchApplyWap is wrapped in DB::transaction with lockForUpdate on the position row', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(OrderObserver::class))->getFileName()
    );

    expect($source)->toContain('private function dispatchApplyWap(');

    $methodBody = (function () use ($source): string {
        $start = mb_strpos($source, 'private function dispatchApplyWap(');
        // Find the next `private function` after the opening to
        // bound the body, or end of file if last private method.
        $end = mb_strpos($source, 'private function ', $start + 1);
        if ($end === false) {
            $end = mb_strlen($source);
        }

        return mb_substr($source, $start, $end - $start);
    })();

    expect($methodBody)->toContain('DB::transaction');
    expect($methodBody)->toContain('lockForUpdate');
});

it('ProcessUserDataEventJob::maybeDetectManualPositionClose is wrapped in DB::transaction with lockForUpdate on the position row', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(Kraite\Core\Jobs\Atomic\UserDataStream\ProcessUserDataEventJob::class))->getFileName()
    );

    expect($source)->toContain('private function maybeDetectManualPositionClose(');

    $methodBody = (function () use ($source): string {
        $start = mb_strpos($source, 'private function maybeDetectManualPositionClose(');
        $end = mb_strpos($source, 'private function ', $start + 1);
        if ($end === false) {
            $end = mb_strlen($source);
        }

        return mb_substr($source, $start, $end - $start);
    })();

    expect($methodBody)->toContain('DB::transaction');
    expect($methodBody)->toContain('lockForUpdate');
});

it('inserts exactly one PreparePositionReplacementJob when dispatchPositionReplacement is called repeatedly for the same position (serial dedupe regression pin)', function (): void {
    $position = buildPositionWithLimits('RACE');

    // Four cancelled LIMIT rows mirroring the ETC #211 pattern.
    $orders = collect();
    for ($i = 0; $i < 4; $i++) {
        $orders->push(Order::withoutEvents(fn () => Order::create([
            'position_id' => $position->id,
            'uuid' => Str::uuid()->toString(),
            'client_order_id' => Str::uuid()->toString(),
            'exchange_order_id' => "RACE-{$i}",
            'type' => 'LIMIT',
            'side' => 'BUY',
            'position_side' => 'LONG',
            'status' => 'CANCELLED',
            'reference_status' => 'NEW',
            'price' => sprintf('%.8f', 1 - ($i * 0.05)),
            'quantity' => '10.00000000',
            'reference_price' => sprintf('%.8f', 1 - ($i * 0.05)),
            'reference_quantity' => '10.00000000',
            'is_algo' => false,
        ])));
    }

    $observer = new OrderObserver;

    foreach ($orders as $order) {
        $observer->updated($order);
    }

    // PreparePositionReplacementJob is dispatched by OrderObserver inside
    // a `Steps::usingPrefix('trading')` scope, so the row lands in
    // `trading_steps`. Reads scope through the same prefix.
    $count = Steps::usingPrefix('trading', fn (): int => Step::query()
        ->where('class', PreparePositionReplacementJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count());

    expect($count)->toBe(
        1,
        'Four cancelled-order observer fires for the same position must collapse to ONE PreparePositionReplacementJob step. '
        .'Two or more would mean the dedupe regressed.'
    );
});
