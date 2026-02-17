<?php

declare(strict_types=1);

/**
 * Architectural Tests for Jobs
 *
 * These tests enforce architectural rules for queued jobs.
 */
arch('jobs implement ShouldQueue interface')
    ->expect('Kraite\Core\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue')
    ->ignoring('Kraite\Core\Jobs\Lifecycles'); // Lifecycle classes are orchestrators, not queued jobs

arch('jobs use strict types')
    ->expect('Kraite\Core\Jobs')
    ->toUseStrictTypes();

// Jobs can be final - no restriction needed

arch('jobs have reasonable complexity')
    ->expect('Kraite\Core\Jobs')
    ->classes()
    ->toHaveLineCountLessThan(700) // Increased limit for complex jobs
    ->ignoring([
        'Kraite\Core\Jobs\BaseQueueableJob',
        'Kraite\Core\Jobs\BaseApiableJob',
    ]);
