<?php

declare(strict_types=1);

namespace Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use RuntimeException;

/**
 * Inert ReactPHP event loop that records timers instead of running them.
 *
 * Lets the reconnect tests fire a scheduled backoff timer by hand and
 * assert that `BaseWebsocketClient` never restarts an already running
 * loop.
 *
 * Used by `tests/Unit/Abstracts/BaseWebsocketClientCancellationTest`.
 */
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
