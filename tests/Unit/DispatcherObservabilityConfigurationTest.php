<?php

declare(strict_types=1);

use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;

it('logs slow dispatcher ticks and retains only diagnostically slow tick rows', function (): void {
    expect(config('step-dispatcher.dispatch.on_slow_dispatch'))->not->toBeInstanceOf(Closure::class)
        ->and(StepsDispatcher::getSlowDispatchCallable())->toBeCallable();

    $recordTickWhen = StepsDispatcher::getRecordTickWhenCallable();
    expect($recordTickWhen)->toBeCallable();

    $fast = new StepsDispatcherTicks(['duration' => 4_999]);
    $slow = new StepsDispatcherTicks(['duration' => 5_001]);

    expect($recordTickWhen($fast))->toBeFalse()
        ->and($recordTickWhen($slow))->toBeTrue();
});
