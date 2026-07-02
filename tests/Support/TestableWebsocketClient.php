<?php

declare(strict_types=1);

namespace Tests\Support;

use Kraite\Core\Abstracts\BaseWebsocketClient;
use ReflectionClass;

/**
 * Test-only subclass of `BaseWebsocketClient` that exposes the protected
 * `shouldReconnect` property via reflection so tests can pin the
 * close() → flag transition without coupling to the property visibility.
 *
 * Used by `tests/Unit/Abstracts/BaseWebsocketClientCancellationTest`.
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

    /**
     * Expose the protected sustained-no-data self-exit decision so tests
     * can pin the 2026-07-02 DNS-wedge recovery contract without booting
     * the ReactPHP loop.
     */
    public function publicShouldSelfExitForNoData(float $now): bool
    {
        return $this->shouldSelfExitForNoData($now);
    }

    public function publicSetNoDataSelfExitSeconds(?int $seconds): void
    {
        $this->setProtected('noDataSelfExitSeconds', $seconds);
    }

    public function publicGetNoDataSelfExitSeconds(): ?int
    {
        return $this->getProtected('noDataSelfExitSeconds');
    }

    public function publicSetLastDataFrameAt(float $timestamp): void
    {
        $this->setProtected('lastDataFrameAt', $timestamp);
    }

    private function setProtected(string $property, mixed $value): void
    {
        $reflection = new ReflectionClass(BaseWebsocketClient::class);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($this, $value);
    }

    private function getProtected(string $property): mixed
    {
        $reflection = new ReflectionClass(BaseWebsocketClient::class);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($this);
    }
}
