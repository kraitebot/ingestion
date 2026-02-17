<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Engine;
use Throwable;

/**
 * Mock exception handler for testing BaseApiableJob.
 * Behavior is controlled via step arguments instead of parsing real HTTP responses.
 */
final class TestExceptionHandler extends BaseExceptionHandler
{
    private array $args;

    public function __construct(array $args)
    {
        $this->args = $args;
        $this->backoffSeconds = $args['backoff_seconds'] ?? 10;
    }

    public function getApiSystem(): string
    {
        return $this->args['api_system'] ?? 'test';
    }

    public function isRecvWindowMismatch(Throwable $e): bool
    {
        return $this->args['handler_is_recv_window_mismatch'] ?? false;
    }

    public function isRateLimited(Throwable $e): bool
    {
        // Check args flag first
        if ($this->args['handler_is_rate_limited'] ?? false) {
            return true;
        }

        // Also check if it's actually a 429 response
        if ($e instanceof RequestException && $e->hasResponse()) {
            return $e->getResponse()->getStatusCode() === 429;
        }

        return false;
    }

    public function isForbidden(Throwable $e): bool
    {
        return $this->args['handler_is_forbidden'] ?? false;
    }

    public function ignoreException(Throwable $e): bool
    {
        return $this->args['handler_ignore_exception'] ?? false;
    }

    public function rateLimitUntil(RequestException $e): Carbon
    {
        $seconds = $this->args['rate_limit_seconds'] ?? 60;

        return now()->addSeconds($seconds);
    }

    public function isSafeToMakeRequest(): bool
    {
        return $this->args['is_safe_to_request'] ?? true;
    }

    public function forbid(): void
    {
        // Create ForbiddenHostname record
        if ($this->account) {
            ForbiddenHostname::create([
                'account_id' => $this->account->id,
                'api_system_id' => $this->account->apiSystem->id,
                'ip_address' => Engine::ip(),
            ]);
        }
    }
}
