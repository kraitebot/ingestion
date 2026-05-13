<?php

declare(strict_types=1);

use Kraite\Core\Abstracts\BaseWebsocketClient;

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
final class TestableWebsocketClient extends BaseWebsocketClient
{
    public function publicGetShouldReconnect(): bool
    {
        $reflection = new ReflectionClass(BaseWebsocketClient::class);
        $property = $reflection->getProperty('shouldReconnect');
        $property->setAccessible(true);

        return (bool) $property->getValue($this);
    }
}

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
