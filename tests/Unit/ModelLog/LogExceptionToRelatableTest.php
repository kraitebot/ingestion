<?php

declare(strict_types=1);

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ModelLog;
use StepDispatcher\Models\Step;

beforeEach(function () {
    // Re-enable logging before each test
    ModelLog::enable();
});

it('logs exceptions to relatable model when step fails', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $step = Step::factory()->create();

    // Associate the relatable with the step
    $step->relatable()->associate($exchangeSymbol);
    $step->save();

    // Create a mock job that will throw an exception
    $job = new class($step) extends BaseQueueableJob
    {
        public function __construct(Step $step)
        {
            $this->step = $step;
            $this->retries = 5;
        }

        protected function compute()
        {
            throw new RuntimeException('Test exception for relatable logging');
        }
    };

    // Execute the job - it will catch and handle the exception
    try {
        $job->handle();
    } catch (Throwable $e) {
        // Job should handle the exception internally
    }

    // Verify ModelLog entry was created on the ExchangeSymbol
    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'step_failed')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->relatable_type)->toBe(Step::class);
    expect($log->relatable_id)->toBe($step->id);
    expect($log->metadata['exception_class'])->toBe(RuntimeException::class);
    expect($log->metadata['exception_message'])->toContain('Test exception for relatable logging');
    expect($log->message)->toContain('Test exception for relatable logging');
});

it('does not log to relatable when step has no relatable', function () {
    $step = Step::factory()->create();

    // Create a mock job that will throw an exception (no relatable)
    $job = new class($step) extends BaseQueueableJob
    {
        public function __construct(Step $step)
        {
            $this->step = $step;
            $this->retries = 5;
        }

        protected function compute()
        {
            throw new RuntimeException('Test exception without relatable');
        }
    };

    // Execute the job
    try {
        $job->handle();
    } catch (Throwable $e) {
        // Job should handle the exception internally
    }

    // Verify NO ModelLog entries were created (no relatable to log to)
    $logs = ModelLog::where('event_type', 'step_failed')->get();
    expect($logs->count())->toBe(0);
});

it('does not log to relatable when logging is globally disabled', function () {
    ModelLog::disable();

    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $step = Step::factory()->create();

    // Associate the relatable with the step
    $step->relatable()->associate($exchangeSymbol);
    $step->save();

    // Create a mock job that will throw an exception
    $job = new class($step) extends BaseQueueableJob
    {
        public function __construct(Step $step)
        {
            $this->step = $step;
            $this->retries = 5;
        }

        protected function compute()
        {
            throw new RuntimeException('Test exception with logging disabled');
        }
    };

    // Execute the job
    try {
        $job->handle();
    } catch (Throwable $e) {
        // Job should handle the exception internally
    }

    // Verify NO ModelLog entries were created (logging is disabled)
    $logs = ModelLog::where('event_type', 'step_failed')->get();
    expect($logs->count())->toBe(0);

    // Re-enable for next tests
    ModelLog::enable();
});

it('logs correct exception class and message in metadata', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $step = Step::factory()->create();

    $step->relatable()->associate($exchangeSymbol);
    $step->save();

    // Create a mock job with a specific exception
    $job = new class($step) extends BaseQueueableJob
    {
        public function __construct(Step $step)
        {
            $this->step = $step;
            $this->retries = 5;
        }

        protected function compute()
        {
            throw new InvalidArgumentException('Invalid symbol ID provided');
        }
    };

    try {
        $job->handle();
    } catch (Throwable $e) {
        // Job should handle the exception internally
    }

    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'step_failed')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->metadata['exception_class'])->toBe(InvalidArgumentException::class);
    expect($log->metadata['exception_message'])->toContain('Invalid symbol ID provided');
    expect($log->message)->toContain('Invalid symbol ID provided');
});

it('associates step as relatable in the application log', function () {
    $exchangeSymbol = ExchangeSymbol::factory()->create();
    $step = Step::factory()->create();

    $step->relatable()->associate($exchangeSymbol);
    $step->save();

    $job = new class($step) extends BaseQueueableJob
    {
        public function __construct(Step $step)
        {
            $this->step = $step;
            $this->retries = 5;
        }

        protected function compute()
        {
            throw new Exception('Test for step association');
        }
    };

    try {
        $job->handle();
    } catch (Throwable $e) {
        // Job should handle the exception internally
    }

    $log = ModelLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'step_failed')
        ->first();

    expect($log)->not->toBeNull();

    // Test relatable relationship
    expect($log->relatable)->toBeInstanceOf(Step::class);
    expect($log->relatable->id)->toBe($step->id);

    // Test loggable relationship
    expect($log->loggable)->toBeInstanceOf(ExchangeSymbol::class);
    expect($log->loggable->id)->toBe($exchangeSymbol->id);
});
