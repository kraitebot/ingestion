<?php

declare(strict_types=1);

namespace App\Support\Tests;

use Kraite\Core\Abstracts\BaseQueueableJob;

/**
 * Inert step-job used as a default `class` value by the
 * `StepDispatcher\Database\Factories\StepFactory` factory (lives in the
 * `brunocfalcao/step-dispatcher` path package) when tests only need a
 * row in the `steps` table, not an actual workflow.
 *
 * The factory references this class by FQCN STRING — IDE/refactor tools
 * will NOT pick up renames. If this class is moved or renamed, also
 * update `StepFactory::definition()['class']` in the step-dispatcher
 * package to match.
 *
 * `compute()` is a no-op by design.
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
