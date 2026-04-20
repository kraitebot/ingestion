<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Kraite\Core\Models\Kraite;

/**
 * Helper to check if system is cooling down.
 * Returns false if database is unavailable (e.g., during CI/CD package discovery).
 */
$isCoolingDown = function (): bool {
    try {
        return (bool) Kraite::first()?->is_cooling_down;
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

// Reclaim zombie Running steps (worker died mid-job). Not gated by cooldown — cleanup, not new work.
Schedule::command('steps:recover-stale')
    ->everyMinute()
    ->withoutOverlapping();

// Scheduled jobs that create NEW steps should NOT run during cooldown
// This prevents new work from being added while we wait for existing steps to finish
// When cooling down, these tasks won't appear in schedule:list at all
if (! $isCoolingDown()) {
    Schedule::command('kraite:cron-check-stale-data')
        ->everyMinute();

    // Fetch klines for active positions only (5m timeframe)
    Schedule::command('kraite:cron-fetch-klines --only-active-positions')
        ->everyFiveMinutes();

    // Fetch klines for all symbols at indicator timeframes (for correlation data)
    Schedule::command('kraite:cron-fetch-klines --timeframe=1h')
        ->hourlyAt(5);

    Schedule::command('kraite:cron-fetch-klines --timeframe=4h')
        ->cron('5 */4 * * *');

    Schedule::command('kraite:cron-fetch-klines --timeframe=6h')
        ->cron('5 */6 * * *');

    Schedule::command('kraite:cron-fetch-klines --timeframe=12h')
        ->cron('5 */12 * * *');

    Schedule::command('kraite:cron-fetch-klines --timeframe=1d')
        ->dailyAt('00:05');

    Schedule::command('kraite:cron-store-accounts-balances')
        ->everyFiveMinutes();

    Schedule::command('kraite:cron-refresh-exchange-symbols')
        ->hourlyAt(15);

    Schedule::command('kraite:cron-conclude-symbols-direction')
        ->hourlyAt(30);

    // Purge old candles daily at 03:00 (keeps last 500 per symbol/timeframe)
    Schedule::command('kraite:purge-candles')
        ->dailyAt('03:00');

    // Archive fully-resolved step trees daily at 04:00 (keeps last 1 day)
    Schedule::command('steps:archive --duration=1')
        ->dailyAt('04:00');
}
