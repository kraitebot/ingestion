<?php

declare(strict_types=1);

use Kraite\Core\Commands\Cronjobs\CreatePositionsCommand;
use Kraite\Core\Jobs\Lifecycles\Account\PreparePositionsOpeningJob;
use Kraite\Core\Jobs\Lifecycles\Position\Binance\DispatchPositionJob as BinanceDispatchPositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\DispatchPositionJob as BaseDispatchPositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\User;
use Kraite\Core\Trading\Kraite;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\Steps;

/*
 * CreatePositionsCommand wraps every dispatch site (orphan recovery + new
 * opens) in `Steps::usingPrefix('trading')`, so the rows live in
 * `trading_steps`. Reads + writes in this test must scope through the
 * same prefix so they target the same table.
 */

beforeEach(function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $user = User::factory()->create([
        'can_trade' => true,
    ]);

    $this->account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => $user->id,
        'is_active' => true,
        'can_trade' => true,
        'total_positions_long' => 1,
        'total_positions_short' => 0,
    ]);

    $symbol = Symbol::factory()->create(['token' => 'BTC']);

    $this->exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'BTC',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);
});

/**
 * Position 235 / sweep-casualty recovery contract.
 *
 * The 2026-04-25 dispatcher wedge was drained by manually deleting stale
 * Pending/Running rows. Two positions (233, 235) survived the sweep but
 * their `DispatchPositionJob` follow-up step did NOT — the row was
 * deleted alongside the genuinely-stale work. Result: positions stuck in
 * `status='new'` with a token assigned, no live workflow to advance
 * them, no automatic recovery path. Operator had to manually re-dispatch
 * via tinker.
 *
 * Contract: every `kraite:cron-create-positions` tick must re-dispatch
 * orphan 'new' positions (status='new', exchange_symbol_id NOT NULL)
 * that have no live `DispatchPositionJob` step. Self-heals the next tick
 * after any operational disturbance — operator drain, supervisor
 * restart, half-truncated cleanup, anything that loses a step row while
 * leaving the domain row behind.
 *
 * The recovery path runs BEFORE the engine guards (slot-cap, directional,
 * exchange cooldown). Orphans must be recovered even when the slot pool
 * is full — they ARE the slot, just stranded.
 */
it('re-dispatches DispatchPositionJob for an orphan position in new status with no live step', function (): void {
    $orphan = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbol->id,
        'status' => 'new',
        'direction' => 'LONG',
    ]);

    expect(stepsForPosition($orphan))->toHaveCount(0);

    $this->artisan('kraite:cron-create-positions')->assertSuccessful();

    $recoveredSteps = Steps::usingPrefix('trading', fn () => Step::query()
        ->whereIn('class', [BaseDispatchPositionJob::class, BinanceDispatchPositionJob::class])
        ->whereJsonContains('arguments->positionId', $orphan->id)
        ->get());

    expect($recoveredSteps)->toHaveCount(
        1,
        'Orphan position with status=new and no live DispatchPositionJob step must be self-healed '
        .'by CreatePositionsCommand on the next tick. Without the recovery path, sweep casualties '
        .'(stale Pending wipes that took follow-up steps with them) leave positions stranded forever '
        .'until manual operator intervention.'
    );

    expect($recoveredSteps->first()->state)->toBeInstanceOf(
        Pending::class,
        'Recovered step must land in Pending so the next dispatcher tick promotes it.'
    );

    // Recovery dispatch must populate relatable_type / relatable_id at
    // create time so the next tick's orphan lookup can resolve via the
    // indexed `idx_p_steps_rel_state_idx` covering tuple instead of the
    // unindexed JSON-path scan. Drives the scaling fix from
    // 2026-05-07: at 200+ accounts the JSON predicate's wall-clock
    // grew linearly with steps-table size; the indexed predicate is
    // O(log n).
    expect($recoveredSteps->first()->relatable_type)->toBe(
        Position::class,
        'Recovered step must carry relatable_type=Position so the orphan lookup hits the index.'
    );
    expect((int) $recoveredSteps->first()->relatable_id)->toBe(
        $orphan->id,
        'Recovered step must carry relatable_id matching the orphaned position.'
    );
});

it('does not recover an orphan position after its exchange is deactivated', function (): void {
    $orphan = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbol->id,
        'status' => 'new',
        'direction' => 'LONG',
    ]);

    expect(stepsForPosition($orphan))->toHaveCount(0)
        ->and($this->account->apiSystem->is_active)->toBeTrue();

    $this->account->apiSystem->update(['is_active' => false]);

    $this->artisan('kraite:cron-create-positions')->assertSuccessful();

    expect(stepsForPosition($orphan))->toHaveCount(0)
        ->and($orphan->refresh()->status)->toBe('new');
});

it('detects an existing live step via the indexed relatable_type / relatable_id columns', function (): void {
    // Pin the new indexed code path: a live DispatchPositionJob step
    // with relatable_type+relatable_id set must be recognised as
    // "already dispatched" — the recovery loop must NOT create a
    // duplicate even if the JSON-path predicate doesn't match. This
    // is the steady-state shape after every step picks up its
    // relatable via the framework's HandlesStepLifecycle hook.
    $position = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbol->id,
        'status' => 'new',
        'direction' => 'LONG',
    ]);

    Steps::usingPrefix('trading', fn () => Step::create([
        'class' => BinanceDispatchPositionJob::class,
        'queue' => 'positions',
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'arguments' => ['positionId' => $position->id],
    ]));

    $this->artisan('kraite:cron-create-positions')->assertSuccessful();

    $stepCount = Steps::usingPrefix('trading', fn (): int => Step::query()
        ->whereIn('class', [BaseDispatchPositionJob::class, BinanceDispatchPositionJob::class])
        ->where('relatable_type', Position::class)
        ->where('relatable_id', $position->id)
        ->count());

    expect($stepCount)->toBe(
        1,
        'Recovery must dedupe via the indexed relatable lookup; no duplicate dispatch when a live '
        .'step already exists with relatable_type=Position + relatable_id matching.'
    );
});

