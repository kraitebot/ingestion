<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\CancelAlgoOpenOrdersJob as AtomicCancelAlgoOpenOrdersJob;
use Kraite\Core\Jobs\Atomic\Position\CancelPositionOpenOrdersJob as AtomicCancelPositionOpenOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Position\PrepareCancelOrphanOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;

uses(RefreshDatabase::class)->group('unit', 'cancel-orphan', 'drift');

/**
 * Builds a position fixture in the requested terminal status. The
 * spotter's orphan-cleanup path hits this job AFTER the parent
 * position has already been resolved to closed/cancelled/failed and
 * the spotter's quiet window has been satisfied.
 *
 * @return array{account: Account, position: Position}
 */
function buildOrphanCancelFixture(string $status): array
{
    $token = mb_strtoupper(Str::random(5));

    $apiSystem = ApiSystem::firstWhere('canonical', 'binance')
        ?? ApiSystem::factory()->exchange()->create([
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

    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => $status,
        'opening_price' => '1.00000000',
        'quantity' => '10.00000000',
    ]);

    return ['account' => $account, 'position' => $position];
}

it('dispatches the cancel-position-open-orders lifecycle for a closed orphan parent', function () {
    // Closed positions that still carry NEW/PARTIALLY_FILLED orders are
    // the spotter's primary orphan use case. The wrapper must spawn
    // both atomic legs of the existing CancelPositionOpenOrders
    // lifecycle: bulk-cancel for regular orders + per-order cancel for
    // algo orders.
    $f = buildOrphanCancelFixture('closed');

    $job = new PrepareCancelOrphanOrdersJob($f['position']->id);
    $blockUuid = Str::uuid()->toString();

    $job->dispatchCancelLifecycle($blockUuid);

    $steps = Step::query()
        ->where('block_uuid', $blockUuid)
        ->orderBy('index')
        ->get();

    expect($steps)->toHaveCount(2);
    expect($steps[0]->arguments['positionId'])->toBe($f['position']->id);
    expect($steps[1]->arguments['positionId'])->toBe($f['position']->id);

    // Class equality checks accept the JobProxy-resolved variant for
    // the account's exchange (e.g. Binance/Bitget overrides) by
    // matching either the base class or any subclass.
    expect(is_a($steps[0]->class, AtomicCancelPositionOpenOrdersJob::class, true))->toBeTrue();
    expect(is_a($steps[1]->class, AtomicCancelAlgoOpenOrdersJob::class, true))->toBeTrue();
});

it('dispatches the lifecycle for a cancelled orphan parent', function () {
    $f = buildOrphanCancelFixture('cancelled');

    $job = new PrepareCancelOrphanOrdersJob($f['position']->id);
    $blockUuid = Str::uuid()->toString();
    $job->dispatchCancelLifecycle($blockUuid);

    expect(Step::where('block_uuid', $blockUuid)->count())->toBe(2);
});

it('dispatches the lifecycle for a failed orphan parent', function () {
    $f = buildOrphanCancelFixture('failed');

    $job = new PrepareCancelOrphanOrdersJob($f['position']->id);
    $blockUuid = Str::uuid()->toString();
    $job->dispatchCancelLifecycle($blockUuid);

    expect(Step::where('block_uuid', $blockUuid)->count())->toBe(2);
});

it('skips the dispatch when the parent position is still active', function () {
    // Sanity guard: the orphan-cancel path only applies when the parent
    // is in a terminal state. An active position still in the live trade
    // loop must NOT have its open orders cancelled by this code path —
    // that's the close workflow's job, not the spotter's.
    $f = buildOrphanCancelFixture('active');

    $job = new PrepareCancelOrphanOrdersJob($f['position']->id);

    expect($job->isOrphanParent())->toBeFalse();
});

it('flags a closed/cancelled/failed parent as a valid orphan parent', function () {
    foreach (['closed', 'cancelled', 'failed'] as $status) {
        $f = buildOrphanCancelFixture($status);
        $job = new PrepareCancelOrphanOrdersJob($f['position']->id);

        expect($job->isOrphanParent())->toBeTrue("status `{$status}` should qualify as orphan parent");
    }
});
