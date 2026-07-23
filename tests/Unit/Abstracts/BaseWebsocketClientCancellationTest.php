<?php

declare(strict_types=1);

use Kraite\Core\Abstracts\BaseWebsocketClient;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use Tests\Support\TestableWebsocketClient;

final class ReconnectProbeLoop implements LoopInterface
{
    /**
     * @var list<TimerInterface>
     */
    public array $timers = [];

    public int $runCalls = 0;

    public function addReadStream($stream, $listener): void {}

    public function addWriteStream($stream, $listener): void {}

    public function removeReadStream($stream): void {}

    public function removeWriteStream($stream): void {}

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback);
        $this->timers[] = $timer;

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, true);
        $this->timers[] = $timer;

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void {}

    public function futureTick($listener): void {}

    public function addSignal($signal, $listener): void {}

    public function removeSignal($signal, $listener): void {}

    public function run(): void
    {
        $this->runCalls++;
    }

    public function stop(): void {}

    public function fireFirstTimer(): void
    {
        $timer = array_shift($this->timers);

        if (! $timer instanceof TimerInterface) {
            throw new RuntimeException('No reconnect timer was scheduled.');
        }

        ($timer->getCallback())();
    }
}

/**
 * Pin the reconnect-cancellation contract on BaseWebsocketClient.
 *
 * Pre-fix, the Pawl close-event handler ALWAYS called reconnect(), so
 * an intentional close() (account reap, listen-key rotation/expiry)
 * still scheduled a backoff timer that re-opened a ghost connection
 * to the now-stale URL. With listen keys rotating every ~60min × N
 * accounts the orphan-reconnect rate scaled linearly with fleet size.
 *
 * Post-fix, close() flips a `shouldReconnect` flag to false and the
 * reconnect chain checks it in three places:
 *   1. The Pawl close-event handler (skip scheduling new timer).
 *   2. The connect-failure handler (skip scheduling on failed connect).
 *   3. The deferred timer callback itself (catch a timer scheduled
 *      before close() ran).
 *
 * These tests pin the close() → shouldReconnect=false transition via
 * reflection. Full reconnect-loop behavior is exercised by the running
 * daemon and the integration suite.
 */
it('shouldReconnect starts true on a fresh client (always-on default)', function (): void {
    $client = new TestableWebsocketClient;

    // The default contract for supervised daemons: reconnect forever
    // unless the operator explicitly tears down via close().
    expect($client->publicGetShouldReconnect())->toBeTrue();
});

it('close() flips shouldReconnect to false even when wsConnection is null', function (): void {
    $client = new TestableWebsocketClient;

    expect($client->publicGetShouldReconnect())->toBeTrue();

    // No wsConnection injected — exercises the early-return branch
    // after the flag flip. The flag MUST flip BEFORE the null check
    // so a close() called on a never-connected client still cancels
    // any subsequent close-event handler that could fire.
    $client->close();

    expect($client->publicGetShouldReconnect())->toBeFalse();
});

it('close() is idempotent — calling twice keeps shouldReconnect false', function (): void {
    $client = new TestableWebsocketClient;

    $client->close();
    expect($client->publicGetShouldReconnect())->toBeFalse();

    // Second close() must not throw or flip the flag back; the
    // teardown-was-intentional state is sticky.
    $client->close();
    expect($client->publicGetShouldReconnect())->toBeFalse();
});

it('registers a reconnect without restarting the already running event loop', function (): void {
    $client = new TestableWebsocketClient;
    $loop = new ReconnectProbeLoop;

    $reflection = new ReflectionClass(BaseWebsocketClient::class);

    $loopProperty = $reflection->getProperty('loop');
    $loopProperty->setValue($client, $loop);

    $reconnect = $reflection->getMethod('reconnect');
    $reconnect->invoke($client, 'invalid://reconnect.test', []);

    $loop->fireFirstTimer();

    expect($loop->runCalls)->toBe(0);
});
