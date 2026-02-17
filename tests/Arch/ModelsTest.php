<?php

declare(strict_types=1);

/**
 * Architectural Tests for Models
 *
 * These tests enforce architectural rules for Eloquent models.
 */
arch('models extend Illuminate Eloquent Model')
    ->expect('Kraite\Core\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->ignoring('Kraite\Core\Models\Enums'); // Ignore enum namespace if exists

arch('models use strict types')
    ->expect('Kraite\Core\Models')
    ->toUseStrictTypes();

arch('models are not used directly in controllers')
    ->expect('Kraite\Core\Models')
    ->not->toBeUsedIn('App\Http\Controllers')
    ->ignoring([
        'Kraite\Core\Models\User', // User model is ok in controllers
        'Kraite\Core\Models\Enums', // Enums are ok everywhere
        'Kraite\Core\Models\ExchangeSymbol', // ExchangeSymbol is used in DashboardController for stats
        'Kraite\Core\Models\Engine', // Engine is used in DashboardController for cooldown status
        'Kraite\Core\Models\Account', // Account is used in DashboardController for account list
    ]);

arch('models do not use forbidden dependencies')
    ->expect('Kraite\Core\Models')
    ->not->toUse([
        'App', // Models should not depend on app-specific code
    ]);

arch('model traits are only used by models')
    ->expect('Kraite\Core\Traits')
    ->toOnlyBeUsedIn('Kraite\Core\Models');
