<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\DispatchPositionSlotsJob;
use Kraite\Core\Jobs\Lifecycles\Position\Binance\DispatchPositionJob as BinanceDispatchPositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

beforeEach(function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    // The account's user must be tradeable so DispatchPositionSlotsJob's
    // final-boundary re-check (added 2026-05-13 review-18) doesn't bail.
    // UserFactory defaults to `can_trade=false`; override to true for
    // these idempotency tests, which are scoped to step-creation
    // semantics rather than user-state gating.
    $user = Kraite\Core\Models\User::factory()->create([
        'is_active' => true,
        'can_trade' => true,
    ]);

    $this->account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
        'is_active' => true,
        'can_trade' => true,
    ]);

    $symbolA = Symbol::factory()->create(['token' => 'BTC']);
    $symbolB = Symbol::factory()->create(['token' => 'ETH']);

    $this->exchangeSymbolA = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbolA->id,
    ]);

    $this->exchangeSymbolB = ExchangeSymbol::factory()->create([
        'token' => 'ETH',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbolB->id,
    ]);
});

/**
 * `DispatchPositionSlotsJob` idempotency contract.
 *
 * The 2026-04-25 17:33-17:34 cluster of 12 Failed steps was caused by two
 * `PreparePositionsOpeningJob` blocks for the same account dispatched 1s
 * apart (an operator manual `kraite:cron-create-positions` racing the
 * scheduled cron during wedge debug). `AssignBestTokensToPositionSlotsJob`
 * is idempotent — its second instance saw the existing 'new' positions
 * and created zero new ones. `DispatchPositionSlotsJob` is NOT — it
 * iterated `where status='new' AND exchange_symbol_id NOT NULL` and
 * created a fresh `DispatchPositionJob` step for every match, regardless
 * of whether a non-terminal `DispatchPositionJob` step already existed.
 * Result: 12 `DispatchPositionJob` blocks for 6 positions, two
 * positions (#241 + #242) raced past the verify gate and reached
 * `DispatchLimitOrdersJob` after the first block had already filled the
 * `total_limit_orders` cap → cap-exceeded exception → cancel workflow →
 * realised loss.
 *
 * Contract: for every 'new' position with a token assigned, the
 * orchestrator MUST skip creating a new `DispatchPositionJob` step when
 * any non-terminal `DispatchPositionJob` step already exists for that
 * position. Same shape as the orphan-recovery dedup landed in v1.5.5.
 */
function dispatchPositionStepsForPosition(Position $position): Illuminate\Database\Eloquent\Collection
{
    return Step::query()
        ->whereIn('class', [BaseDispatchPositionJob::class, BinanceDispatchPositionJob::class])
        ->whereJsonContains('arguments->positionId', $position->id)
        ->get();
}

it('does not create a duplicate DispatchPositionJob step when one already exists for the position', function (): void {
    $positionA = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbolA->id,
        'status' => 'new',
        'direction' => 'LONG',
    ]);

    $positionB = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbolB->id,
        'status' => 'new',
        'direction' => 'SHORT',
    ]);

    // Position A already has a live (non-terminal) DispatchPositionJob step
    // — simulating the first DispatchPositionSlotsJob instance that already
    // ran. The second instance should NOT duplicate this.
    Step::create([
        'class' => BinanceDispatchPositionJob::class,
        'queue' => 'positions',
        'relatable_type' => Position::class,
        'relatable_id' => $positionA->id,
        'arguments' => ['positionId' => $positionA->id],
    ]);

    $parentBlockUuid = (string) Str::uuid();
    $parentStep = Step::create([
        'class' => DispatchPositionSlotsJob::class,
        'arguments' => ['accountId' => $this->account->id],
        'queue' => 'cronjobs',
        'index' => 1,
        'block_uuid' => $parentBlockUuid,
    ]);

    $job = new DispatchPositionSlotsJob($this->account->id);
    $job->step = $parentStep;
    $job->compute();

    expect(dispatchPositionStepsForPosition($positionA))->toHaveCount(
        1,
        'Position A already had a live DispatchPositionJob step — a duplicate '
        .'concurrent DispatchPositionSlotsJob run must not double-dispatch. '
        .'The 2026-04-25 17:33 cluster (12 Failed steps, realised loss on positions '
        .'#241 + #242) was caused exactly by this missing idempotency guard.'
    );

    expect(dispatchPositionStepsForPosition($positionB))->toHaveCount(
        1,
        'Position B had no existing step — orchestrator must still dispatch '
        .'this one fresh. Idempotency must skip duplicates, not block all dispatches.'
    );

    expect(dispatchPositionStepsForPosition($positionB)->first()->state)->toBeInstanceOf(
        Pending::class,
        'Fresh dispatch lands in Pending so the next dispatcher tick promotes it.'
    );
});

it('does not let an argument-only legacy row block an indexed position dispatch', function (): void {
    $position = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbolA->id,
        'status' => 'new',
        'direction' => 'LONG',
    ]);

    Step::create([
        'class' => BinanceDispatchPositionJob::class,
        'queue' => 'positions',
        'arguments' => ['positionId' => $position->id],
    ]);

    $parentStep = Step::create([
        'class' => DispatchPositionSlotsJob::class,
        'arguments' => ['accountId' => $this->account->id],
        'queue' => 'cronjobs',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    $job = new DispatchPositionSlotsJob($this->account->id);
    $job->step = $parentStep;
    $job->compute();

    $steps = dispatchPositionStepsForPosition($position);

    expect($steps)->toHaveCount(2)
        ->and($steps->where('relatable_type', Position::class))->toHaveCount(1)
        ->and((int) $steps->firstWhere('relatable_type', Position::class)->relatable_id)->toBe($position->id);
});

it('treats only non-terminal DispatchPositionJob steps as a duplicate signal', function (): void {
    $position = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbolA->id,
        'status' => 'new',
        'direction' => 'LONG',
    ]);

    // A previous attempt that already concluded in a terminal state. The
    // position is still 'new' which means the previous workflow either
    // never reached the open or got cancelled mid-flight. Recovery must
    // treat this as orphan and dispatch fresh.
    $oldStep = Step::create([
        'class' => BinanceDispatchPositionJob::class,
        'queue' => 'positions',
        'arguments' => ['positionId' => $position->id],
    ]);
    Step::withoutEvents(function () use ($oldStep): void {
        Step::where('id', $oldStep->id)->update([
            'state' => StepDispatcher\States\Cancelled::class,
        ]);
    });

    $parentBlockUuid = (string) Str::uuid();
    $parentStep = Step::create([
        'class' => DispatchPositionSlotsJob::class,
        'arguments' => ['accountId' => $this->account->id],
        'queue' => 'cronjobs',
        'index' => 1,
        'block_uuid' => $parentBlockUuid,
    ]);

    $job = new DispatchPositionSlotsJob($this->account->id);
    $job->step = $parentStep;
    $job->compute();

    $liveSteps = Step::query()
        ->whereIn('class', [BaseDispatchPositionJob::class, BinanceDispatchPositionJob::class])
        ->whereJsonContains('arguments->positionId', $position->id)
        ->whereNotIn('state', Step::terminalStepStates())
        ->get();

    expect($liveSteps)->toHaveCount(
        1,
        'Terminal-state DispatchPositionJob steps must NOT count as a duplicate — '
        .'a position whose only step ended Cancelled is still stranded and must '
        .'be re-dispatched.'
    );
});
