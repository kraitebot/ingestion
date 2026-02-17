<?php

declare(strict_types=1);

namespace Tests\Support;

use Exception;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Exceptions\NonNotifiableException;
use StepDispatcher\Exceptions\JustEndException;
use StepDispatcher\Exceptions\JustResolveException;
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
        if ($args['stop'] ?? false) {
            $this->stopJob();

            return null;
        }

        if ($args['skip'] ?? false) {
            $this->skipJob();

            return null;
        }

        if ($args['fail'] ?? false) {
            $this->step->state->transitionTo(\StepDispatcher\States\Failed::class);
            $this->stepStatusUpdated = true;

            return null;
        }

        if ($args['retry'] ?? false) {
            $this->retryJob();

            return null;
        }

        if ($args['retry_for_confirmation'] ?? false) {
            $this->retryForConfirmation();

            return null;
        }

        if ($args['reschedule_without_retry'] ?? false) {
            // Only reschedule once - check if already rescheduled via response marker
            $response = $this->step->response ?? [];
            if (! isset($response['rescheduled_once'])) {
                // Mark that we've rescheduled to prevent infinite loop
                $this->step->update(['response' => ['rescheduled_once' => true]]);

                // Get seconds from args and create Carbon instance
                $seconds = $args['reschedule_seconds'] ?? 0;
                $dispatchAfter = $seconds > 0 ? now()->addSeconds($seconds) : null;

                $this->rescheduleWithoutRetry($dispatchAfter);

                return null;
            }
        }

        if ($args['report_and_fail'] ?? false) {
            $exception = new Exception($args['exception_message'] ?? 'Test exception for reportAndFail');
            $this->reportAndFail($exception);

            return null;
        }

        // Throw exceptions
        if ($args['throw_exception'] ?? false) {
            $message = $args['exception_message'] ?? 'Test exception';
            throw new Exception(is_string($message) ? $message : 'Test exception');
        }

        if ($args['throw_non_notifiable'] ?? false) {
            throw new NonNotifiableException('Non-notifiable test exception');
        }

        if ($args['throw_max_retries'] ?? false) {
            throw new MaxRetriesReachedException('Max retries reached');
        }

        if ($args['throw_just_resolve'] ?? false) {
            throw new JustResolveException('Just resolve exception');
        }

        if ($args['throw_just_end'] ?? false) {
            throw new JustEndException('Just end exception');
        }

        // Store result for verification
        $result = ['success' => true];

        if ($args['custom_result'] ?? null) {
            $result = $args['custom_result'];
        }

        return $result;
    }

    protected function shouldStartOrStop(): bool
    {
        $args = $this->getArgs();
        if (isset($args['should_start_or_stop'])) {
            return (bool) $args['should_start_or_stop'];
        }

        return parent::shouldStartOrStop();
    }

    protected function shouldStartOrSkip(): bool
    {
        $args = $this->getArgs();
        if (isset($args['should_start_or_skip'])) {
            return (bool) $args['should_start_or_skip'];
        }

        return parent::shouldStartOrSkip();
    }

    protected function shouldStartOrFail(): bool
    {
        $args = $this->getArgs();
        if (isset($args['should_start_or_fail'])) {
            return (bool) $args['should_start_or_fail'];
        }

        return parent::shouldStartOrFail();
    }

    protected function shouldStartOrRetry(): bool
    {
        $args = $this->getArgs();
        if (isset($args['should_start_or_retry'])) {
            return (bool) $args['should_start_or_retry'];
        }

        return parent::shouldStartOrRetry();
    }

    protected function doubleCheck(): bool
    {
        $args = $this->getArgs();
        if (isset($args['double_check'])) {
            return (bool) $args['double_check'];
        }

        return true; // Default: passes double check
    }

    protected function confirmOrRetry(): bool
    {
        $args = $this->getArgs();
        if (isset($args['confirm_or_retry'])) {
            return (bool) $args['confirm_or_retry'];
        }

        return true; // Default: confirm completion
    }

    protected function retryException(Throwable $e): bool
    {
        $args = $this->getArgs();
        if (isset($args['retry_exception'])) {
            return (bool) $args['retry_exception'];
        }

        return false;
    }

    protected function ignoreException(Throwable $e): bool
    {
        $args = $this->getArgs();
        if (isset($args['ignore_exception'])) {
            return (bool) $args['ignore_exception'];
        }

        return false;
    }

    protected function resolveException(Throwable $e): void
    {
        $args = $this->getArgs();
        if ($args['resolve_exception'] ?? false) {
            $this->step->update(['response' => ['resolved' => true]]);
            $this->step->state->transitionTo(\StepDispatcher\States\Completed::class);
            $this->stepStatusUpdated = true;
        }
    }

    /**
     * Called when max retries is reached, provides diagnostic info for exception message.
     * Purpose: Return array of diagnostic strings explaining why retries failed.
     *
     * @return array<int, string>
     */
    protected function getRetryDiagnostics(): array
    {
        $args = $this->getArgs();
        if (isset($args['retry_diagnostics'])) {
            return is_array($args['retry_diagnostics'])
                ? $args['retry_diagnostics']
                : [$args['retry_diagnostics']];
        }

        return [];
    }

    /**
     * Called during prepareJobExecution() to associate a model with the step.
     * Purpose: Return a model instance (Account, ExchangeSymbol, etc.) to be associated.
     *
     * @return mixed
     */
    protected function relatable()
    {
        $args = $this->getArgs();
        if (isset($args['relatable'])) {
            return $args['relatable'];
        }

        return null;
    }

    /**
     * Called during retryJob() and rescheduleWithoutRetry().
     * Purpose: Determine if step should be escalated to high priority.
     * Default: true if retries >= retries/2.
     */
    protected function shouldChangeToHighPriority(): bool
    {
        $args = $this->getArgs();
        if (isset($args['should_change_to_high_priority'])) {
            return (bool) $args['should_change_to_high_priority'];
        }

        return parent::shouldChangeToHighPriority();
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
}
