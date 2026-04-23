<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\DispatchPositionSlotsJob;
use Kraite\Core\Jobs\Lifecycles\Account\PreparePositionsOpeningJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\TradeConfiguration;
use StepDispatcher\Models\Step;

/**
 * End-to-end guard on the block-completion fix landed earlier in the session.
 *
 * PreparePositionsOpeningJob used to create the DispatchPositionSlotsJob step
 * with a child_block_uuid that never got populated (DispatchPositionSlotsJob
 * spawns each position in its own isolated block_uuid). That turned the
 * slots-dispatch step into a phantom parent, permanently wedged in Running
 * because its declared child block was empty. The fix drops the
 * child_block_uuid from the step creation so it can self-complete.
 *
 * The test runs PreparePositionsOpeningJob::compute() against a fresh
 * account, then inspects the resulting DispatchPositionSlotsJob step row
 * and asserts child_block_uuid is NULL.
 */
function buildAccountReadyForOpeningWorkflow(): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $tradeConfig = TradeConfiguration::factory()->create();

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'is_active' => true,
        'can_trade' => true,
    ]);
}

it('creates the DispatchPositionSlotsJob step without a child_block_uuid', function (): void {
    $account = buildAccountReadyForOpeningWorkflow();

    // Simulate the parent step that PreparePositionsOpeningJob operates under.
    $parentBlockUuid = (string) Str::uuid();
    $parentChildBlockUuid = (string) Str::uuid();

    $parentStep = Step::create([
        'class' => PreparePositionsOpeningJob::class,
        'arguments' => ['accountId' => $account->id],
        'queue' => 'cronjobs',
        'index' => 1,
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $parentChildBlockUuid,
    ]);

    $job = new PreparePositionsOpeningJob($account->id);
    $job->step = $parentStep;
    $job->compute();

    $slotsStep = Step::query()
        ->where('class', DispatchPositionSlotsJob::class)
        ->whereJsonContains('arguments->accountId', $account->id)
        ->firstOrFail();

    // This is the behaviour we're pinning: the slots-dispatch step must NOT
    // advertise a child_block_uuid. If it does, the StepDispatcher framework
    // treats it as a parent and waits for children in that empty block —
    // which never arrive because DispatchPositionSlotsJob spawns each
    // position's workflow in its own isolated block_uuid.
    expect($slotsStep->child_block_uuid)->toBeNull();

    // Sanity: the slots-dispatch step is still wired into the parent block.
    expect($slotsStep->block_uuid)->toBe($parentChildBlockUuid);
});
