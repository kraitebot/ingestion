<?php

declare(strict_types=1);

namespace Tests\Support;

use Kraite\Core\Abstracts\BaseQueueableJob;

/**
 * Queueable job whose only side effect is flipping a public flag, so the
 * freeze tests can assert that a pulled step job never reaches its
 * `compute()` body while the system is frozen.
 *
 * Used by `tests/Feature/FreezeTrafficGuardTest`.
 */
final class FreezeProbeQueueJob extends BaseQueueableJob
{
    public bool $computed = false;

    public function compute(): void
    {
        $this->computed = true;
    }
}
