<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\UpdatePositionStatusJob;
use Kraite\Core\Jobs\Lifecycles\Position\CancelPositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;

/**
 * F5 regression (code-review 02-P1/03-P1): the opening chain's
 * resolve-exception path is CancelPositionJob, and opening steps 1-2
 * fail while the position is still 'new' (status only becomes 'opening'
 * at PreparePositionData). The cancel chain's first status step
 * previously omitted 'new' from onlyFromStatus, so it no-opped, the
 * final cancelled-from-cancelling step refused, and the position stayed
 * 'new' — where DispatchPositionSlots re-selected it every cron cycle
 * into an infinite open-fail-"cancel" loop.
 */
function makeNewPositionForCancelClaimTest(): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'status' => 'new',
    ]);
}

function runStatusStep(Position $position, string $status, string|array|null $onlyFromStatus): void
{
    $job = new UpdatePositionStatusJob($position->id, $status, null, $onlyFromStatus);
    $job->step = Step::create([
        'class' => UpdatePositionStatusJob::class,
        'queue' => 'positions',
        'block_uuid' => (string) Illuminate\Support\Str::uuid(),
        'index' => 1,
    ]);
    $job->compute();
}

it('buries a new position through cancelling to cancelled', function (): void {
    $position = makeNewPositionForCancelClaimTest();

    // Step 1 of the cancel chain — must claim 'new'.
    runStatusStep($position, 'cancelling', ['new', 'active', 'syncing', 'opening', 'waping']);
    expect($position->fresh()->status)->toBe('cancelling');

    // Final step — the pre-existing cancelling → cancelled gate completes the burial.
    runStatusStep($position, 'cancelled', ['cancelling']);
    expect($position->fresh()->status)->toBe('cancelled');
});

it('wires the new-claimable guard into the cancel orchestrator', function (): void {
    $source = file_get_contents((string) (new ReflectionClass(CancelPositionJob::class))->getFileName());

    expect($source)->toContain("withOnlyFromStatus(['new', 'active', 'syncing', 'opening', 'waping'])");
});

it('still refuses to clobber terminal statuses from a stale cancel step', function (): void {
    $position = makeNewPositionForCancelClaimTest();
    $position->update(['status' => 'closed']);

    runStatusStep($position, 'cancelling', ['new', 'active', 'syncing', 'opening', 'waping']);

    expect($position->fresh()->status)->toBe('closed');
});
