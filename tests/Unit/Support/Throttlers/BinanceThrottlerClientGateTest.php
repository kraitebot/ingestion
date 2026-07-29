<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Support\Throttlers\BinanceThrottler;

uses()->group('unit', 'binance', 'throttler');

const CLIENT_GATE_IP = '127.0.0.1';

const CLIENT_GATE_WEIGHT_LIMIT = 2040;

beforeEach(function (): void {
    Cache::flush();
    seedKraiteServerIpCache();

    config()->set('kraite.throttlers.binance.client_max_sleep_ms', 1000);
    config()->set('kraite.throttlers.binance.safety_threshold', 0.85);
    config()->set('kraite.throttlers.binance.rate_limits', [
        ['type' => 'REQUEST_WEIGHT', 'interval' => '1m', 'limit' => CLIENT_GATE_WEIGHT_LIMIT],
    ]);
});

afterEach(function (): void {
    Cache::flush();
    seedKraiteServerIpCache();
});

it('lets a request through untouched while the weight budget is comfortable', function (): void {
    // 1,700 of an effective 1,734 ceiling — close, but not yet over.
    Cache::put('binance:'.CLIENT_GATE_IP.':weight:1m', 1_700, 60);

    BinanceThrottler::throttleRequest();

    Sleep::assertNeverSlept();
});

it('pauses a request once the weight budget crosses the safety threshold', function (): void {
    // 0.85 x 2,040 = 1,734. At the threshold the window-reset wait is whole
    // seconds, so the configured 1s ceiling is what actually gets slept.
    Cache::put('binance:'.CLIENT_GATE_IP.':weight:1m', 1_734, 60);

    BinanceThrottler::throttleRequest();

    Sleep::assertSequence([
        Sleep::for(1000)->milliseconds(),
    ]);
});

it('caps the pause at the configured ceiling instead of sleeping out the whole window', function (): void {
    // Deliberately far over budget: the raw proximity wait is a window reset
    // of up to 60s, which must never reach a worker holding a live request.
    Cache::put('binance:'.CLIENT_GATE_IP.':weight:1m', 99_999, 60);
    config()->set('kraite.throttlers.binance.client_max_sleep_ms', 250);

    BinanceThrottler::throttleRequest();

    Sleep::assertSequence([
        Sleep::for(250)->milliseconds(),
    ]);
});

it('refuses outright while the exchange is holding an IP ban', function (): void {
    Cache::put('binance:'.CLIENT_GATE_IP.':banned_until', now()->timestamp + 120, 120);

    // Non-notifiable: the ban was already announced when it was recorded, so
    // refusing calls afterwards must not raise a fresh alert each time.
    expect(fn () => BinanceThrottler::throttleRequest())
        ->toThrow(NonNotifiableException::class, 'banned for a further 120s');

    // A ban that runs from two minutes to three days is not something to
    // sleep off inside a worker.
    Sleep::assertNeverSlept();
});

it('proceeds past an expired ban stamp rather than refusing on a stale key', function (): void {
    Cache::put('binance:'.CLIENT_GATE_IP.':banned_until', now()->timestamp - 5, 120);
    Cache::put('binance:'.CLIENT_GATE_IP.':weight:1m', 10, 60);

    BinanceThrottler::throttleRequest();

    Sleep::assertNeverSlept();
});

it('pauses rather than refusing when the ban ledger cannot be read at all', function (): void {
    // The step-level gate fails closed on cache trouble because rescheduling a
    // step is free. This gate holds a live request — refusing here would break
    // callers that succeed today, the listen-key refresh among them.
    $unreachable = Mockery::mock(CacheManager::class);
    $unreachable->shouldReceive('get')->andThrow(new RuntimeException('cache unreachable'));
    $unreachable->shouldReceive('put')->andReturnTrue();
    $unreachable->shouldReceive('flush')->andReturnTrue();
    Cache::swap($unreachable);

    BinanceThrottler::throttleRequest();

    Sleep::assertSequence([
        Sleep::for(1000)->milliseconds(),
    ]);
});
