<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\SyncPositionQuantityFromExchangeJob;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareSyncOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;

/**
 * 2026-05-06 — Safety net for partial-fill detection on Bitget.
 *
 * The OrderObserver hook covers fast-path detection (Binance WS / status
 * transitions). It misses Bitget repeats: Bitget's REST mapper falls back
 * to `size` (not `baseVolume`) when `filledQty` is absent in V2 detail —
 * orders.quantity stays static across partial-fill chunks, the row is
 * not dirty on subsequent syncs, the observer never re-fires, and chunks
 * 2..N of the same LIMIT silently miss the position-quantity refresh.
 *
 * `PrepareSyncOrdersJob` runs on every kraite:cron-sync-orders tick. It
 * dispatches a SyncPositionQuantityFromExchangeJob step whenever the
 * position has at least one LIMIT in PARTIALLY_FILLED status. Idempotent
 * + deduped via the same Step::exists check.
 */
function buildSafetyNetPosition(string $exchange, string $limitStatus): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $exchange,
        'name' => mb_ucfirst($exchange),
    ]);

    $symbol = Symbol::factory()->create(['token' => 'GRT']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'GRT',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $accountAttributes = ['api_system_id' => $apiSystem->id, 'on_hedge_mode' => true];

    if ($exchange === 'bitget') {
        $accountAttributes['bitget_api_key'] = 'TESTKEY';
        $accountAttributes['bitget_api_secret'] = 'TESTSECRET';
        $accountAttributes['bitget_passphrase'] = 'TESTPASS';
    } else {
        $accountAttributes['binance_api_key'] = 'TESTKEY';
        $accountAttributes['binance_api_secret'] = 'TESTSECRET';
    }

    $account = Account::factory()->create($accountAttributes);

    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'GRTUSDT',
        'direction' => 'SHORT',
        'status' => 'active',
        'total_limit_orders' => 4,
        'quantity' => '487.50000000',
        'opening_price' => '0.02432000',
        'profit_percentage' => '0.500',
    ]);

    Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'LIMIT-1',
        'type' => 'LIMIT',
        'side' => 'SELL',
        'position_side' => 'SHORT',
        'status' => $limitStatus,
        'reference_status' => $limitStatus,
        'price' => '0.02663',
        'reference_price' => '0.02663',
        'quantity' => '975',
        'reference_quantity' => '975',
        'is_algo' => false,
    ]));

    return $position;
}

function invokeSafetyNet(PrepareSyncOrdersJob $job): void
{
    (new ReflectionMethod($job, 'dispatchPartialFillQuantitySyncSafetyNet'))->invoke($job);
}

it('dispatches a position-quantity sync step when any LIMIT in the position is PARTIALLY_FILLED', function (): void {
    $position = buildSafetyNetPosition('bitget', 'PARTIALLY_FILLED');

    invokeSafetyNet(new PrepareSyncOrdersJob($position->id));

    $count = Step::query()
        ->where('class', SyncPositionQuantityFromExchangeJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count();

    expect($count)->toBe(1);
});

it('does NOT dispatch a position-quantity sync step when no LIMIT is PARTIALLY_FILLED', function (): void {
    $position = buildSafetyNetPosition('bitget', 'NEW');

    invokeSafetyNet(new PrepareSyncOrdersJob($position->id));

    $count = Step::query()
        ->where('class', SyncPositionQuantityFromExchangeJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count();

    expect($count)->toBe(0);
});

it('deduplicates: re-running the cron orchestrator does not pile up duplicate sync steps for the same position', function (): void {
    $position = buildSafetyNetPosition('bitget', 'PARTIALLY_FILLED');

    invokeSafetyNet(new PrepareSyncOrdersJob($position->id));
    invokeSafetyNet(new PrepareSyncOrdersJob($position->id));

    $count = Step::query()
        ->where('class', SyncPositionQuantityFromExchangeJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->whereIn('state', [Pending::class, Dispatched::class])
        ->count();

    expect($count)->toBe(1);
});

it('compute() invokes the safety-net dispatch (regression pin against the dispatch being silently dropped)', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(PrepareSyncOrdersJob::class))->getFileName()
    );

    expect($source)->toContain('private function dispatchPartialFillQuantitySyncSafetyNet(');
    expect($source)->toContain('$this->dispatchPartialFillQuantitySyncSafetyNet();');

    $methodBody = (function () use ($source): string {
        $start = mb_strpos($source, 'private function dispatchPartialFillQuantitySyncSafetyNet(');
        $end = mb_strpos($source, "\n    }", $start);

        return mb_substr($source, $start, $end - $start);
    })();

    expect($methodBody)->toContain('DB::transaction');
    expect($methodBody)->toContain('lockForUpdate');
});
