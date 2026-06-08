<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Kraite\Core\Models\Kraite;

/**
 * Helper to check if system is cooling down.
 *
 * Returns false ONLY when the DB read genuinely succeeds and the flag
 * is false. On DB-read failure: returns false in non-production
 * (CI/CD package discovery, local boot when DB isn't migrated yet) so
 * `php artisan` continues to work, but returns TRUE on production-role
 * servers (`ingestion`, `worker`) so an unknown cooldown state fails
 * CLOSED — registering only health/cleanup commands instead of every
 * cron tick. A transient DB blip during schedule boot must NOT mean
 * "schedule everything as if all systems are go".
 */
$isCoolingDown = function (): bool {
    try {
        return (bool) Kraite::first()?->is_cooling_down;
    } catch (Throwable) {
        $rawRole = config('kraite.server_role');
        $role = is_string($rawRole) ? $rawRole : '';

        return in_array($role, ['ingestion', 'worker'], true);
    }
};

// Step dispatcher — one entry per dispatcher group so each group gets its
// own every-second tick instead of all 10 groups serialised behind one
// global iterator. Throughput per group goes from "global tick every ~5s"
// to "per-group tick every 1s" — about a 5× lift on dispatchable promotion
// rate before the per-group max_per_tick cap kicks in.
//
// Safety:
//  • Each tick acquires a per-group lock via steps_dispatcher.can_dispatch
//    CAS (single row per group). 10 dispatchers → 10 disjoint locks; no
//    cross-group contention.
//  • Every tick query carries an explicit `where('group', X)` filter
//    covered by idx_steps_state_group_dispatch_type, so the rows each
//    dispatcher touches are partitioned by the group column. Block-uuid
//    cache lookups are globally unique by construction. Audited 2026-05-08
//    against the package's full tick lifecycle — no global table scans.
//  • The maintenance pause flag (MaintenanceMode::isStepsDispatchPaused)
//    is checked once per tick per group; if any short-window operation
//    needs the dispatcher quiesced, every group ticks-out cleanly until
//    the flag clears.
//  • The `kraite.can_dispatch_steps` env-level kill-switch still gates
//    every entry so a global emergency stop is one config flip away.
// All scheduled commands only run on the ingestion server.
// Workers only process queued jobs via Horizon — they never dispatch.
if (config('kraite.server_role') !== 'ingestion') {
    return;
}

// Step dispatcher runs as a long-running daemon via supervisor
// (kraite:dispatch-daemon), NOT as scheduler forks. This eliminates
// the 20 forks/second that saturated 4-vCPU machines at 100% CPU.
// See: /etc/supervisor/conf.d/kraite-dispatch-daemon.conf

// Per-minute flush of the dispatcher saturation counters from Redis
// into `steps_dispatcher_saturation`. Reads the previous completed
// minute so we never race with in-flight ticks. Dashboard reads from
// MySQL only; the dispatcher hot path stays Redis-only.
Schedule::command('kraite:cron-flush-dispatcher-saturation')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

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

// Same recover-stale watchdog scoped to the `trading_*` table set.
// Independent withoutOverlapping lock from the default cron so the
// two never block each other — they read / write disjoint tables.
Schedule::command('steps:recover-stale --prefix=trading --recover-dispatched --release-locks --watchdog-progress')
    ->everyMinute()
    ->withoutOverlapping();

// Mark-price freshness is now a check inside `kraite:cron-check-system-health`
// (see below) — the standalone `kraite:cron-check-stale-data` was retired
// 2026-05-02 in favour of the unified watchdog so every staleness signal
// shares the same `system_health_alert` notification + 5-minute per-signal
// throttle.

// Keep every Binance account's listenKey alive past Binance's 60-minute
// auto-expiry. The user-data WebSocket daemon
// (`kraite:stream-binance-user-data`) opens the connection but does NOT
// refresh the key on its own — this cron is the dedicated keepalive
// surface so a daemon restart never racing with key expiry. Three
// consecutive failures on one account fire a Pushover alert. Runs
// regardless of cooldown gate: keepalive is operational maintenance,
// not new-work creation.
Schedule::command('kraite:cron-refresh-binance-listen-keys')
    ->everyMinute()
    ->withoutOverlapping();

