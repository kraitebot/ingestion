<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Abstracts\BaseApiThrottler;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\Throttlers\BinanceThrottler;

uses(RefreshDatabase::class);

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

beforeEach(function (): void {
    $this->freezeTime();
    AtomicReservationProbeThrottler::reset();
});

function seedBinanceThrottleIp(): string
{
    $hostname = 'binance-throttle-test';
    $ip = '203.0.113.77';
    config()->set('kraite.fleet_metrics.hostname', $hostname);
    Server::query()->create([
        'hostname' => $hostname,
        'ip_address' => $ip,
        'is_apiable' => true,
        'needs_whitelisting' => true,
        'type' => 'ingestion',
    ]);

    return $ip;
}

it('atomically reserves the configured request budget without recordDispatch double-counting', function (): void {
    expect(AtomicReservationProbeThrottler::canDispatch())->toBe(0);
    AtomicReservationProbeThrottler::recordDispatch();

    expect(AtomicReservationProbeThrottler::canDispatch())->toBe(0);
    AtomicReservationProbeThrottler::recordDispatch();

    expect(AtomicReservationProbeThrottler::canDispatch())
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(60000);
});

it('backs off when the ban cache cannot prove Binance is safe', function (): void {
    seedBinanceThrottleIp();
    config()->set('kraite.throttlers.binance.min_delay_ms', 0);
    config()->set('kraite.throttlers.binance.cache_failure_backoff_ms', 30000);

    Cache::shouldReceive('get')
        ->twice()
        ->andThrow(new RuntimeException('redis unavailable'));

    expect(BinanceThrottler::isSafeToDispatch())->toBe(30000);
});

it('waits only for the remainder of the active Binance interval', function (): void {
    $this->travelTo(Carbon::parse('2026-07-25 12:34:57'));

    $method = (new ReflectionClass(BinanceThrottler::class))
        ->getMethod('calculateWindowResetTime');

    expect($method->invoke(null, '1m'))->toBe(3)
        ->and($method->invoke(null, '10s'))->toBe(3);
});

it('stops at the configured reduced Binance profile without applying a second margin', function (): void {
    expect(config('kraite.throttlers.binance.safety_threshold'))->toBe(1.0)
        ->and(config('kraite.throttlers.binance.requests_per_window'))->toBe(2040)
        ->and(config('kraite.throttlers.taapi.safety_threshold'))->toBe(1.0)
        ->and(file_get_contents(base_path('.env.example')))
        ->toContain('BINANCE_THROTTLER_SAFETY_THRESHOLD=1.0');
});

it('throttles when a response-header ledger reaches the configured boundary exactly', function (): void {
    $ip = seedBinanceThrottleIp();
    config()->set('kraite.throttlers.binance.safety_threshold', 1.0);
    config()->set('kraite.throttlers.binance.rate_limits', [[
        'type' => 'REQUEST_WEIGHT',
        'interval' => '1m',
        'limit' => 100,
    ]]);
    Cache::put("binance:{$ip}:weight:1m", 100, 60);

    $method = (new ReflectionClass(BinanceThrottler::class))
        ->getMethod('checkRateLimitProximity');

    expect($method->invoke(null))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(60000);
});
