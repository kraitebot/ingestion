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
}
