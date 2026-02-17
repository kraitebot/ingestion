<?php

declare(strict_types=1);

namespace Tests\Support;

use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Throwable;

/**
 * KuCoin-specific test job for testing KucoinExceptionHandler.
 * Uses the REAL KucoinExceptionHandler to test actual exception routing logic.
 *
 * Usage:
 *   $step = StepTester::createSteps([
 *       ['arguments' => [
 *           'accountId' => $account->id,
 *           'throw_exception_stub' => 'kucoinIpRateLimited',  // Method name on ResponseException
 *       ]],
 *   ], TestKucoinApiableJob::class)[0];
 *
 *   // With arguments:
 *   $step = StepTester::createSteps([
 *       ['arguments' => [
 *           'accountId' => $account->id,
 *           'throw_exception_stub' => 'kucoinRateLimitedWithRetryAfter',
 *           'throw_exception_stub_args' => [10],  // retryAfterSeconds
 *       ]],
 *   ], TestKucoinApiableJob::class)[0];
 */
final class TestKucoinApiableJob extends BaseApiableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function assignExceptionHandler(): void
    {
        // Use REAL KucoinExceptionHandler
        $this->exceptionHandler = BaseExceptionHandler::make('kucoin')
            ->withAccount($this->account);

        $this->track('assignExceptionHandler', [
            'api_system' => $this->exceptionHandler->getApiSystem(),
            'account_id' => $this->account->id,
        ]);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function computeApiable()
    {
        $this->track('computeApiable:start');

        $args = $this->getArgs();

        // Throw exception from ResponseException factory using method name
        // Pass method name as string to avoid serialization issues
        if (isset($args['throw_exception_stub'])) {
            $methodName = $args['throw_exception_stub'];
            $methodArgs = $args['throw_exception_stub_args'] ?? [];

            if (! method_exists(ResponseException::class, $methodName)) {
                throw new Exception("ResponseException::{$methodName}() does not exist");
            }

            /** @var Throwable $exception */
            $exception = ResponseException::$methodName(...$methodArgs);
            $this->track('computeApiable:throwing_exception_stub', [
                'exception_type' => class_basename($exception),
                'method_name' => $methodName,
            ]);
            throw $exception;
        }

        // Throw ConnectException (network issues)
        if ($args['throw_connect_exception'] ?? false) {
            $this->track('computeApiable:throwing_connect_exception');
            throw new ConnectException(
                $args['exception_message'] ?? 'Connection timeout',
                new Request('GET', '/test')
            );
        }

        // Throw generic exception
        if ($args['throw_exception'] ?? false) {
            $this->track('computeApiable:throwing_exception');
            throw new Exception($args['exception_message'] ?? 'Generic error');
        }

        // Success case
        $result = $args['custom_result'] ?? ['success' => true];
        $this->track('computeApiable:success', ['result' => $result]);

        return $result;
    }

    /**
     * Override to track exception routing.
     */
    protected function handleApiException(Throwable $e): void
    {
        $this->track('handleApiException:start', [
            'exception_type' => class_basename($e),
            'exception_message' => $e->getMessage(),
        ]);

        try {
            parent::handleApiException($e);
            $this->track('handleApiException:handled');
        } catch (Throwable $rethrown) {
            $this->track('handleApiException:rethrow', [
                'exception_type' => class_basename($rethrown),
            ]);
            throw $rethrown;
        }
    }

    /**
     * Override BaseQueueableJob hook to track calls.
     */
    protected function ignoreException(Throwable $e): bool
    {
        $args = $this->getArgs();

        // Check if we should ignore based on step arguments
        $shouldIgnore = $args['job_ignore_exception'] ?? false;

        $this->track('ignoreException:called', [
            'exception_type' => class_basename($e),
            'result' => $shouldIgnore,
        ]);

        return $shouldIgnore;
    }

    /**
     * Override BaseQueueableJob hook to track calls.
     */
    protected function retryException(Throwable $e): bool
    {
        $args = $this->getArgs();

        $shouldRetry = $args['job_retry_exception'] ?? false;

        $this->track('retryException:called', [
            'exception_type' => class_basename($e),
            'result' => $shouldRetry,
        ]);

        return $shouldRetry;
    }

    /**
     * Override BaseQueueableJob hook to track calls.
     */
    protected function resolveException(Throwable $e): void
    {
        $this->track('resolveException:called', [
            'exception_type' => class_basename($e),
        ]);

        $args = $this->getArgs();

        if ($args['job_resolve_exception'] ?? false) {
            $this->track('resolveException:resolving');
            $this->step->update(['response' => array_merge($this->step->response ?? [], ['resolved' => true])]);
            $this->step->state->transitionTo(\StepDispatcher\States\Completed::class);
            $this->stepStatusUpdated = true;
        }
    }

    /**
     * Track execution path in step response.
     * Silently skips if step is not yet initialized.
     */
    private function track(string $event, array $data = []): void
    {
        if (! isset($this->step)) {
            return;
        }

        $response = $this->step->response ?? [];
        $response['execution_path'] ??= [];
        $response['execution_path'][] = [
            'event' => $event,
            'data' => $data,
            'timestamp' => microtime(true),
        ];

        $this->step->update(['response' => $response]);
    }

    /**
     * Get step arguments safely.
     * Returns empty array if step is not yet initialized.
     *
     * @return array<string, mixed>
     */
    private function getArgs(): array
    {
        if (! isset($this->step)) {
            return [];
        }

        $arguments = $this->step->arguments;

        /** @var array<string, mixed> $result */
        return is_array($arguments) ? $arguments : [];
    }
}
