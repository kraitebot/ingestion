<?php

declare(strict_types=1);

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

    $command = new CooldownCommand;
    $method = (new ReflectionClass($command))->getMethod('getActiveStepCount');
    $method->setAccessible(true);

    expect($method->invoke($command))->toBe(2);
});

it('still waits for an active orchestrator before it has populated a child tree', function (): void {
    createActiveCooldownStep('UnbuiltParent', Running::class);

    $command = new CooldownCommand;
    $method = (new ReflectionClass($command))->getMethod('getActiveStepCount');
    $method->setAccessible(true);

    expect($method->invoke($command))->toBe(1);
});
