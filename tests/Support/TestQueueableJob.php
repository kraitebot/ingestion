<?php

declare(strict_types=1);

namespace Tests\Support;

use Exception;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Exceptions\NonNotifiableException;
use StepDispatcher\Exceptions\MaxRetriesReachedException;
use Throwable;

/**
 * Flexible test job for BaseQueueableJob testing.
 * Configure behavior via step arguments.
 */
final class TestQueueableJob extends BaseQueueableJob
{
    public int $tries = 10;

    public int $retries = 10;

    protected function compute(): mixed
    {
        $args = $this->getArgs();

        // Direct lifecycle actions
        if ($this->getArg($args, 'stop')) {
            $this->stopJob();

            return null;
        }

        if ($this->getArg($args, 'skip')) {
            $this->skipJob();

            return null;
        }

        if ($this->getArg($args, 'fail')) {
            $this->step->state->transitionTo(\StepDispatcher\States\Failed::class);
            $this->stepStatusUpdated = true;

            return null;
        }

        if ($this->getArg($args, 'retry')) {
            $this->retryJob();

            return null;
        }

        if ($this->getArg($args, 'retry_for_confirmation')) {
            $this->retryForConfirmation();

            return null;
        }

        if ($this->getArg($args, 'reschedule_without_retry')) {
            $this->handleRescheduleWithoutRetry($args);

            return null;
        }

        if ($this->getArg($args, 'report_and_fail')) {
            $exception = new Exception($args['exception_message'] ?? 'Test exception for reportAndFail');
            $this->reportAndFail($exception);

            return null;
        }

        // Throw exceptions
        if ($this->getArg($args, 'throw_exception')) {
            $message = $args['exception_message'] ?? 'Test exception';
            throw new Exception($message);
        }

        if ($this->getArg($args, 'throw_non_notifiable')) {
            throw new NonNotifiableException('Non-notifiable test exception');
        }

        if ($this->getArg($args, 'throw_max_retries')) {
            throw new MaxRetriesReachedException('Max retries reached');
        }

        // Store result for verification
        return $args['custom_result'] ?? ['success' => true];
    }

    protected function shouldStartOrStop(): bool
    {
        return $this->getArgBool('should_start_or_stop', parent::shouldStartOrStop());
    }

    protected function shouldStartOrSkip(): bool
    {
        return $this->getArgBool('should_start_or_skip', parent::shouldStartOrSkip());
    }

    protected function shouldStartOrFail(): bool
    {
        return $this->getArgBool('should_start_or_fail', parent::shouldStartOrFail());
    }

    protected function shouldStartOrRetry(): bool
    {
        return $this->getArgBool('should_start_or_retry', parent::shouldStartOrRetry());
    }

    protected function doubleCheck(): bool
    {
        return $this->getArgBool('double_check', true);
    }

    protected function confirmOrRetry(): bool
    {
        return $this->getArgBool('confirm_or_retry', true);
    }

    protected function retryException(Throwable $e): bool
    {
        return $this->getArgBool('retry_exception', false);
    }

    protected function ignoreException(Throwable $e): bool
    {
        return $this->getArgBool('ignore_exception', false);
    }

    protected function resolveException(Throwable $e): void
    {
        if (! $this->getArg($this->getArgs(), 'resolve_exception')) {
            return;
        }

        $this->step->update(['response' => ['resolved' => true]]);
        $this->step->state->transitionTo(\StepDispatcher\States\Completed::class);
        $this->stepStatusUpdated = true;
    }

    /**
     * @return array<int, string>
     */
    protected function getRetryDiagnostics(): array
    {
        $diagnostics = $this->getArgs()['retry_diagnostics'] ?? null;

        if ($diagnostics === null) {
            return [];
        }

        return is_array($diagnostics) ? $diagnostics : [$diagnostics];
    }

    protected function relatable(): mixed
    {
        return $this->getArgs()['relatable'] ?? null;
    }

    protected function shouldChangeToHighPriority(): bool
    {
        return $this->getArgBool('should_change_to_high_priority', parent::shouldChangeToHighPriority());
    }

    private function handleRescheduleWithoutRetry(array $args): void
    {
        $response = $this->step->response ?? [];
        if (isset($response['rescheduled_once'])) {
            return;
        }

        $this->step->update(['response' => ['rescheduled_once' => true]]);

        $seconds = $args['reschedule_seconds'] ?? 0;
        $dispatchAfter = $seconds > 0 ? now()->addSeconds($seconds) : null;

        $this->rescheduleWithoutRetry($dispatchAfter);
    }

    /**
     * @return array<string, mixed>
     */
    private function getArgs(): array
    {
        $arguments = $this->step->arguments;

        /** @var array<string, mixed> $result */
        return is_array($arguments) ? $arguments : [];
    }

    /**
     * Get a boolean value from arguments with a default fallback.
     */
    private function getArgBool(string $key, bool $default = false): bool
    {
        return (bool) ($this->getArgs()[$key] ?? $default);
    }

    /**
     * Get a value from arguments, checking if key exists.
     */
    private function getArg(array $args, string $key): mixed
    {
        return $args[$key] ?? false;
    }
}
