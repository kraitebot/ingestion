<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiClients\Websocket\BinanceApiClient;
use Kraite\Core\Support\Apis\Websocket\BinanceApi;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use React\EventLoop\Loop;
use Tests\Support\TestableWebsocketClient;

/**
 * BinanceApiClient's constructor registers a periodic rate-limit-reset
 * timer on the shared ReactPHP loop. ReactPHP auto-runs that loop at
 * script shutdown, so a live periodic timer would block the test process
 * forever after the suite finishes. Stopping the loop after each test
 * defuses the shutdown autorun without affecting assertions.
 */
afterEach(function (): void {
    Loop::stop();
});

/**
 * Pin the sustained-no-data self-exit contract on BaseWebsocketClient.
 *
 * Incident 2026-07-02: a transient network blip at 08:10 wedged the
 * price daemon's ReactPHP DNS resolver on the shared event loop. The
 * daemon's "reconnect forever" contract kept it alive, but the
 * "fresh connector per attempt" mitigation did NOT clear a loop-level
 * UDP wedge — 46,088 reconnects failed over ~4 hours (reconnect
 * attempt 16,632), zero mark-price frames arrived, and BASUSDT went
 * stale. A process restart cleared it in seconds.
 *
 * Only a fresh PROCESS reliably clears the wedge, so strict-data streams
 * (mark-price) now self-exit after a sustained window with no DATA frame
 * and let supervisor respawn a clean process. `lastDataFrameAt` advances
 * ONLY on real data frames (never on connect or ping), so it ages through
 * BOTH failure phases seen that morning: the never-connect DNS phase and
 * the connect-then-silent idle-flap phase.
 *
 * Sparse-data streams (user-data: silent for hours on a quiet account)
 * MUST leave the threshold unset so a legitimately quiet feed never
 * self-exits.
 */
it('does not self-exit when no threshold is configured (sparse-data default)', function (): void {
    $client = new TestableWebsocketClient;

    // Even an ancient last-data timestamp must not trigger a self-exit
    // when the stream never opted in — this is the user-data contract.
    $client->publicSetLastDataFrameAt(microtime(true) - 86400);

    expect($client->publicShouldSelfExitForNoData(microtime(true)))->toBeFalse();
});

it('does not self-exit while data frames are arriving within the window', function (): void {
    $client = new TestableWebsocketClient;
    $client->publicSetNoDataSelfExitSeconds(300);

    // Last data frame 10 seconds ago — a healthy 1Hz feed.
    $now = microtime(true);
    $client->publicSetLastDataFrameAt($now - 10);

    expect($client->publicShouldSelfExitForNoData($now))->toBeFalse();
});

it('self-exits after a sustained no-data window (the DNS-wedge repro)', function (): void {
    $client = new TestableWebsocketClient;
    $client->publicSetNoDataSelfExitSeconds(300);

    // No data frame for 6 minutes — past the 5-minute threshold. This is
    // the wedge signature: the process is alive and "reconnecting" but no
    // frames land. A fresh process is the only reliable recovery.
    $now = microtime(true);
    $client->publicSetLastDataFrameAt($now - 360);

    expect($client->publicShouldSelfExitForNoData($now))->toBeTrue();
});

it('does not self-exit before the first data window is primed (lastDataFrameAt zero)', function (): void {
    $client = new TestableWebsocketClient;
    $client->publicSetNoDataSelfExitSeconds(300);

    // lastDataFrameAt defaults to 0.0 until the watchdog primes it at
    // daemon start. A zero clock must never be read as "infinitely stale"
    // and force an exit before the daemon has had a chance to connect.
    $client->publicSetLastDataFrameAt(0.0);

    expect($client->publicShouldSelfExitForNoData(microtime(true)))->toBeFalse();
});

it('enables the self-exit on the mark-price stream (strict-data opt-in)', function (): void {
    $api = new BinanceApi(new ApiCredentials([
        'binance_api_key' => 'test-key',
        'binance_api_secret' => 'test-secret',
    ]));

    $reflection = new ReflectionClass(BinanceApi::class);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setAccessible(true);
    $client = $clientProp->getValue($api);

    $baseReflection = new ReflectionClass(Kraite\Core\Abstracts\BaseWebsocketClient::class);
    $threshold = $baseReflection->getProperty('noDataSelfExitSeconds');
    $threshold->setAccessible(true);

    // Mark-price is strict-data (1Hz) → self-exit enabled at 5 minutes.
    expect($threshold->getValue($client))->toBe(300);
});

it('leaves the self-exit disabled on a directly-built client (user-data safety)', function (): void {
    // The user-data daemon constructs BinanceApiClient directly without
    // the no_data_self_exit_seconds config key — it must stay disabled so
    // a legitimately silent account never self-exits.
    $client = new BinanceApiClient([
        'base_url' => 'wss://fstream.binance.com',
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
    ]);

    $baseReflection = new ReflectionClass(Kraite\Core\Abstracts\BaseWebsocketClient::class);
    $threshold = $baseReflection->getProperty('noDataSelfExitSeconds');
    $threshold->setAccessible(true);

    expect($threshold->getValue($client))->toBeNull();
});
