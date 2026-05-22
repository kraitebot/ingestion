<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\PurgePositionTrailJob;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * Pin the PositionObserver janitor contract.
 *
 *   - `closed` transition → fires PurgePositionTrailJob (clean exit,
 *     trail reclaimable).
 *   - `cancelled` / `failed` transitions → DO NOT fire (operator needs
 *     the diagnostic trail to reconcile a non-happy-path outcome).
 *   - Updates that do not flip status → DO NOT fire (avoid spam).
 *   - The dispatched step lives under the `trading` prefix so its
 *     own DELETE FROM trading_steps query targets the right table.
 *     A regression that drops the prefix wrapper sends the purge to
 *     the default `steps` table and the trading_steps trail is never
 *     reclaimed.
 *
 * The PurgePositionTrailJob target is asserted on the step row (no
 * actual purge is run — we are only pinning the dispatch, not the job
 * body).
 */
function purgeTrailStepsForPosition(int $positionId): Illuminate\Support\Collection
{
    return Steps::usingPrefix('trading', function () use ($positionId) {
        return Step::query()
            ->where('class', PurgePositionTrailJob::class)
            ->whereJsonContains('arguments->positionId', $positionId)
            ->get();
    });
}

it('fires PurgePositionTrailJob on the trading prefix when status flips to closed', function (): void {
    $position = Position::factory()->long()->create(['status' => 'closing']);

    $position->update(['status' => 'closed']);

    expect(purgeTrailStepsForPosition($position->id))->toHaveCount(1);
});

it('does NOT fire the purge on cancelled (operator needs the trail)', function (): void {
    $position = Position::factory()->long()->create(['status' => 'cancelling']);

    $position->update(['status' => 'cancelled']);

    expect(purgeTrailStepsForPosition($position->id))->toHaveCount(0);
});

it('does NOT fire the purge on failed (operator needs the trail)', function (): void {
    $position = Position::factory()->long()->create(['status' => 'opening']);

    $position->update(['status' => 'failed']);

    expect(purgeTrailStepsForPosition($position->id))->toHaveCount(0);
});

it('does NOT fire on updates that do not change status', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'active',
        'profit_percentage' => '0.350',
    ]);

    $position->update(['profit_percentage' => '0.500']);

    expect(purgeTrailStepsForPosition($position->id))->toHaveCount(0);
});

it('uuid is auto-populated by the creating() hook on Position::factory->create', function (): void {
    $position = Position::factory()->long()->create();

    expect($position->uuid)->not->toBeNull()
        ->and($position->uuid)->toMatch('/^[0-9a-f-]{36}$/i');
});

it('does NOT fire on flips between non-terminal statuses (active → closing → cancelling)', function (): void {
    $position = Position::factory()->long()->create(['status' => 'active']);

    $position->update(['status' => 'closing']);
    $position->update(['status' => 'cancelling']);

    expect(purgeTrailStepsForPosition($position->id))->toHaveCount(0);
});
