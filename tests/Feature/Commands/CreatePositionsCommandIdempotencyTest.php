<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Lifecycles\Account\PreparePositionsOpeningJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\User;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

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
});

/**
 * `CreatePositionsCommand` invocation idempotency.
 *
 * The 2026-04-25 17:33-17:34 incident produced 12 Failed steps and a
 * realised loss because TWO `PreparePositionsOpeningJob` rows for the
 * same account were created 1s apart and both ran their full
 * Verify/Query/Assign/Dispatch chain in parallel. The two `block_uuid`s
 * meant the step-dispatcher framework saw them as independent
 * workflows — its ordering guarantees apply WITHIN a tree, not across
 * trees rooted at the same domain entity.
 *
 * Plausible triggers, all of which the framework cannot prevent on its
 * own:
 *   - operator manual `kraite:cron-create-positions` during an incident
 *     debug racing with the scheduled tick (the most likely 17:33 cause)
 *   - `schedule:work` lag under CPU pressure batching missed ticks
 *   - a stale `withoutOverlapping` mutex left by a crashed prior run
 *     releasing and letting two pending ticks fire back-to-back
 *
 * Fix at the command entry: skip the dispatch when a non-terminal
 * `PreparePositionsOpeningJob` step already exists for the account.
 * Guarantees ONE opening workflow per account at any time. Defense in
 * depth on top of the v1.5.6 `DispatchPositionSlotsJob` idempotency
 * guard — that one stops the *consequence* (twin DispatchPositionJob
 * blocks); this one stops the *cause* (twin orchestrators).
 */
function liveOpeningStepsForAccount(int $accountId): Illuminate\Database\Eloquent\Collection
{
    return Step::query()
        ->where('class', PreparePositionsOpeningJob::class)
        ->whereJsonContains('arguments->accountId', $accountId)
        ->whereNotIn('state', Step::terminalStepStates())
        ->get();
}

it('does not enqueue a second PreparePositionsOpeningJob when one is already in flight for the account', function (): void {
    // Seed a "first instance" — already in flight, not yet terminal.
    $existing = Step::create([
        'class' => PreparePositionsOpeningJob::class,
        'queue' => 'cronjobs',
        'arguments' => ['accountId' => $this->account->id],
    ]);
    Step::withoutEvents(function () use ($existing) {
        Step::where('id', $existing->id)->update(['state' => Running::class]);
    });

    expect(liveOpeningStepsForAccount($this->account->id))->toHaveCount(1);

    // Run the command — would normally enqueue PreparePositionsOpeningJob.
    // The idempotency guard must short-circuit since one is already live.
    $this->artisan('kraite:cron-create-positions')->assertSuccessful();

    expect(liveOpeningStepsForAccount($this->account->id))->toHaveCount(
        1,
        'CreatePositionsCommand must skip an account that already has a non-terminal '
        .'PreparePositionsOpeningJob step in flight. The 2026-04-25 17:33 cluster '
        .'(twin orchestrators racing → 12 Failed steps + realised loss) was caused '
        .'exactly by the absence of this guard at the command entry.'
    );
});

it('does enqueue PreparePositionsOpeningJob when no in-flight workflow exists for the account', function (): void {
    expect(liveOpeningStepsForAccount($this->account->id))->toHaveCount(0);

    $this->artisan('kraite:cron-create-positions')->assertSuccessful();

    expect(liveOpeningStepsForAccount($this->account->id))->toHaveCount(
        1,
        'Idempotency must skip duplicates, not block first-time dispatches. '
        .'An account with no live workflow should still get its scheduled tick honoured.'
    );

    expect(liveOpeningStepsForAccount($this->account->id)->first()->state)->toBeInstanceOf(
        Pending::class,
        'Fresh dispatch lands in Pending so the next dispatcher tick promotes it.'
    );
});

it('treats only non-terminal PreparePositionsOpeningJob steps as in-flight', function (): void {
    // Existing step that already concluded — irrelevant for idempotency.
    $oldStep = Step::create([
        'class' => PreparePositionsOpeningJob::class,
        'queue' => 'cronjobs',
        'arguments' => ['accountId' => $this->account->id],
    ]);
    foreach ([Completed::class, Cancelled::class] as $terminalState) {
        Step::withoutEvents(function () use ($oldStep, $terminalState) {
            Step::where('id', $oldStep->id)->update(['state' => $terminalState]);
        });
    }

    $this->artisan('kraite:cron-create-positions')->assertSuccessful();

    expect(liveOpeningStepsForAccount($this->account->id))->toHaveCount(
        1,
        'Past concluded workflows must NOT count as in-flight — the next scheduled '
        .'tick must produce a fresh PreparePositionsOpeningJob for the next opening cycle.'
    );
});
