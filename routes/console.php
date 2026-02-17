<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Kraite\Core\Models\Engine;

/**
 * Helper to check if system is cooling down.
 * Returns false if database is unavailable (e.g., during CI/CD package discovery).
 */
$isCoolingDown = function (): bool {
    try {
        return (bool) Engine::first()?->is_cooling_down;
    } catch (Throwable) {
        return false;
    }
};

// Step dispatcher always runs - it processes existing steps
if (config('kraite.can_dispatch_steps')) {
    Schedule::command('steps:dispatch')
        ->everySecond()
        ->when(function () {
            return config('kraite.can_dispatch_steps');
        });
}

// Scheduled jobs that create NEW steps should NOT run during cooldown
// This prevents new work from being added while we wait for existing steps to finish
// When cooling down, these tasks won't appear in schedule:list at all
if (! $isCoolingDown()) {
    Schedule::command('cronjobs:check-stale-data')
        ->everyMinute();

    // Fetch klines for active positions only (5m timeframe)
    Schedule::command('cronjobs:fetch-klines --only-active-positions')
        ->everyFiveMinutes();

    // Fetch klines for all symbols at indicator timeframes (for correlation data)
    Schedule::command('cronjobs:fetch-klines --timeframe=1h')
        ->hourlyAt(5);

    Schedule::command('cronjobs:fetch-klines --timeframe=4h')
        ->cron('5 */4 * * *');

    Schedule::command('cronjobs:fetch-klines --timeframe=6h')
        ->cron('5 */6 * * *');

    Schedule::command('cronjobs:fetch-klines --timeframe=12h')
        ->cron('5 */12 * * *');

    Schedule::command('cronjobs:fetch-klines --timeframe=1d')
        ->dailyAt('00:05');

    Schedule::command('cronjobs:store-accounts-balances')
        ->everyFiveMinutes();

    Schedule::command('cronjobs:refresh-exchange-symbols')
        ->hourlyAt(15);

    Schedule::command('cronjobs:conclude-symbols-direction')
        ->hourlyAt(45);
}
