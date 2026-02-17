<?php

declare(strict_types=1);

namespace App\Support\Tests;

use Kraite\Core\Abstracts\BaseQueueableJob;

/**
 * Simple test job that does nothing.
 * Used by StepFactory for testing purposes.
 */
final class EchoJob extends BaseQueueableJob
{
    public int $tries = 1;

    /** @var int */
    public $timeout = 30;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    protected function compute(): void
    {
        // Do nothing - this is a test job
    }
}
