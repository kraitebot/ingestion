<?php

declare(strict_types=1);

namespace Tests\Support;

use Kraite\Core\Abstracts\BaseApiThrottler;

/**
 * Minimal throttler with a two-request window and atomic reservation on,
 * so tests can prove `canDispatch()` reserves the budget itself and
 * `recordDispatch()` does not double-count on top of it.
 *
 * Used by `tests/Unit/BinanceThrottlerResilienceTest`.
 */
final class AtomicReservationProbeThrottler extends BaseApiThrottler
{
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => 2,
            'window_seconds' => 60,
            'atomic_reservation' => true,
            'cache_failure_backoff_ms' => 30000,
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'atomic-reservation-probe';
    }
}
