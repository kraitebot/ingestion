<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Pending;
use Tests\Support\TestQueueableJob;

uses(RefreshDatabase::class)->group('unit', 'step-dispatcher');

/**
 * Fan-out guard: once a block reaches the configured sibling threshold, new
 * children in that block stop inheriting the block's group and round-robin
 * across the dispatch groups instead. Small orchestrator blocks stay below
 * the threshold and keep inheritance intact; batch dispatches (thousands of
 * siblings from an hourly cron) spread across groups and no single group
 * becomes a magnet for cron-driven work.
 */
beforeEach(function () {
    // Seed three groups with a fresh round-robin baseline so getDispatchGroup()
    // has something to rotate through during the fan-out path. Use upsert
    // semantics so the test co-exists with any framework-level seeding.
    foreach (['alpha', 'beta', 'gamma'] as $name) {
        StepsDispatcher::updateOrCreate(
            ['group' => $name],
            ['can_dispatch' => true]
        );
    }
});

function seedBlockSibling(string $blockUuid, string $group, int $index = 1): Step
{
    return Step::factory()->create([
        'block_uuid' => $blockUuid,
        'group' => $group,
        'index' => $index,
        'state' => Pending::class,
        'class' => TestQueueableJob::class,
    ]);
}

it('keeps inheriting while a block is below the fan-out threshold', function () {
    config()->set('step-dispatcher.fanout_threshold', 50);

    $block = (string) Str::uuid();
    seedBlockSibling($block, 'alpha', index: 1);

    // Children 2..49 — all below the threshold. They should inherit alpha.
    $inheritedGroups = [];
    for ($i = 2; $i <= 10; $i++) {
        $child = Step::factory()->create([
            'block_uuid' => $block,
            'index' => $i,
            'state' => Pending::class,
            'class' => TestQueueableJob::class,
        ]);
        $inheritedGroups[] = $child->group;
    }

    expect($inheritedGroups)->each->toBe('alpha');
});

it('round-robins new children once the block crosses the fan-out threshold', function () {
    config()->set('step-dispatcher.fanout_threshold', 5);

    $block = (string) Str::uuid();

    // Seed 5 initial siblings on alpha — reaches the threshold exactly.
    for ($i = 1; $i <= 5; $i++) {
        seedBlockSibling($block, 'alpha', index: $i);
    }

    // Subsequent children must not keep piling onto alpha.
    $fanoutGroups = [];
    for ($i = 6; $i <= 15; $i++) {
        $child = Step::factory()->create([
            'block_uuid' => $block,
            'index' => $i,
            'state' => Pending::class,
            'class' => TestQueueableJob::class,
        ]);
        $fanoutGroups[] = $child->group;
    }

    // At least one non-alpha group must appear — that's the whole point.
    $distinctGroups = array_unique($fanoutGroups);
    expect(count($distinctGroups))->toBeGreaterThan(1)
        ->and($distinctGroups)->toContain('beta');
});

it('disables fan-out when the threshold is set to 0', function () {
    config()->set('step-dispatcher.fanout_threshold', 0);

    $block = (string) Str::uuid();
    for ($i = 1; $i <= 5; $i++) {
        seedBlockSibling($block, 'alpha', index: $i);
    }

    // Even at 100 siblings we should keep inheriting alpha — pure legacy semantics.
    $groups = [];
    for ($i = 6; $i <= 20; $i++) {
        $child = Step::factory()->create([
            'block_uuid' => $block,
            'index' => $i,
            'state' => Pending::class,
            'class' => TestQueueableJob::class,
        ]);
        $groups[] = $child->group;
    }

    expect($groups)->each->toBe('alpha');
});

it('fans out a realistic batch dispatch across multiple groups', function () {
    // Mirrors a cron like fetch-klines that spawns hundreds of siblings on
    // one block. With threshold=50, the first 50 inherit (original behaviour)
    // and the remaining 550 spread across the 3 seeded groups.
    config()->set('step-dispatcher.fanout_threshold', 50);

    $block = (string) Str::uuid();
    seedBlockSibling($block, 'alpha', index: 1);

    for ($i = 2; $i <= 600; $i++) {
        Step::factory()->create([
            'block_uuid' => $block,
            'index' => $i,
            'state' => Pending::class,
            'class' => TestQueueableJob::class,
        ]);
    }

    $distribution = Step::where('block_uuid', $block)
        ->selectRaw('`group`, COUNT(*) as total')
        ->groupBy('group')
        ->pluck('total', 'group')
        ->all();

    // Alpha keeps the first 50 (inheritance) plus its share of the round-robin.
    // No single group should hold the entire batch — the whole point is that
    // the 550 fanned-out children spread across every available dispatcher
    // group rather than piling on the inherited one.
    $totalInBlock = array_sum($distribution);
    $alphaShare = $distribution['alpha'] / $totalInBlock;

    expect(count($distribution))->toBeGreaterThanOrEqual(3)
        ->and($distribution['alpha'])->toBeGreaterThan(50)
        ->and($alphaShare)->toBeLessThan(0.5);
});

it('does not fan out steps without a block_uuid', function () {
    config()->set('step-dispatcher.fanout_threshold', 50);

    // No block_uuid → observer generates one per step → each is a single-sibling
    // block → threshold never triggers. Each step still gets a group assigned
    // via the plain round-robin path.
    $groups = [];
    for ($i = 0; $i < 10; $i++) {
        $step = Step::factory()->create([
            'state' => Pending::class,
            'class' => TestQueueableJob::class,
        ]);
        $groups[] = $step->group;
    }

    expect($groups)->each->not->toBeNull();
});
