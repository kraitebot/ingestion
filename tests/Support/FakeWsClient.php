<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Minimal stand-in for a Pawl WebSocket client. Records whether `close()`
 * was called and can be configured to throw during close to exercise the
 * "client.close() threw but the slot must still be unset" path.
 *
 * Used by `tests/Unit/Commands/Daemons/CloseAndUnsetSlotTest`.
 */
final class FakeWsClient
{
    public bool $closed = false;

    public bool $closeShouldThrow = false;

    public function close(): void
    {
        if ($this->closeShouldThrow) {
            throw new RuntimeException('simulated close failure');
        }

        $this->closed = true;
    }
}
