<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\Throttlers\BinanceThrottler;
use Kraite\Core\Support\Throttlers\BybitThrottler;
use Kraite\Core\Support\Throttlers\KucoinThrottler;
use Kraite\Core\Support\Throttlers\TaapiThrottler;
use Tests\Support\AtomicReservationProbeThrottler;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
    AtomicReservationProbeThrottler::reset();
    TaapiThrottler::reset();
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
    expect((int) Cache::get('atomic-reservation-probe:last_dispatch'))
        ->toBe((int) round(now()->getPreciseTimestamp(3)));

    AtomicReservationProbeThrottler::recordDispatch();

    expect(AtomicReservationProbeThrottler::canDispatch())->toBe(0);
    AtomicReservationProbeThrottler::recordDispatch();

    expect(AtomicReservationProbeThrottler::canDispatch())
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(60000);
});

it('atomically reserves a TAAPI request slot at the HTTP boundary', function (): void {
    $this->travelTo(Carbon::parse('2026-08-01 12:34:56.123'));
    config()->set('kraite.throttlers.taapi.requests_per_window', 2);
    config()->set('kraite.throttlers.taapi.window_seconds', 1);
    config()->set('kraite.throttlers.taapi.min_delay_between_requests_ms', 0);
    config()->set('kraite.throttlers.taapi.safety_threshold', 1.0);

    TaapiThrottler::throttleRequest();

    expect((int) Cache::get('taapi_throttler:last_dispatch'))
        ->toBe((int) round(now()->getPreciseTimestamp(3)) + 500);
    Sleep::assertNeverSlept();
});

it('normalizes numeric Redis-style TAAPI reservations without losing precision', function (): void {
    $this->travelTo(Carbon::parse('2026-08-01 12:34:56.123'));
    config()->set('kraite.throttlers.taapi.requests_per_window', 1000);
    config()->set('kraite.throttlers.taapi.window_seconds', 1);
    config()->set('kraite.throttlers.taapi.min_delay_between_requests_ms', 221);
    $nowMs = (int) round(now()->getPreciseTimestamp(3));
    Cache::put('taapi_throttler:last_dispatch', (string) ($nowMs + 38), 60);

    TaapiThrottler::throttleRequest();

    expect((int) Cache::get('taapi_throttler:last_dispatch'))->toBe($nowMs + 259);
    Sleep::assertSequence([Sleep::for(38)->milliseconds()]);
});

it('preserves a future TAAPI reservation made by another worker', function (): void {
    config()->set('kraite.throttlers.taapi.requests_per_window', 1000);
    config()->set('kraite.throttlers.taapi.window_seconds', 1);
    config()->set('kraite.throttlers.taapi.min_delay_between_requests_ms', 221);
    $nowMs = (int) round(now()->getPreciseTimestamp(3));
    Cache::put('taapi_throttler:last_dispatch', $nowMs + 5000, 60);

    TaapiThrottler::throttleRequest();

    expect((int) Cache::get('taapi_throttler:last_dispatch'))->toBe($nowMs + 5221);
    Sleep::assertSequence([Sleep::for(5000)->milliseconds()]);
});

it('fails closed when a TAAPI request reservation is malformed', function (): void {
    config()->set('kraite.throttlers.taapi.min_delay_between_requests_ms', 221);
    Cache::put('taapi_throttler:last_dispatch', new stdClass, 60);

    expect(fn () => TaapiThrottler::throttleRequest())
        ->toThrow(UnexpectedValueException::class, 'integer timestamp');

    Sleep::assertNeverSlept();
});

it('enforces exchange pre-flight delays from scalar dispatch timestamps', function (
    string $throttler,
    string $prefix,
    string $configKey,
): void {
    seedBinanceThrottleIp();
    $this->travelTo(Carbon::parse('2026-08-01 12:34:56.183'));
    config()->set($configKey, 200);
    $nowMs = (int) round(now()->getPreciseTimestamp(3));
    Cache::put($prefix.':last_dispatch', $nowMs - 183, 60);

    expect($throttler::isSafeToDispatch())->toBe(17);
})->with([
    'Binance' => [BinanceThrottler::class, 'binance_throttler', 'kraite.throttlers.binance.min_delay_ms'],
    'Bybit' => [BybitThrottler::class, 'bybit_throttler', 'kraite.throttlers.bybit.min_delay_ms'],
    'KuCoin' => [KucoinThrottler::class, 'kucoin_throttler', 'kraite.throttlers.kucoin.min_delay_ms'],
]);

it('enforces the full exchange delay when the cached timestamp is in the future', function (
    string $throttler,
    string $prefix,
    string $configKey,
): void {
    seedBinanceThrottleIp();
    config()->set($configKey, 200);
    $nowMs = (int) round(now()->getPreciseTimestamp(3));
    Cache::put($prefix.':last_dispatch', $nowMs + 5000, 60);

    expect($throttler::isSafeToDispatch())->toBe(200);
})->with([
    'Binance' => [BinanceThrottler::class, 'binance_throttler', 'kraite.throttlers.binance.min_delay_ms'],
    'Bybit' => [BybitThrottler::class, 'bybit_throttler', 'kraite.throttlers.bybit.min_delay_ms'],
    'KuCoin' => [KucoinThrottler::class, 'kucoin_throttler', 'kraite.throttlers.kucoin.min_delay_ms'],
]);

it('fails closed when an exchange pre-flight dispatch timestamp is malformed', function (
    string $throttler,
    string $prefix,
    string $configKey,
): void {
    seedBinanceThrottleIp();
    config()->set($configKey, 200);
    Cache::put($prefix.':last_dispatch', new stdClass, 60);

    expect($throttler::isSafeToDispatch())->toBe(30000);
})->with([
    'Binance' => [BinanceThrottler::class, 'binance_throttler', 'kraite.throttlers.binance.min_delay_ms'],
    'Bybit' => [BybitThrottler::class, 'bybit_throttler', 'kraite.throttlers.bybit.min_delay_ms'],
    'KuCoin' => [KucoinThrottler::class, 'kucoin_throttler', 'kraite.throttlers.kucoin.min_delay_ms'],
]);

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
