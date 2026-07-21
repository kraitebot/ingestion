<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\ClosePositionAtomicallyJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

function closePositionChildClassesFor(Position $position, bool $positionConfirmedFlat): array
{
    return Steps::usingPrefix('trading', function () use ($position, $positionConfirmedFlat): array {
        $job = new ClosePositionJob($position->id, null, $positionConfirmedFlat);
        $job->step = Step::create([
            'class' => ClosePositionJob::class,
            'queue' => 'positions',
            'relatable_type' => $position->getMorphClass(),
            'relatable_id' => $position->getKey(),
        ]);

        $job->computeApiable();
        $childBlockUuid = $job->step->fresh()->child_block_uuid;

        return Step::query()
            ->where('block_uuid', $childBlockUuid)
            ->pluck('class')
            ->filter()
            ->values()
            ->all();
    });
}

/**
 * Pin the close-cascade entry gate. ClosePositionJob is fired by
 * OrderObserver when a TP or SL reaches FILLED. Between observer-
 * dispatch and worker-pickup, a parallel close (manual cancel, sync
 * race, peer TP/SL fill) may have already moved the position past
 * the opened set — in that case the close cascade has nothing to do
 * and must land in Skipped, NOT Failed.
 *
 * A regression that drops the openedStatuses gate floods Failed with
 * normal lifecycle noise; one that adds terminal statuses to it
 * silently replays close steps against already-closed rows, producing
 * "close called on closed position" errors at every layer.
 */
it('startOrSkip returns true for any opened-set status (the cascade can run)', function (string $opened): void {
    $position = Position::factory()->long()->create(['status' => $opened]);

    expect((new ClosePositionJob($position->id))->startOrSkip())->toBeTrue();
})->with([
    'opening' => ['opening'],
    'waping' => ['waping'],
    'active' => ['active'],
    'new' => ['new'],
    'closing' => ['closing'],
    'cancelling' => ['cancelling'],
    'syncing' => ['syncing'],
]);

it('startOrSkip returns false for terminal/non-opened statuses (close cascade is a no-op)', function (string $nonOpened): void {
    $position = Position::factory()->long()->create(['status' => $nonOpened]);

    expect((new ClosePositionJob($position->id))->startOrSkip())->toBeFalse();
})->with([
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
    'watching' => ['watching'],
]);

it('skips the redundant exchange close after the position was confirmed flat twice', function (): void {
    $position = Position::factory()->long()->create(['status' => 'closing']);

    expect(closePositionChildClassesFor($position, positionConfirmedFlat: true))
        ->not->toContain(ClosePositionAtomicallyJob::class);
});

it('keeps the exchange close step for ordinary close workflows', function (): void {
    $position = Position::factory()->long()->create(['status' => 'closing']);

    expect(closePositionChildClassesFor($position, positionConfirmedFlat: false))
        ->toContain(ClosePositionAtomicallyJob::class);
});
