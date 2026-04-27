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

// Reclaim stalled steps (Running zombies, Dispatched stalls) and release wedged
// dispatcher locks. Not gated by cooldown — cleanup, not new work. The
// --watchdog-progress flag (added 2026-04-25) generalises stall detection
// beyond per-step zombies: any group with Pending work but no terminal-state
// progress in the last 10 minutes fires a critical StaleStepsDetected event,
// catching cleanup-phase wedges that don't surface a stuck step.
// Flags owned by brunocfalcao/step-dispatcher >= 1.11; notifications wired via
// the StaleStepsDetected event (SendStaleStepsNotification listener in kraitebot/core).
Schedule::command('steps:recover-stale --recover-dispatched --release-locks --watchdog-progress')
    ->everyMinute()
    ->withoutOverlapping();

// External watchdog for the Binance mark-price daemon. Belt-and-suspenders on top of
// the BaseWebsocketClient idle watchdog — if the PHP process itself stalls (OS stall,
// deadlock, etc.), this bounces it via supervisorctl. Not gated by cooldown — fresh
// prices are load-bearing for selection + S/R gating + residual detection even when
// new trades are paused.
Schedule::command('kraite:watch-price-stream')
    ->everyMinute()
    ->withoutOverlapping();

// Scheduled jobs that create NEW steps should NOT run during cooldown
// This prevents new work from being added while we wait for existing steps to finish
// When cooling down, these tasks won't appear in schedule:list at all
if (! $isCoolingDown()) {
    // Reconcile exchange order state into the DB. Gated by cooldown so an operator
    // patching the dispatcher / consumer side can drain ALL new-work creation —
    // including sync — before touching code. Outside cooldown windows, open
    // positions still need active reconciliation; the gate flips back the moment
    // cooldown lifts.
    Schedule::command('kraite:cron-sync-orders')
        ->everyMinute()
        ->withoutOverlapping();

    // Open new positions every 3 minutes. Runs PreparePositionsOpeningJob
    // per account/can_trade=true combo, which in turn fans out the
    // Verify/Query/Assign/Dispatch chain only if slots are available.
    Schedule::command('kraite:cron-create-positions')
        ->cron('*/3 * * * *')
        ->withoutOverlapping();

    // Fetch klines for active positions only (5m timeframe)
    Schedule::command('kraite:cron-fetch-klines --only-active-positions')
        ->everyFiveMinutes();

    // Fetch klines for all symbols at indicator timeframes (for correlation data)
    Schedule::command('kraite:cron-fetch-klines --timeframe=1h')
        ->hourlyAt(5);

    Schedule::command('kraite:cron-fetch-klines --timeframe=4h')
        ->cron('5 */4 * * *');

    Schedule::command('kraite:cron-fetch-klines --timeframe=12h')
        ->cron('5 */12 * * *');

    Schedule::command('kraite:cron-store-accounts-balances')
        ->everyFiveMinutes();

    Schedule::command('kraite:cron-refresh-exchange-symbols')
        ->hourlyAt(15);

    Schedule::command('kraite:cron-conclude-symbols-direction')
        ->hourlyAt(30);

    // Disabled 2026-04-27: deny-list sweep retired in favour of the
    // per-token `was_backtesting_approved` flag — operator now decides
    // tradability one token at a time after reviewing backtest data.
    // The `kraite:disable-volatile-tokens` command + ALLOWED_TOKENS list
    // remain in core (still callable manually) in case the deny-list
    // sweep is reintroduced later.
    //
    // Schedule::command('kraite:disable-volatile-tokens')
    //     ->hourlyAt(45)
    //     ->withoutOverlapping();

    // Hourly Black Swan Composite Score (BSCS) recompute — Phase 1
    // telemetry only. Reads BTC + 4 reference alts klines, computes the
    // five sub-signals, persists a snapshot, denormalises onto the kraite
    // singleton. NO trading-flow side effects in Phase 1.
    Schedule::command('kraite:cron-compute-market-regime')
        ->hourlyAt(50)
        ->withoutOverlapping()
        ->onOneServer();

    // Purge old candles daily at 03:00 (keeps last 500 per symbol/timeframe)
    Schedule::command('kraite:purge-candles')
        ->dailyAt('03:00');

    // Purge candles for ExchangeSymbols whose backtest review was rejected.
    // Runs hourly so a fresh reject quickly drops dead candle weight.
    Schedule::command('kraite:cron-purge-failed-backtested-klines')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

    // Purge old model_logs daily at 03:30 — keeps a 30-day rolling window of
    // attribute-change history (position lifecycle, fills, WAP transitions,
    // close chains). Beyond that, the log volume outweighs the forensics
    // value and the table growth starts to hurt.
    Schedule::command('kraite:purge-model-logs --duration=30')
        ->dailyAt('03:30');

    // Archive fully-resolved step trees daily at 04:00 (keeps last 1 day)
    Schedule::command('steps:archive --duration=1')
        ->dailyAt('04:00');
}