it('does not let an argument-only legacy row hide an orphan after the production cutover audit', function (): void {
    $position = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbol->id,
        'status' => 'new',
        'direction' => 'LONG',
    ]);

    // Production was audited read-only before removing the JSON fallback:
    // no in-progress position workflow lacked relatable_type/relatable_id.
    // An argument-only row is therefore legacy debris, not a live workflow
    // ownership signal. Recovery must create one indexed replacement.
    Steps::usingPrefix('trading', fn () => Step::create([
        'class' => BinanceDispatchPositionJob::class,
        'queue' => 'positions',
        'arguments' => ['positionId' => $position->id],
    ]));

    $this->artisan('kraite:cron-create-positions')->assertSuccessful();

    $steps = Steps::usingPrefix('trading', fn () => Step::query()
        ->whereIn('class', [BaseDispatchPositionJob::class, BinanceDispatchPositionJob::class])
        ->whereJsonContains('arguments->positionId', $position->id)
        ->get());

    expect($steps)->toHaveCount(2)
        ->and($steps->where('relatable_type', Position::class))->toHaveCount(1)
        ->and((int) $steps->firstWhere('relatable_type', Position::class)->relatable_id)->toBe($position->id);
});

it('treats terminal-state DispatchPositionJob steps as "no live step" for recovery purposes', function (): void {
    $position = Position::factory()->create([
        'account_id' => $this->account->id,
        'exchange_symbol_id' => $this->exchangeSymbol->id,
        'status' => 'new',
        'direction' => 'LONG',
    ]);

    // A previous dispatch attempt that already concluded in a terminal
    // state (Completed / Cancelled / Failed). The position is still
    // 'new' which means the previous workflow must have been cancelled
    // mid-flight. Recovery must treat this as orphan and re-dispatch.
    $oldStep = Steps::usingPrefix('trading', fn () => Step::create([
        'class' => BinanceDispatchPositionJob::class,
        'queue' => 'positions',
        'arguments' => ['positionId' => $position->id],
    ]));
    Steps::usingPrefix('trading', function () use ($oldStep): void {
        Step::withoutEvents(function () use ($oldStep): void {
            Step::where('id', $oldStep->id)->update(['state' => Cancelled::class]);
        });
    });

    $this->artisan('kraite:cron-create-positions')->assertSuccessful();

    $liveSteps = Steps::usingPrefix('trading', fn () => Step::query()
        ->whereIn('class', [BaseDispatchPositionJob::class, BinanceDispatchPositionJob::class])
        ->whereJsonContains('arguments->positionId', $position->id)
        ->where('state', '!=', Cancelled::class)
        ->where('state', '!=', Completed::class)
        ->get());

    expect($liveSteps)->toHaveCount(
        1,
        'Recovery must consider only NON-terminal DispatchPositionJob steps when deciding whether '
        .'a position is orphaned. A position whose only step ended Cancelled is still stranded.'
    );
});

it('does not dispatch account preparation when both directional BSCS caps are already full', function (): void {
    $this->account->update([
        'total_positions_long' => 6,
        'total_positions_short' => 6,
    ]);
    KraiteModel::findOrFail(1)->updateSaving([
        'allow_opening_positions' => true,
        'can_trade' => true,
        'bscs_score' => 50,
        'bscs_band' => 'elevated',
        'bscs_synced_at' => now(),
        'bscs_cooldown_until' => null,
    ]);
    Position::factory()->count(4)->create([
        'account_id' => $this->account->id,
        'status' => 'active',
        'direction' => 'LONG',
        'total_limit_orders' => 4,
    ]);
    Position::factory()->count(4)->create([
        'account_id' => $this->account->id,
        'status' => 'active',
        'direction' => 'SHORT',
        'total_limit_orders' => 4,
    ]);

    $engine = Kraite::withAccount($this->account->refresh());
    expect($engine->canOpenPositions())->toBeTrue()
        ->and($engine->canOpenLongs())->toBeTrue()
        ->and($engine->canOpenShorts())->toBeTrue()
        ->and($this->account->apiSystem->inCooldown())->toBeFalse();

    $method = new ReflectionMethod(CreatePositionsCommand::class, 'attemptOpeningPositionsForAccount');
    Steps::usingPrefix('trading', fn () => $method->invoke(
        app(CreatePositionsCommand::class),
        $this->account->refresh(),
    ));

    $steps = Steps::usingPrefix('trading', fn () => Step::query()
        ->where('class', PreparePositionsOpeningJob::class)
        ->where('relatable_type', $this->account->getMorphClass())
        ->where('relatable_id', $this->account->id)
        ->get());

    expect($steps)->toHaveCount(0);
});

function stepsForPosition(Position $position): Illuminate\Database\Eloquent\Collection
{
    return Steps::usingPrefix('trading', fn () => Step::query()
        ->whereIn('class', [BaseDispatchPositionJob::class, BinanceDispatchPositionJob::class])
        ->whereJsonContains('arguments->positionId', $position->id)
        ->get());
}
