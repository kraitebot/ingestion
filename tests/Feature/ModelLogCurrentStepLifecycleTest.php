<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Kraite\Core\Models\ModelLog;
use StepDispatcher\Models\Step;
use Tests\Support\TestQueueableJob;

beforeEach(function (): void {
    ModelLog::setCurrentStep(null);
});

function makeStepForCurrentStepTest(array $extraArgs = []): Step
{
    return Step::create([
        'class' => TestQueueableJob::class,
        'arguments' => array_merge(['custom_result' => ['ok' => true]], $extraArgs),
        'queue' => 'default',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);
}

it('sets currentStep during handle() and clears it after the job object is destroyed', function (): void {
    $step = makeStepForCurrentStepTest();

    expect(ModelLog::currentStep())->toBeNull();

    $job = new TestQueueableJob;
    $job->step = $step;
    $job->handle();

    expect(ModelLog::currentStep())->not->toBeNull();
    expect(ModelLog::currentStep()->getKey())->toBe($step->id);

    // Dropping every reference triggers the destructor, which performs
    // the belt-and-suspenders clear for paths where Queue::after does
    // not fire (synchronous / test execution).
    unset($job);
    gc_collect_cycles();

    expect(ModelLog::currentStep())->toBeNull();
});

it('clears currentStep after an exception exits handle()', function (): void {
    $step = makeStepForCurrentStepTest(['throw_exception' => true, 'exception_message' => 'boom']);

    $job = new TestQueueableJob;
    $job->step = $step;
    $job->handle();

    unset($job);
    gc_collect_cycles();

    expect(ModelLog::currentStep())->toBeNull();
});

it('clears currentStep after a stopJob() exit path', function (): void {
    $step = makeStepForCurrentStepTest(['stop' => true]);

    $job = new TestQueueableJob;
    $job->step = $step;
    $job->handle();

    unset($job);
    gc_collect_cycles();

    expect(ModelLog::currentStep())->toBeNull();
});

it('destructor does not stomp a different job\'s registered step', function (): void {
    $stepA = makeStepForCurrentStepTest();
    $stepB = makeStepForCurrentStepTest();

    $jobA = new TestQueueableJob;
    $jobA->step = $stepA;

    // Register stepB as the "currently executing" step — i.e. a different
    // job owns the context. Destroying jobA must NOT clear it.
    ModelLog::setCurrentStep($stepB);

    unset($jobA);
    gc_collect_cycles();

    expect(ModelLog::currentStep())->not->toBeNull();
    expect(ModelLog::currentStep()->getKey())->toBe($stepB->id);
});

it('Queue::after listener clears currentStep for real queued jobs', function (): void {
    // Seed a non-null context and simulate the queue-level JobProcessed event
    // that the CoreServiceProvider::boot() listener is registered for. Any
    // other queued job in the same worker — including non-step jobs — fires
    // this event and the listener must always clear.
    $step = makeStepForCurrentStepTest();
    ModelLog::setCurrentStep($step);

    expect(ModelLog::currentStep())->not->toBeNull();

    Event::dispatch(new JobProcessed(
        connectionName: 'sync',
        job: new class
        {
            public function resolveName(): string
            {
                return 'dummy';
            }
        }
    ));

    expect(ModelLog::currentStep())->toBeNull();
});
