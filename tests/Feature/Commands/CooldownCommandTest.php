<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Kraite\Core\Commands\CooldownCommand;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Running;
use StepDispatcher\Support\Steps;

function createActiveCooldownStep(string $class, string $state, ?string $childBlockUuid = null): int
{
    return (int) Step::query()->insertGetId([
        'class' => $class,
        'queue' => 'indicators',
        'state' => $state,
        'block_uuid' => (string) Str::uuid(),
        'child_block_uuid' => $childBlockUuid,
        'workflow_id' => null,
        'index' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('waits only for executable leaves across both step prefixes', function (): void {
    createActiveCooldownStep('DefaultParent', Running::class, (string) Str::uuid());
    createActiveCooldownStep('DefaultLeaf', Dispatched::class);

    Steps::usingPrefix('trading', function (): void {
        createActiveCooldownStep('TradingParent', Running::class, (string) Str::uuid());
        createActiveCooldownStep('TradingLeaf', Running::class);
    });

    $command = app(CooldownCommand::class);
    $method = (new ReflectionClass($command))->getMethod('getActiveStepCount');
    $method->setAccessible(true);

    expect($method->invoke($command))->toBe(2);
});

it('still waits for an active orchestrator before it has populated a child tree', function (): void {
    createActiveCooldownStep('UnbuiltParent', Running::class);

    $command = app(CooldownCommand::class);
    $method = (new ReflectionClass($command))->getMethod('getActiveStepCount');
    $method->setAccessible(true);

    expect($method->invoke($command))->toBe(1);
});

it('counts the physical worker queues before declaring cooldown drained', function (): void {
    config()->set('kraite.horizon.workers', [
        'kraite' => [
            'positions' => ['processes' => 2],
            'web' => ['processes' => 1],
            'kraite' => ['processes' => 1],
        ],
    ]);

    $probedQueues = [];
    $connection = Mockery::mock();
    $connection->shouldReceive('llen')
        ->andReturnUsing(function (string $queue) use (&$probedQueues): int {
            $probedQueues[] = $queue;

            return $queue === 'queues:kraite-positions' ? 3 : 0;
        });
    Redis::shouldReceive('connection')->andReturn($connection);

    $command = app(CooldownCommand::class);
    $method = (new ReflectionClass($command))->getMethod('getQueueDepth');
    $method->setAccessible(true);

    expect($method->invoke($command))
        ->toBe(3)
        ->and($probedQueues)
        ->toContain(
            'queues:positions',
            'queues:kraite-positions',
            'queues:kraite-web',
            'queues:kraite',
        );
});