// Listen-key staleness watchdog. Detects two failure modes the
// keepalive cron alone cannot surface: (1) an active Binance account
// with NO listenKey row (daemon never initialised it), and (2) a row
// whose last_keep_alive_at is older than 30 minutes (keepalive cron
// not firing for this account). Threshold is well below Binance's
// 60-minute hard expiry so the operator has time to respond before
// the WS dies. Per-account dedupe so a sustained failure alerts on
// every check window without spamming.
Schedule::command('kraite:cron-check-binance-listen-keys-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Generic system-health watchdog. Nine sequential checks across the
// bot's critical data paths (indicator freshness, balance freshness,
// daemon heartbeat, dispatcher tick rate, scheduler liveness,
// failed_jobs overflow, DB connection, Redis connection, Horizon
// queue depth). Every alert routes through the shared
// `system_health_alert` notification with a per-signal cache key
// (5-minute throttle) so distinct failures dedupe independently.
Schedule::command('kraite:cron-check-system-health')
    ->cron('*/7 * * * *')
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
        ->everyFiveMinutes()
        ->withoutOverlapping();

    // Proactive 5-minute drift spotter — safety net on top of the
    // every-minute reactive sync. Audits active positions that have
    // been quiet for 10+ minutes against the exchange, dispatches
    // PrepareSyncOrdersJob on disagreements, and cleans orphan open
    // orders attached to non-active positions (ghosts → DB CANCELLED
    // inline, real algo → CancelSingleAlgoOrderJob). One pushover per
    // affected position per cycle (admin-only).
    Schedule::command('kraite:cron-check-drifts')
        ->everyFiveMinutes()
        ->withoutOverlapping();

    // Open new positions every 3 minutes. Runs PreparePositionsOpeningJob
    // per account/can_trade=true combo, which in turn fans out the
    // Verify/Query/Assign/Dispatch chain only if slots are available.
    Schedule::command('kraite:cron-create-positions')
        ->cron('*/3 * * * *')
        ->withoutOverlapping();

    // Fetch klines for active positions only (5m timeframe)
    Schedule::command('kraite:cron-fetch-klines --only-active-positions')
        ->everyFiveMinutes()
        ->withoutOverlapping();

    // Fetch 15m klines for the BSCS reference basket (BTC + ETH/SOL/BNB/XRP
    // by default, configurable via MARKET_REGIME_SYMBOLS). Required by the
    // upcoming MarketShockCircuitBreaker which reads 15m intra-hour moves
    // to catch cascades before the hourly BSCS compute would notice them.
    // Active-positions-only schedule above doesn't reliably cover this set.
    Schedule::command('kraite:cron-fetch-klines --reference-set --canonical=binance --timeframe=15m')
        ->everyFifteenMinutes()
        ->withoutOverlapping();

    // Fetch klines for all symbols at indicator timeframes (for correlation data)
    Schedule::command('kraite:cron-fetch-klines --timeframe=4h')
        ->cron('5 */4 * * *')
        ->withoutOverlapping();

    Schedule::command('kraite:cron-fetch-klines --timeframe=6h')
        ->cron('5 */6 * * *')
        ->withoutOverlapping();

    Schedule::command('kraite:cron-fetch-klines --timeframe=12h')
        ->cron('5 */12 * * *')
        ->withoutOverlapping();

    Schedule::command('kraite:cron-store-accounts-balances')
        ->everyFiveMinutes()
        ->withoutOverlapping();

    Schedule::command('kraite:cron-upsert-pnls')
        ->everyFiveMinutes()
        ->withoutOverlapping();

    Schedule::command('kraite:cron-refresh-exchange-symbols')
        ->hourlyAt(15)
        ->withoutOverlapping();

    Schedule::command('kraite:cron-conclude-symbols-direction')
        ->hourlyAt(30)
        ->withoutOverlapping();

    Schedule::command('kraite:cron-renew-subscriptions')
        ->dailyAt('00:00')
        ->withoutOverlapping()
        ->onOneServer();

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

    // Hourly Black Swan Composite Score (BSCS) recompute. Reads BTC + 4
    // reference alts klines, computes the five sub-signals, persists a
    // snapshot, denormalises score+band onto the kraite singleton.
    Schedule::command('kraite:cron-compute-market-regime')
        ->hourlyAt(50)
        ->withoutOverlapping()
        ->onOneServer();

    // BSCS gate state machine — runs 5 minutes after the compute cron so
    // it acts on the freshly-stamped score. Arms a 24h cooldown when score
    // crosses threshold, re-arms on expiry if score still high, releases
    // when score recovers below threshold. Wires `BlackSwanIndex::shouldBlockOpens()`
    // through `HasTradingGuards::canOpenPositions()` to pause new opens
    // while the cooldown is active. Existing positions untouched.
    Schedule::command('kraite:cron-analyse-bscs')
        ->hourlyAt(55)
        ->withoutOverlapping()
        ->onOneServer();

    // Cascade detector — fast safety net that closes the hourly BSCS
    // compute blind spot. Runs every minute on 15m klines for BTC + 4
    // reference alts. When any rule fires (BTC -3%/15m, BTC -5%/1h,
    // alt-basket -7%/1h, or corr ≥ 0.85 + |BTC 1h| ≥ 3%), arms the
    // SHARED bscs_cooldown_until column for cooldown_hours (default
    // 24h, same column the BSCS analyse cron uses). Silent re-fire
    // while a cooldown is already active to avoid notification spam.
    Schedule::command('kraite:cron-detect-market-shock')
        ->everyMinute()
        ->withoutOverlapping()
        ->onOneServer();

    // Purge old candles daily at 03:00 (keeps last 500 per symbol/timeframe)
    Schedule::command('kraite:purge-candles')
        ->dailyAt('03:00');

    // Sweep breadcrumb trails of positions whose clean close has aged past
    // the configured retention window (kraite.positions.trail_retention_hours).
    // With retention 0 the PositionObserver purges on close and this sweep
    // finds nothing — it only carries the deferred-retention mode, where the
    // trail must survive long enough for the every-3-hours DB backup
    // (cron 7 */3) to capture it before reclamation. Slotted before the
    // 03:30 generic log purges.
    Schedule::command('kraite:cron-purge-position-trails')
        ->dailyAt('03:20');

    // Purge candles for ExchangeSymbols whose backtest review was rejected.
    // Runs hourly so a fresh reject quickly drops dead candle weight.
    Schedule::command('kraite:cron-purge-failed-backtested-klines')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();

    // Purge old operational logs daily at 03:30:
    //   - api_request_logs: 5-day rolling window (high-volume HTTP audit trail
    //     for every Binance/Bitget/Bybit/KuCoin/TAAPI call; 200 OK rows are
    //     forensic noise after a few days and the table size dominates the
    //     buffer pool when left unbounded).
    //   - model_logs:        30-day rolling window of attribute-change history
    //     (position lifecycle, fills, WAP transitions, close chains). Beyond
    //     that, log volume outweighs the forensics value.
    Schedule::command('kraite:purge-old-data --api-request-logs-days=5 --model-logs-days=30')
        ->dailyAt('03:30');

    // Archive fully-resolved step trees daily at 04:00 (keeps last 1 day)
    Schedule::command('steps:archive --duration=1')
        ->dailyAt('04:00');

    // Same archive pass, scoped to the `trading_*` table set. Offset by
    // 5 minutes so the two passes never compete for I/O on the same
    // physical disk. Same 1-day retention as the default set.
    Schedule::command('steps:archive --prefix=trading --duration=1')
        ->dailyAt('04:05');

    // Trim the archive itself daily at 04:30, 30 min after the archive
    // run finishes. --only-archive keeps the live `steps` table and the
    // ticks table off-limits — date-based delete on steps_archive only.
    // 5-day retention window: anything older than that is gone.
    Schedule::command('steps:purge --only-archive --days=5')
        ->dailyAt('04:30');

    // Same archive trim, scoped to `trading_steps_archive`. Offset by
    // 5 minutes from the default purge for the same I/O reason.
    Schedule::command('steps:purge --prefix=trading --only-archive --days=5')
        ->dailyAt('04:35');

    // ---------------------------------------------------------------
    // OPTIMIZE TABLE on the breadcrumb tables — staggered window
    // 03:00 → 04:36, 24-min spacing, one table per slot.
    // **Weekly on Sundays only** — the per-position janitor + the
    // daily purge chain keep the .ibd files compact enough that a
    // daily OPTIMIZE was finding zero fragmentation to reclaim
    // (verified 2026-05-07 mid-day run: delta=0MB across all five
    // tables). One nightly rebuild per week reclaims the slow-drift
    // residue without paying the daily cost.
    //
    // Each entry runs the same OPTIMIZE command targeting a single
    // table, which engages MaintenanceMode (pauses steps:dispatch),
    // waits for the in-flight pipeline to drain, runs the rebuild,
    // then resumes dispatch — all in a tight per-table window so the
    // dispatcher catches up between slots instead of staying gated
    // for the whole rebuild pass. Sequential per-table avoids the
    // disk-bandwidth contention parallel mode produced (per-table
    // duration tripled when 5 rebuilds shared the disk).
    //
    // Slot ordering is interleaved with the existing purge chain so
    // the OPTIMIZE on each table runs AFTER that table's purge
    // finishes:
    //   03:00  model_logs       (own slot — purge-old-data hits this
    //                            at 03:30 but we only want to compact
    //                            the week's accumulated deletes here)
    //   03:00  purge-candles    (existing — different table)
    //   03:24  api_snapshots    (instant; harmless filler slot)
    //   03:30  purge-old-data   (existing)
    //   03:48  api_request_logs (after purge-old-data finishes)
    //   04:00  steps:archive    (existing)
    //   04:12  steps            (after archive run finishes)
    //   04:30  steps:purge      (existing)
    //   04:36  steps_archive    (after archive purge finishes)
    // ---------------------------------------------------------------
    Schedule::command('kraite:cron-optimize-breadcrumb-tables --table=model_logs')
        ->weeklyOn(0, '03:00')
        ->withoutOverlapping()
        ->onOneServer();

    Schedule::command('kraite:cron-optimize-breadcrumb-tables --table=api_snapshots')
        ->weeklyOn(0, '03:24')
        ->withoutOverlapping()
        ->onOneServer();

    Schedule::command('kraite:cron-optimize-breadcrumb-tables --table=api_request_logs')
        ->weeklyOn(0, '03:48')
        ->withoutOverlapping()
        ->onOneServer();

    Schedule::command('kraite:cron-optimize-breadcrumb-tables --table=steps')
        ->weeklyOn(0, '04:12')
        ->withoutOverlapping()
        ->onOneServer();

    Schedule::command('kraite:cron-optimize-breadcrumb-tables --table=steps_archive')
        ->weeklyOn(0, '04:36')
        ->withoutOverlapping()
        ->onOneServer();

    // -------------------------------------------------------------------
    // Database backups (spatie/laravel-backup → Backblaze B2 only)
    // -------------------------------------------------------------------
    // Snapshot every 3 hours at minute 7, off the conclude:30 /
    // refresh:15 / bscs:50 / bscs:55 bursts. `--only-db` skips the
    // file-system part (codebase lives in git, no upside zipping
    // vendor/). Cleanup chained immediately after — TieredStrategy
    // (hourly=3, daily=0, weekly=0) keeps a rolling window of the
    // latest 3 backups; the 4th run evicts the oldest.
    // PRODUCTION ONLY. Every box in the fleet runs server_role=ingestion
    // (including the local dev box, so the full pipeline can be exercised
    // locally), so the server_role gate above does NOT separate dev from
    // prod — APP_ENV does. Backups must never run off a dev box: localhost
    // carries the same B2 credentials, and the keep-latest-3 cleanup would
    // upload a local dump into the shared bucket and evict a real prod
    // snapshot. Gate strictly on APP_ENV=production.
    if (app()->isProduction()) {
        Schedule::command('backup:run --only-db --disable-notifications')
            ->cron('7 */3 * * *')
            ->withoutOverlapping()
            ->onOneServer()
            ->then(function (): void {
                Artisan::call('backup:clean', ['--disable-notifications' => true]);
            });

        // Backup-freshness watchdog every 6 hours. Fires
        // `UnhealthyBackupWasFound` event when the newest B2 backup is
        // older than the configured threshold or bucket usage exceeds
        // configured cap. Bridge listener routes the event to the
        // `system_health_alert` Pushover canonical.
        Schedule::command('backup:monitor')
            ->cron('15 */6 * * *')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
