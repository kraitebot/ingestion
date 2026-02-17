<?php

declare(strict_types=1);

/**
 * Architectural Tests for Code Quality
 *
 * These tests enforce general code quality standards.
 */
arch('no debugging functions in production code')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed()
    ->ignoring('Tests');

arch('no var_dump or print_r in production code')
    ->expect(['var_dump', 'print_r', 'var_export'])
    ->not->toBeUsed()
    ->ignoring('Tests');

arch('no die or exit in production code')
    ->expect(['die', 'exit'])
    ->not->toBeUsed()
    ->ignoring([
        'Tests',
        'App\Console\Commands', // Commands may use exit codes
    ]);

arch('strict types are declared everywhere')
    ->expect('Kraite\Core')
    ->toUseStrictTypes();

arch('strict types are declared in app code')
    ->expect('App')
    ->toUseStrictTypes()
    ->ignoring([
        'App\Exceptions\Handler', // May not have strict types
        'App\Providers',
    ]);

arch('no eval usage')
    ->expect('eval')
    ->not->toBeUsed();
