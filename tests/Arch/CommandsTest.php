<?php

declare(strict_types=1);

/**
 * Architectural Tests for Console Commands
 *
 * These tests enforce architectural rules for Artisan commands.
 */
arch('commands extend Laravel Command class')
    ->expect('App\Console\Commands')
    ->toExtend('Illuminate\Console\Command');

arch('commands use strict types')
    ->expect('App\Console\Commands')
    ->toUseStrictTypes();

arch('commands have reasonable line count')
    ->expect('App\Console\Commands')
    ->toHaveLineCountLessThan(500)
    ->ignoring('App\Console\Commands\Tests'); // Test commands may be longer
