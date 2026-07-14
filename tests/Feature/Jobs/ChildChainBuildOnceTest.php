<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Abstracts\BaseQueueableJob;
use StepDispatcher\Models\Step;

/**
 * Pins BaseQueueableJob::buildChildChainOnce — the atomic + idempotent
 * child-chain builder used by trading orchestrators (DispatchPosition,
 * CancelPosition, ApplyWap). Review finding: a retried orchestrator
 * re-ran compute() against its persisted child_block_uuid and inserted
 * children 1..N-1 a second time at the same indexes (no unique
 * constraint), making duplicate exchange-facing steps dispatchable.
 * The helper's two layers under test:
 *   1. transaction — a mid-build failure persists ZERO children, so a
 *      retry rebuilds from scratch instead of appending to a half-chain;
 *   2. populated-block guard — a rerun against a fully-built block is a
 *      no-op (returns false, creates nothing).
 */
uses(RefreshDatabase::class)->group('feature', 'step-dispatcher');

function makeChainOrchestrator(int $childCount, int $throwAtIndex = 0, ?Step $step = null)
{
    $job = new class extends BaseQueueableJob
    {
        public int $childCount = 0;

        public int $throwAtIndex = 0;

        public function compute()
        {
            $built = $this->buildChildChainOnce(function (string $blockUuid): void {
                for ($i = 1; $i <= $this->childCount; $i++) {
                    Step::create([
                        'class' => 'Tests\\Fakes\\FakeChildStep',
                        'queue' => 'default',
                        'block_uuid' => $blockUuid,
                        'index' => $i,
                    ]);

                    if ($i === $this->throwAtIndex) {
                        throw new RuntimeException('transient database error mid-build');
                    }
                }
            });

            return ['built' => $built];
        }
    };

    $job->childCount = $childCount;
    $job->throwAtIndex = $throwAtIndex;
    $job->step = $step ?? Step::create([
        'class' => 'Tests\\Fakes\\FakeOrchestratorStep',
        'queue' => 'default',
        'block_uuid' => (string) Illuminate\Support\Str::uuid(),
        'index' => 1,
    ]);

    return $job;
}

it('persists zero children when the chain build fails mid-way', function (): void {
    $job = makeChainOrchestrator(childCount: 3, throwAtIndex: 2);

    expect(fn () => $job->compute())->toThrow(RuntimeException::class);

    $job->step->refresh();

    expect($job->step->child_block_uuid)->toBeNull();
});

it('serializes two stale parent instances onto one child block', function (): void {
    $parent = Step::create([
        'class' => 'Tests\\Fakes\\FakeOrchestratorStep',
        'queue' => 'default',
        'block_uuid' => (string) Illuminate\Support\Str::uuid(),
        'index' => 1,
    ]);

    $first = makeChainOrchestrator(2, step: Step::findOrFail($parent->id));
    $staleSecond = makeChainOrchestrator(2, step: Step::findOrFail($parent->id));

    expect($first->compute()['built'])->toBeTrue();
    $firstBlockUuid = $parent->fresh()->child_block_uuid;

    expect($staleSecond->compute()['built'])->toBeFalse()
        ->and($parent->fresh()->child_block_uuid)->toBe($firstBlockUuid)
        ->and(Step::where('block_uuid', $firstBlockUuid)->count())->toBe(2);
});

it('rebuilds a full chain on retry after a rolled-back build', function (): void {
    $job = makeChainOrchestrator(childCount: 3, throwAtIndex: 2);

    expect(fn () => $job->compute())->toThrow(RuntimeException::class);

    // Retry: same step, no failure this time (mirrors a transient error clearing).
    $job->throwAtIndex = 0;
    $result = $job->compute();

    $children = Step::where('block_uuid', $job->step->fresh()->child_block_uuid)->get();

    expect($result['built'])->toBeTrue()
        ->and($children)->toHaveCount(3)
        ->and($children->pluck('index')->sort()->values()->all())->toBe([1, 2, 3]);
});

it('no-ops instead of duplicating children when rerun against a built block', function (): void {
    $job = makeChainOrchestrator(childCount: 3);

    expect($job->compute()['built'])->toBeTrue();

    // Rerun — the settled-tree recover-stale scenario. Must not append.
    $rerun = $job->compute();

    expect($rerun['built'])->toBeFalse()
        ->and(Step::where('block_uuid', $job->step->fresh()->child_block_uuid)->count())->toBe(3);
});
