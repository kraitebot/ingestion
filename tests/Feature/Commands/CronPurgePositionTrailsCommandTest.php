<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kraite\Core\Jobs\Atomic\Position\PurgePositionTrailJob;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * Pin the deferred-retention sweeper contract
 * (`kraite:cron-purge-position-trails`):
 *
 *   - Closed positions whose `closed_at` aged past the retention window
 *     AND that still carry trail rows → janitor step dispatched (trading
 *     prefix, cronjobs queue — same shape as PositionObserver's
 *     immediate-mode dispatch).
 *   - Closed positions younger than the window → untouched.
 *   - Closed positions with no remaining trail rows → untouched
 *     (idempotence: a swept position never re-dispatches).
 *   - Non-`closed` exits (cancelled / failed) → untouched regardless of
 *     age (forensic trail is kept forever, mirroring the janitor
 *     contract).
 *   - `--hours-to-keep` overrides the configured retention.
 *   - `--dry-run` dispatches nothing.
 */
function janitorStepsFor(int $positionId): Illuminate\Support\Collection
{
    return Steps::usingPrefix('trading', function () use ($positionId) {
        return Step::query()
            ->where('class', PurgePositionTrailJob::class)
            ->whereJsonContains('arguments->positionId', $positionId)
            ->get();
    });
}

function closedPositionWithTrail(string $status, Carbon\CarbonInterface $closedAt): Position
{
    // Retention active so PositionObserver does NOT purge-on-close —
    // the trail row below must survive until the sweeper looks at it.
    config()->set('kraite.positions.trail_retention_hours', 24);

    $position = Position::factory()->long()->create(['status' => 'opening']);

    $position->update(['status' => $status, 'closed_at' => $closedAt]);

    DB::table('model_logs')->insert([
        'loggable_type' => Position::class,
        'loggable_id' => $position->id,
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'event_type' => 'updated',
        'attribute_name' => 'status',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $position;
}

it('dispatches the janitor for closed positions older than the retention window', function (): void {
    $position = closedPositionWithTrail('closed', now()->subHours(25));

    $this->artisan('kraite:cron-purge-position-trails')->assertExitCode(0);

    expect(janitorStepsFor($position->id))->toHaveCount(1);
});

it('keeps positions younger than the retention window untouched', function (): void {
    $position = closedPositionWithTrail('closed', now()->subHours(1));

    $this->artisan('kraite:cron-purge-position-trails')->assertExitCode(0);

    expect(janitorStepsFor($position->id))->toHaveCount(0);
});

it('skips closed positions whose trail was already reclaimed', function (): void {
    config()->set('kraite.positions.trail_retention_hours', 24);

    $position = Position::factory()->long()->create(['status' => 'opening']);
    $position->update(['status' => 'closed', 'closed_at' => now()->subHours(48)]);

    // Simulate the post-janitor state: wipe every breadcrumb the live
    // model-logging produced for this position (the status updates above
    // auto-log into model_logs). With zero trail rows left the sweep
    // must treat the position as already swept.
    DB::table('model_logs')
        ->where('loggable_type', Position::class)
        ->where('loggable_id', $position->id)
        ->delete();

    $this->artisan('kraite:cron-purge-position-trails')->assertExitCode(0);

    expect(janitorStepsFor($position->id))->toHaveCount(0);
});

it('never touches cancelled or failed exits regardless of age', function (): void {
    $cancelled = closedPositionWithTrail('cancelled', now()->subHours(100));
    $failed = closedPositionWithTrail('failed', now()->subHours(100));

    $this->artisan('kraite:cron-purge-position-trails')->assertExitCode(0);

    expect(janitorStepsFor($cancelled->id))->toHaveCount(0)
        ->and(janitorStepsFor($failed->id))->toHaveCount(0);
});

it('honours the --hours-to-keep override', function (): void {
    $position = closedPositionWithTrail('closed', now()->subHours(3));

    // Configured retention (24h) would keep it; the override reclaims it.
    $this->artisan('kraite:cron-purge-position-trails', ['--hours-to-keep' => 2])
        ->assertExitCode(0);

    expect(janitorStepsFor($position->id))->toHaveCount(1);
});

it('dispatches nothing on --dry-run', function (): void {
    $position = closedPositionWithTrail('closed', now()->subHours(25));

    $this->artisan('kraite:cron-purge-position-trails', ['--dry-run' => true])
        ->assertExitCode(0);

    expect(janitorStepsFor($position->id))->toHaveCount(0);
});
