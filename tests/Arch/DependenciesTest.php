<?php

declare(strict_types=1);

/**
 * Architectural Tests for Dependencies
 *
 * These tests enforce dependency rules and boundaries.
 */
arch('app does not depend on tests')
    ->expect('App')
    ->not->toUse('Tests')
    ->not->toUse('PHPUnit')
    ->not->toUse('Pest');

arch('core does not depend on app')
    ->expect('Kraite\Core')
    ->not->toUse('App');

arch('jobs do not use forbidden dependencies')
    ->expect('Kraite\Core\Jobs')
    ->not->toUse([
        'App', // Jobs should not depend on app-specific code
    ]);

arch('observers have Observer suffix')
    ->expect('Kraite\Core\Observers')
    ->toHaveSuffix('Observer');

arch('models do not depend on jobs')
    ->expect('Kraite\Core\Models')
    ->not->toUse('Kraite\Core\Jobs')
    ->ignoring([
        'StepDispatcher\Models\Step', // Step model references job classes
    ]);
