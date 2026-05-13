# Changelog

All notable changes to this project will be documented in this file.

## 1.40.1 - 2026-05-13

### Infrastructure
- [INFRA] `deploy.sh` adds `brunocfalcao/step-dispatcher` to the `composer update` allow-list alongside `kraitebot/core`. Prevents the v1.40.0 dev-master regression where the lock pinned a stale step-dispatcher entry and workers ran old code until manual `composer update` was issued per host.

### Maintenance
- [CHORE] Vendor lock refresh: `aws/aws-sdk-php` 3.380.3 → 3.381.0, Symfony components (cache, console, http-kernel, finder, etc.) → v7.4.11 / v8.0.11, `league/flysystem` 2.4.2 → 2.4.3, `dasprid/enum` v4.1.3 → v4.1.4.

## 1.40.0 - 2026-05-13

### Features
- [FEATURE] Bumps `kraitebot/core` to v1.40.0 — code-review pass closing reviews 10–24 (~50 source files patched, 2 new migrations, idempotency parity for `RecreateCancelledOrderJob`, dual-prefix recovery, sticky forbidden records, fail-closed throttler on Redis outage, HTTP timeouts, `withOnlyFromStatus` lifecycle guards, account-scoped user-data event resolution, exchange-only drift signals, deletion of `Kraite::computeMarketOrder` + `HasPositionPlanning` dead code).
- [FEATURE] Bumps `brunocfalcao/step-dispatcher` to v1.12.2 — `doubleCheck` exhaustion fails the step (was silent complete), `retryJobWithBackoff` preserves DB backoff, `buildStepsCache` group-scoped, `batchTransitionSteps` log on failure.

### Improvements
- [IMPROVED] `withoutOverlapping()` added to several heavy recurring crons (kline fetches, balances, exchange-symbols refresh, conclude-symbols-direction).
- [IMPROVED] Production-role schedule helper fails CLOSED (registers only operational schedules) on DB-read failure for `ingestion` / `worker` server roles. Local / package-discovery boots still fail open for legacy compatibility.

### Hardening
- [HARDENED] 11 new TDD test files pinning the contract for every Implement verdict in the cycle (idempotency, lock+dedupe, account-scoping, throttler fail-closed, HTTP timeouts, etc.).

## 1.39.0 - 2026-05-13

### Features
- [FEATURE] Bumps `kraitebot/core` to v1.39.0 — code-review pass + original-price forensic anchors + idempotency parity
- [FEATURE] `CheckSystemHealthCommand` adds `checkStaleSyncingPositions` watchdog (15-min threshold)
- [FEATURE] `slow_query_detected` notification gets cache-key-based throttling (per-connection)

### Improvements
- [IMPROVED] `NotificationService` cache-key build wrapped in failure containment
- [IMPROVED] `AlertNotification` carries per-send relatable (eliminates cached-admin user audit-log leak)
- [IMPROVED] `NotificationLogListener::createLog` body contained; Telegram channel/recipient normalized
- [IMPROVED] `ApiRequestLogObserver::saved` per-branch try/catch; TAAPI deactivation notification fires after commit
- [IMPROVED] Broad `serverForbiddenHttpCodes` cleared on Binance/Bybit, reduced to `[403]` on Bitget/Kucoin (eliminates double-fire with `ForbiddenHostnameObserver`)
- [IMPROVED] `CreatePositionsCommand` shuffles account dispatch order
- [IMPROVED] `PlaceMarketOrderJob` consults `apiSystem->inCooldown()` before firing
- [IMPROVED] `PlaceStopLossOrderJob` and `PlaceProfitOrderJob` rehydrate-existing-on-retry idempotency parity
- [IMPROVED] `BaseApiableJob::getRetryDiagnostics` rewritten with type-aware ban messages
- [IMPROVED] `BaseQueueableJob::onExceptionLogged` wrapped in failure containment
- [IMPROVED] Mail-from name auto-derived from address local-part
- [IMPROVED] Original-price forensic anchors on orders + write-once protection in `OrderObserver` (parallel workstream)

### Hardening
- [HARDENED] `CreatePositionsCommand --clean` refused outside local/testing
- [HARDENED] `SyncOrdersCommand --clean` refused outside local/testing
- [HARDENED] `SyncOrdersCommand --order_id` requires `--force` outside local/testing + bypass-safety warning

## 1.37.7 - 2026-05-10

### Improvements
- [IMPROVED] Local Horizon auto-balance (min 1 / max N per queue, saves ~1.7GB RAM)
- [IMPROVED] Bumps `kraitebot/core` to v1.37.6 — php_binary config key

## 1.37.6 - 2026-05-10

### Improvements
- [IMPROVED] Bumps `kraitebot/core` to v1.37.5 — NOWPayments credentials on Kraite singleton (migration + encrypted columns)

## 1.37.4 - 2026-05-10

### Improvements
- [IMPROVED] BusinessSeeder simplified: production = sysadmin only, local = sysadmin + Karine + Binance account
- [IMPROVED] Bumps `kraitebot/core` to v1.37.4 — local symbol restriction (21 tokens when APP_ENV=local), HORIZON_ENV config key

## 1.37.3 - 2026-05-10

### Features
- [NEW FEATURE] Dedicated indicators server (artemis) — 20 indicator workers on a separate $5/mo server
- [NEW FEATURE] `HORIZON_ENV` config key — decouples Horizon environment selection from `APP_ENV`

### Improvements
- [IMPROVED] `deploy.sh` v4 — aborts if any `dev-master` packages detected in composer.lock
- [IMPROVED] Indicators queue removed from apollo/ares (now handled by artemis)
- [IMPROVED] Athena keeps 2 indicator workers for self-sufficiency during deploys

## 1.37.2 - 2026-05-10

### Improvements
- [IMPROVED] `deploy.sh` v4 — tag-based deploys: requires `DEPLOY_TAG` env var, checks out exact tagged commit instead of branch HEAD

## 1.37.1 - 2026-05-10

### Improvements
- [IMPROVED] Athena Horizon: add 2 workers per queue (positions, orders, indicators, priority) for self-sufficient deploys
- [IMPROVED] `deploy.sh` v4 — verify composer auth, update kraitebot/core after install, suppress view:cache on workers, clean dirty index before fetch

## 1.37.0 - 2026-05-10

### Features
- [NEW FEATURE] `kraite:dispatch-daemon` — persistent single-process dispatcher replacing 20 scheduler forks/second (CPU load 105 → 0.68)
- [NEW FEATURE] Indicator queue offloaded to workers (apollo 10, ares 10, athena 0) — frees ingestion RAM for WebSocket streams
- [NEW FEATURE] `deploy.sh` v3 — backs up/restores production `composer.json` across `git reset --hard`
- [NEW FEATURE] WebSocket stream URLs added to `config/kraite.php` (Binance fstream, Bybit, Bitget, KuCoin)

### Improvements
- [IMPROVED] Bumps `kraitebot/core` to v1.37.2 — cascade FK on `exchange_symbol_prices`, `ExchangeSymbolObserver::created()` auto-creates sidecar rows
- [IMPROVED] `BusinessSeeder` uses `config()->string()` for PHPStan compliance
- [IMPROVED] `FetchKlinesReferenceSetTest` uses model-level deletes for cascade FK compatibility

## 1.36.0 - 2026-05-10

### Features
- [NEW FEATURE] `deploy.sh` v2 — role-aware deployment script (cooldown-verified, DB backup on ingestion, no Ploi)
- [NEW FEATURE] Horizon environments for athena/apollo/ares with dedicated queue splits
- [NEW FEATURE] `console.php` gated by `SERVER_ROLE=ingestion` — prevents accidental step-dispatching from workers
- [NEW FEATURE] Bumps `kraitebot/core` to 1.36.0 — `kraite:cooldown` + `kraite:warmup` commands

### Improvements
- [IMPROVED] CI: fix PHPStan cast errors, Mago attestation, test fixes (10 tests fixed)
- [IMPROVED] CI: trigger on tags + push to master

## 1.35.0 - 2026-05-09

### Improvements
- [IMPROVED] Bumps `kraitebot/core` to 1.35.0 — drop waitlist_subscribers table, admin credentials migration
- [IMPROVED] `.env.testing` removed from git history (contained real API keys), added to `.gitignore`
- [IMPROVED] Composer path repos updated to `../packages/` for unified local dev structure

## 1.34.1 - 2026-05-09

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.34.5** — TRIGGERED algo-status faithfulness (mapper + observer pin + `dispatchClosePosition` `reference_status` race fix), simple-trade-mode (`N=0`) SL anchor falls back to `opening_price`, four latent-defect fixes (`Math::multiply` typo on residual check, drift-detector strict `!==` vs accessor-stripped price, `delistedAt` return-type vs `CarbonImmutable`, dead `Concerns/Order/HasStatuses` methods writing to non-existent `error_message` column). See core 1.34.5 changelog for the full surface.
- [IMPROVED] **Test fixtures wrapped under `Steps::usingPrefix('trading', …)` for trading-prefix step queries.** Seven test files (`OrderObserverTest`, `OrderObserverDriftSkipTest`, `OrderObserverDispatchDedupeRaceTest`, `OrderObserverPartialFillSyncTest`, `OrderObserverDriftDetectionDuringSyncTest`, `PurgePositionTrailJobTest`, `CreatePositionsCommandOrphanRecoveryTest`) now match the production prefix-context wrapper added in 1.34.0. Without it, the in-test `Step::where(…)` queries hit the default `steps_*` tables and miss the rows the production code wrote into `trading_steps_*`.
- [IMPROVED] **`tests/Unit/Support/Backtest/BacktestSimulatorDividerTest.php` sentinel updated** to reflect the new `get_market_order_amount_divider(0) => 1` contract (was previously asserting the old `2^(N+1)` curve).

### Tests

- [NEW FEATURE] **~220 new tests across position/order lifecycles, observers, mappers, math primitives, accessors, and scopes.** New files cover: position status writers + side effects; position scope groupings (active/opened/nonActive/ongoing); position accessors (pnl, current_price, parsed_trading_pair_extended, daily_variation_percentage); position trading actions (opened_since); position getters (lastLimitOrder, profitOrder, stopLossOrder, isActive); order status flips + scope filters (syncable/cancellable/activeOnExchange/cancelled); order isLastLimitOrder anchor; ladder calculator N=0 contract + LONG/SHORT geometry + monotonicity; PnL calculations (calculatePnL, calculateWAPData, calculatePnLAnalysis with LONG/SHORT TP-vs-WAP sign-flip pinned); dispatch / placement / activation gates (PlaceMarketOrder, PlaceLimitOrder, PlaceProfitOrder, DispatchLimitOrders, ActivatePosition, CalculateWap, SyncPositionOrders, CorrectModifiedOrder, RecreateCancelledOrder); preparation gates (PrepareData / DetermineLeverage / SetLeverage / VerifyOrderNotional); WAP gate (Failed-vs-Skipped buckets); ClosePosition openedStatuses gate; CancelOrphanAlgoOrders base no-op; OrderObserver creation slot guard (1 MARKET / 1 STOP-MARKET / 1 PROFIT / N LIMITs); PositionObserver purge-trail (closed-only, prefix-aware); ApiSnapshot store/getFrom + canonical scope; NotificationLog scopes + enum bounce-string compat; ExchangeSymbol delisting (isDelisted, delistedAt, notDelisted scope); Account scopes + slot helpers + hedge/one-way mode; Math primitives (equal/gt/lt/gte/lte/cmp/add/sub/mul/div/pow/isPositive); Binance + Bitget mapper canonicals (identifyBaseAndQuote, canonicalOrderType); MapsOrderModify computeOrderModifyPrice; AlgoStatus TRIGGERED faithfulness; truncate_decimal_string + remove_trailing_zeros (TAKE #151 integer-survival pin); ComputationHelpers (returnLadderedValue clamp, pctToDecimal). Total suite: ~1926 → ~2153 passing, 0 code-failures.

## 1.34.0 - 2026-05-08

### Features

- [NEW FEATURE] **Parallel `trading_*` dispatcher fleet wired into the scheduler.** 13 new schedule entries in `routes/console.php`: 10× `steps:dispatch --prefix=trading --group=<group>` per-second per-group with `runInBackground()` (mirrors the default fleet wiring shipped earlier today); 1× `steps:recover-stale --prefix=trading --recover-dispatched --release-locks --watchdog-progress` every minute; 1× `steps:archive --prefix=trading --duration=1` daily at 04:05 (5-min offset from default's 04:00 to avoid disk-bandwidth contention); 1× `steps:purge --prefix=trading --only-archive --days=5` daily at 04:35 (5-min offset from default's 04:30). Each dispatcher entry's `->skip()` callback now passes its own prefix to `MaintenanceMode::isStepsDispatchPaused()` so per-prefix cooldowns gate the right fleet. Default-fleet entries gate on `''`, trading-fleet entries gate on `'trading'`. Bumps `kraitebot/core` to 1.34.0 (trading-prefix wraps + per-prefix MaintenanceMode + notification gates) and `brunocfalcao/step-dispatcher` to 1.12.0 (RuntimeContext + Steps facade + `steps:install --prefix=` + universal `--prefix=` CLI option + worker-side `__unserialize()` prefix gate).

## 1.33.0 - 2026-05-08

### Features

- [NEW FEATURE] **Per-group `steps:dispatch` parallelism in `routes/console.php`.** Replaced the single `Schedule::command('steps:dispatch')->everySecond()` entry (which looped all 10 groups serially in one PHP process, ~5s per group cycle) with 10 dedicated `--group=<name>` entries, each `everySecond()->runInBackground()`. The scheduler now forks each group's dispatcher into its own subprocess so all 10 ticks fire in parallel. Per-group cadence drops from ~5s to ~1s — about a 5× lift on dispatchable promotion rate before the per-group `max_per_tick` cap kicks in. Verified empirically: in-process serial execution drove tick age to 11–17s; backgrounded forks restore sub-second cadence. Auditor's note: every dispatcher query carries an explicit `where('group', X)` filter covered by `idx_steps_state_group_dispatch_type`, block-uuid lookups are globally unique by construction, and `hasActiveSteps()` is index-covered EXISTS — no global table scans across the full tick lifecycle.
- [NEW FEATURE] **Dispatcher saturation persistence + flush cron.** Bumps `kraitebot/core` to 1.33.0 (new `steps_dispatcher_saturation` table + `kraite:cron-flush-dispatcher-saturation` command + `Kraite\Core\Models\StepsDispatcherSaturation`) and `brunocfalcao/step-dispatcher` to 1.11.14 (per-tick Redis counter writes inside `StepDispatcher::dispatch()`). New `Schedule::command('kraite:cron-flush-dispatcher-saturation')->everyMinute()->withoutOverlapping()->onOneServer()` entry pulls the previous minute's Redis counters for all 10 groups into the persistent table. Saturation % per minute = `ticks_capped_with_leftover / ticks_observed × 100`. Sustained near-100% across all groups = unambiguous signal to scale to more groups; sub-100% = the cap is not the bottleneck and adding groups will not help. Dashboard surface to be wired in admin.

## 1.32.0 - 2026-05-08

### Features

- [NEW FEATURE] **Bumps `kraitebot/core` to 1.32.0** — adds the stale-mark-price freshness gate inside token discovery (refuses to evaluate opens when the candidate's sidecar `mark_price_synced_at` is older than 30s), bundles the May-7 per-account / per-position / per-rung fan-out blast-radius hardening across six cron and orchestrator points, and the indexed `(relatable_type, relatable_id, state)` orphan / live-step lookup that replaces the unindexed JSON predicate. See core 1.32.0 changelog for the full surface.

### Tests

- [NEW FEATURE] `tests/Feature/Concerns/HasTokenDiscoveryStaleMarkPriceGateTest.php` — three pinned cases: stale sidecar drops a high-score candidate in favour of a fresh lower-score one, all-stale-candidates produces zero assignments (general daemon-stall shape), and null sidecar (legacy / brand-new / test fixture) is allowed through unchanged.

## 1.31.2 - 2026-05-08

### Fixes

- [BUG FIX] **B2 backup multipart upload retries.** Backblaze B2 returns a sporadic per-part `InternalError (server): internal incident` 500 during multipart uploads of the nightly ~1.1 GB DB dump. The AWS SDK's default retry policy (`legacy` mode, 3 attempts) wasn't enough — a single failed part aborts the entire upload and Spatie/laravel-backup reports the whole backup as failed. `config/filesystems.php` `b2` disk now declares `retries => ['mode' => 'adaptive', 'max_attempts' => 10]`. Adaptive mode adds client-side rate limiting on top of standard exponential backoff so a throttled B2 endpoint does not feed itself with a retry storm. Recorded failures: 2026-05-05 (×3), 2026-05-08 (×1).

### Tests

- [NEW FEATURE] `tests/Feature/Backup/B2DiskRetryConfigTest.php` pins the explicit `retries.mode='adaptive'` and `retries.max_attempts > 3` contract on the `b2` disk so a future config edit cannot silently re-expose production backups to the same transient failure shape. Includes a smoke pin that the disk still boots into a real `Aws\S3\S3Client` after the SDK consumes the retry config.

## 1.31.1 - 2026-05-07

### Fixes

- [BUG FIX] **Bumps `brunocfalcao/step-dispatcher` to 1.11.13** — pass-1 fall-through fix. When `priority='high'` Pending rows existed but none were dispatchable that tick (orphan with missing previous index, etc.), the dispatcher used to skip pass 2 entirely and the entire group's non-priority backlog starved. Production trigger today: group `eta` wedged for 11+ minutes behind one poison-pill `UpdatePositionStatusJob` (`index=9` in a block with no `index=8`). The group-stall watchdog notification fired; root cause traced and patched at the package layer. See step-dispatcher 1.11.13 changelog for the full surface.

### Improvements

- [IMPROVED] OPTIMIZE schedule narrowed from daily to Sundays-only (`weeklyOn(0, '03:00')` etc.). The first end-to-end daily run found `delta=0MB` across all five tables — the existing janitor + per-tick step-dispatcher pruning already keeps `.ibd` files compact, so a daily rebuild was wasted I/O. Sunday cadence preserves the safety net for any rare drift without the 4 a.m. churn.

### Tests

- [IMPROVED] `tests/Feature/Commands/CreatePositionsCommandOrphanRecoveryTest.php` adds a regression case pinning the indexed `(relatable_type, relatable_id, state)` tuple lookup (covered by `idx_p_steps_rel_state_idx`).

## 1.31.0 - 2026-05-07

### Features

- [NEW FEATURE] **Bumps `kraitebot/core` to 1.31.0** — adds `PurgePositionTrailJob` (clean-close breadcrumb janitor), `OptimizeBreadcrumbTablesCommand` (per-table maintenance-window OPTIMIZE TABLE), and `Kraite\Core\Support\MaintenanceMode` (cache-backed `steps:dispatch` pause/resume). See core 1.31.0 changelog for the full surface.
- [NEW FEATURE] **Per-table OPTIMIZE schedule (03:00 → 04:36, 24-min spacing).** Each schedule entry rebuilds one breadcrumb table inside its own maintenance window so the dispatcher catches up between slots instead of staying gated for the whole pass. Slot ordering is interleaved with the existing purge chain so each OPTIMIZE runs AFTER its corresponding purge: 03:00 model_logs, 03:24 api_snapshots, 03:48 api_request_logs (after 03:30 purge-old-data), 04:12 steps (after 04:00 steps:archive), 04:36 steps_archive (after 04:30 steps:purge).
- [NEW FEATURE] **`tests/Feature/Jobs/Atomic/Position/PurgePositionTrailJobTest.php`** — 6 cases pinning the janitor: dispatches on `closed` transition only (skips on `cancelled` / `failed` / unrelated attribute change), wipes every polymorphic breadcrumb tied to the position chain, preserves the position row + orders + the janitor's own running step row.
- [NEW FEATURE] **`tests/Feature/Support/MaintenanceModeTest.php`** — pins the pause/resume helper contract: default-not-paused, engages with reason+timestamp, clears on resume, honours custom TTL.

### Improvements

- [IMPROVED] **`steps:dispatch` schedule entry now honours `MaintenanceMode::isStepsDispatchPaused()`** via a `->skip()` callback. The pause is engaged automatically by `OptimizeBreadcrumbTablesCommand` for the duration of each per-table rebuild; cache-TTL safety net auto-resumes dispatch within minutes if anything crashes mid-pause.

## 1.30.0 - 2026-05-06

### Features

- [NEW FEATURE] **Bumps `kraitebot/core` to 1.30.0** — adds the position-structure-integrity audit (Scope 3 in `kraite:cron-check-drifts`), the new `SyncPositionQuantityFromExchangeJob` atomic, and the cross-exchange "already closed" handling in `ClosePositionAtomicallyJob`. See core 1.30.0 changelog for the full surface.
- [NEW FEATURE] **`tests/Feature/Cronjobs/CheckPositionStructureIntegrityTest.php`** — 7 cases pinning the new structure audit: happy path (zero notifications, flag stays true), missing TP, missing SL via CANCELLED status, incomplete limit count, non-active skip, throttle-once-per-position, multi-position fan-out (one notification per broken position).
- [NEW FEATURE] **Regression tests for the partial-fill safety net** — `tests/Feature/Cronjobs/SyncOrdersPartialFillSafetyNetTest.php`, `tests/Feature/Observers/OrderObserverPartialFillSyncTest.php`, and `tests/Feature/Jobs/Atomic/Position/` cover the LIMIT-PARTIALLY_FILLED → `SyncPositionQuantityFromExchangeJob` dispatch path on both the observer side and the `PrepareSyncOrdersJob` belt-and-suspenders side.
- [NEW FEATURE] **`tests/Feature/Concerns/Position/PositionBuildCloseOrderAttributesTest.php`** — pins the `Position::buildCloseOrderAttributes()` helper that backs `apiClose()` (sums every FILLED MARKET + LIMIT to derive close quantity from local DB truth, returns null when nothing is filled).

### Fixes

- [BUG FIX] **Existing `CheckDriftsCommandTest` cases now pass `--skip-structure-audit`** — three drift-scope tests intentionally fixture incomplete order sets to exercise Scope 1 / Scope 2 logic; without the flag those fixtures would also trip the new Scope 3. Functional behaviour of the drift / orphan tests is unchanged.
- [BUG FIX] **`AnalyseBscsJobTest` regression** — pins the BSCS recovery branch nulling `bscs_cooldown_until` so the next tick correctly reads "no cooldown" and silently no-ops instead of re-firing `market_regime_recovered` every minute.

### Improvements

- [IMPROVED] **`CLAUDE.md` rewritten as a production-environment briefing.** Replaces the stale "sandbox / dev environment" framing with the real model: this filesystem IS the live ingestion server, file edits are live changes, `git push` is backup-not-deploy, job-class edits require `horizon:terminate` to take effect. Adds a comprehensive destructive-operations checklist (DB, filesystem, git, process, exchange) so future sessions stop and ask before any non-reversible action — anchored on the 2026-05-01 `migrate:fresh --env=testing` incident that wiped the prod `kraite` DB because no `.env.testing` file existed.
- [IMPROVED] **`claude.sh` drops the `claude-chill` wrapper** — direct `claude` invocation; the wrapper added no value here and produced an extra layer of stdio buffering that interfered with the Telegram channel plugin's stdio MCP.

## 1.29.0 - 2026-05-06

### Fixes

- [BUG FIX] **Bumps `kraitebot/core` to 1.28.0** — drops the spurious MARKET reference_price drift check in `ActivatePositionJob::validateMarketOrders()` that kicked the cancel-cascade on legitimate sub-cent VWAP slippage and left Position #577 (TONUSDT) residual on Binance.
- [NEW FEATURE] **Regression test pinning the new behaviour** — `tests/Unit/Jobs/Atomic/Position/ActivatePositionJobMarketRetryTest.php::"absorbs MARKET fill-vs-reference price drift"` reproduces the 0.00012345 drift scenario, fails before the fix, passes after.

## 1.28.0 - 2026-05-06

### Features

- [NEW FEATURE] **Bumps `kraitebot/core` to 1.27.0** — adds the six Binance user-data daemon notification canonicals (`binance_user_data_account_connected`, `binance_user_data_account_init_failed`, `binance_user_data_listen_key_expired`, `binance_user_data_account_reaped`, `binance_user_data_memory_restart`, `binance_listen_key_keepalive_failed`) so the per-account user-data WebSocket daemon has live wiring for connect / init-fail / listenKey-expired / reap / memory-restart / keepalive-fail signals.

## 1.27.0 - 2026-05-05

### Fixes

- [BUG FIX] **Bumps `kraitebot/core` to 1.26.0** — `MarketRegimeNotificationsSeeder` activates `market_regime_critical` / `market_regime_recovered` / `market_regime_compute_stale` (closes silent-fail path where BSCS cooldown arming dropped the operator alert) + `StreamBinancePricesCommand` coalesces `exchange_symbol_prices` writes to once per 5s (~2500/sec → ~500/sec).
- [BUG FIX] **mysqldump `useSingleTransaction => true`.** `config/database.php` mysql connection now passes `'dump' => ['useSingleTransaction' => true]` through `spatie/db-dumper` so the backup runs in a REPEATABLE READ snapshot instead of `LOCK TABLES READ`. Eliminates the metadata-lock storm that froze every writer for the ~110s dump window each :07 hour-mark — the HH:08 slow-query bursts observed across 2026-05-05 traced 100% to backup-induced metadata locks (`Innodb_buffer_pool_wait_free` stayed at 0 throughout).

### Improvements

- [IMPROVED] **Slow-query notification threshold 10s → 45s.** `SLOW_QUERY_THRESHOLD_MS` raised in `.env` (10000 → 45000) and `config/kraite.php` default (5000 → 45000). Reduces notification noise on the long-tail of legitimately heavy reports / one-shot batch queries while still catching pathological waits.
- [DEPENDENCIES] **`aws/aws-sdk-php` 3.379.11 → 3.380.0.** Routine point-release bump.

## 1.26.0 - 2026-05-05

### Features

- [NEW FEATURE] **Backup-failure notification bridge.** New `App\Listeners\RouteBackupEventToSystemHealthAlert` (auto-discovered by Laravel 12's default event-discovery scan of `app/Listeners/`) routes `BackupHasFailed` (Critical), `CleanupHasFailed` / `UnhealthyBackupWasFound` (High) into the existing `system_health_alert` Pushover canonical with 1h per-signal throttle. Signal key now includes the exception's short class name so a transient auth blip cannot mask a quota / connectivity alert inside the throttle window. Closes the silent-failure gap that hid a B2 storage-cap exhaustion in `laravel.log` for hours with no operator alert.

### Fixes

- [BUG FIX] **B2 storage-cap exhaustion + silent failure root cause.** The hourly `backup:run --only-db --disable-notifications` schedule had been pushing 750 MB → ~1 GB SQL dumps to Backblaze B2 every hour with daily-only cleanup, which let 24 hours of dumps stack on B2 between prunes. Account-side storage cap on `kraite-backups` eventually rejected `UploadPart` with `AccessDenied: storage cap exceeded` and the `--disable-notifications` flag suppressed Spatie's built-in mail/slack notification dispatch — the failure landed in `laravel.log` only, with no operator paging. Manual B2 prune trimmed 13 → 3 backups (1.94 GB used).

### Improvements

- [IMPROVED] **Backup architecture: B2-only with rolling-3 retention.** `config/backup.php` `disks` reduced from `['local', 'b2']` to `['b2']` (B2 is the source of truth; ~12 GB of local dumps in `storage/app/private/kraite/` deleted). `config/kraite.php` `backup_tiers` collapsed to `hourly=3, daily=0, weekly=0` so `TieredStrategy` keeps a rolling window of the latest 3 snapshots — the 4th run evicts the oldest. `monitor_backups` watchdog cap dropped 50 GB → 5 GB to match the new working set.
- [IMPROVED] **Backup cadence: hourly → 3h, with chained immediate cleanup.** `routes/console.php` swaps `cron('7 * * * *')` for `cron('7 */3 * * *')` and chains `Artisan::call('backup:clean')` via `->then()` so retention is enforced on every successful run instead of waiting for the daily 03:45 prune. Standalone `backup:clean` daily entry removed.

### Operations

- [IMPROVED] **B2 storage cap headroom.** Bucket now sits at 1.94 GB / 3 backups under the new rolling-3 model. Operator action still required to bump the Backblaze account-side storage cap (set in B2 console → Caps & Alerts) for additional buffer above the rolling working set.

## 1.25.0 - 2026-05-04

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.25.0** — `exchange_symbol_prices` sidecar table cutover, `mark_price_synced_at` index dropped, `kraite:purge-old-data` command, `api_request_logs.payload` nulled on 200, lifecycle scenario tables.
- [IMPROVED] **Schedule consolidation.** `routes/console.php` swaps the standalone `kraite:purge-model-logs --duration=30` daily entry for the new combined `kraite:purge-old-data --api-request-logs-days=5 --model-logs-days=30` at 03:30 — one schedule entry now keeps both high-volume operational log tables bounded.

### Operations

- [IMPROVED] **MySQL `innodb_buffer_pool_size` 2G → 10G.** Production `/etc/mysql/mysql.conf.d/mysqld.cnf` bumped to give the post-purge `api_request_logs` working set + the rest of the hot tables enough room to fit. Server has 30 GB RAM with 18 GB free pre-bump, so headroom remains.
- [IMPROVED] **`api_request_logs` reclaim.** One-shot backfill (475k rows nulled `payload` for 200-class historical rows) + `OPTIMIZE TABLE` reclaimed 12.2 GB on disk (14.3 GB → 2.1 GB).

## 1.24.0 - 2026-05-04

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.24.0** — disaster-recovery true-up (4 new phases on `kraite:recover-positions`), OrderObserver dispatch dedupe race fix at 4 sites via `Position::lockForUpdate()`, `position_opened` canonical state-drift resolved.

### Tests

- [NEW FEATURE] **`OrderObserverDispatchDedupeRaceTest`** (5 cases) — source-level pins that all 4 dispatch sites (close, replacement, WAP, user-data manual-close detection) wrap their SELECT-then-INSERT in a DB::transaction with Position lockForUpdate; serial dedupe regression pin (4 cancelled-order observer fires collapse to 1 step).
- [NEW FEATURE] **`RecoverPositionsCommandHardeningTest`** (11 cases) — source-level pins for the 5 new phase helpers; functional pins for Phase 2 (phantom close-detection with safety guard for empty exchange snapshot); functional pins for Phase 4 (stuck opening-status reset to active when exchange shows it / closed when not); operational guard pin that allow_opening_positions is restored after the run via try/finally.

## 1.23.0 - 2026-05-04

### Features

- [NEW FEATURE] **Telegram channel wired into ingestion.** `composer require laravel-notification-channels/telegram`; `services.telegram-bot-api.token` reads `TELEGRAM_BOT_TOKEN`. Two new env keys (`TELEGRAM_BOT_TOKEN`, `ADMIN_USER_TELEGRAM_CHAT_ID`). Per-user opt-in via `notification_channels` array including `'telegram'` + a populated `telegram_chat_id`.

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.23.0** — Telegram channel + UserObserver/KraiteObserver auto-welcome + disk-pressure health check (#12).

### Tests

- [NEW FEATURE] **`AlertNotificationTelegramChannelTest`** (8 cases) — channel string resolution, `via()` inclusion, `toTelegram()` HTML payload + chat_id, fallback chain (telegram→pushover→message), HTML escape safety, `routeNotificationForTelegram` null/value paths.
- [NEW FEATURE] **`UserObserverTelegramWelcomeTest`** (6 cases) — welcome fires on creation with chat_id, fires on null→set transition, no-fire on unrelated update, no-fire on chat_id cleared, silent skip when token missing, error containment swallows Telegram API 401.
- [NEW FEATURE] **`KraiteObserverTelegramWelcomeTest`** (5 cases) — engine-side mirror of the user observer test suite, watches `kraite.admin_telegram_chat_id`.
- [NEW FEATURE] **`CheckSystemHealthDiskPressureTest`** (2 cases) — pin disk_pressure_low signal not firing under 15% threshold (skipped if test host itself is tight on disk) + source-level pin that the check is wired into the runner array.

## 1.22.0 - 2026-05-04

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.22.0** — `ProcessUserDataEventJob::applyToOrderModel` no longer overwrites `orders.quantity` from WS pushes (fixes the ONDO #271 quantity-drift cancel-cascade pattern).
- [IMPROVED] **New `ProcessUserDataEventJobQuantityFreezeTest` (4 cases).** Pins the fix: PARTIALLY_FILLED cumulative-filled corruption, FILLED no-op, late out-of-order PARTIALLY_FILLED regression (the ONDO #271 reproduction), and normal NEW→FILLED happy path.

## 1.21.0 - 2026-05-04

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.21.0** — `CancelPositionOpenOrdersJob` reshaped to per-order on every exchange; symbol-wide collateral damage class eliminated by construction.
- [IMPROVED] **New `CancelPositionOpenOrdersPerOrderTest` (4 cases).** Pins per-order behaviour, cross-position isolation (the smoking-gun reproduction with two cohabiting ETCUSDT positions on the same account), `reference_status='CANCELLED'` intent-flag bump, and ghost / terminal / algo skip.
- [IMPROVED] **`SendsNotificationsTest` fixture leak fix.** The two "no-op save doesn't re-fire delisting" cases moved `Notification::fake()` BEFORE the test creates the `BINANCE_NO_CHANGE` / `BYBIT_NO_CHANGE` `ExchangeSymbol` row. Previously the discovery save fired `token_delisting` through the real Notification facade BEFORE `fake()` was called, and (because no `.env.testing` existed) tests fell back to `.env`'s production Pushover credentials — every full-suite run leaked one BINANCE + one BYBIT alert to Bruno's phone.
- [IMPROVED] **`.env.testing` defense-in-depth created.** Full copy of `.env` with the leak-prone keys overridden: `APP_ENV=testing`, `DB_DATABASE=kraite_tests` (defends against the 2026-05-01 `migrate:fresh --env=testing` wipe pattern), `MAIL_MAILER=array`, ZeptoMail key stubbed, `NOTIFICATIONS_ENABLED=false`, every `*_PUSHOVER_*` key stubbed (admin + every trader + delivery groups), `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`. Belt + braces with the existing `phpunit.xml` `<env>` overrides — `.env.testing` covers the gap when artisan commands run with `--env=testing` outside the pest runner.

## 1.20.1 - 2026-05-04

### Improvements

- [IMPROVED] **`kraite:cron-check-system-health` cadence relaxed from every 5 minutes to every 7 minutes (`*/7 * * * *`).** Reduces background load from the orphan reconciliation pass (per-account exchange snapshot fetch + classification) without weakening the safety net — drift watchdog still runs every 5 minutes as the faster alert-only monitor; Health follows up with the auto-cancel on its next tick.

## 1.20.0 - 2026-05-03

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.20.0** — drift watchdog pivoted to alert-only with surgical silent self-heal; `ActivatePositionJob` MARKET race-tolerant poll-with-timeout; reverts of the pre-flight `apiSync` and `PreparePositionReplacementJob` dedupe scan that triggered the 192-second `exchange_symbols` lock-wait.
- [IMPROVED] **`CheckDriftsCommandTest` rewritten against the alert-only contract.** Nine cases survive (Scope-3 cases removed with the Scope-3 revert). The four cases that asserted dispatch / inline-DB-cancel behaviour now assert no dispatch, no DB writes, single notification per parent — covering ghost-only, real-only, mixed-orphan, and failed-parent variants.
- [IMPROVED] **New `ActivatePositionJobMarketRetryTest` pins the race-tolerant MARKET validation.** Three TDD cases: happy-path (already FILLED), exhausted retry budget (stays non-FILLED, throws), mid-poll promotion (flips to FILLED between iterations).

## 1.19.0 - 2026-05-03

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.19.0** — WebSocket idle-watchdog data-frame discipline + drift-spotter pre-flight sync + richer `position_orphan_orders_detected` body (exchange + client ids).
- [IMPROVED] **`OrphanReconciler::reconcile` in-flight guard regression test (carry-over from 1.18.1).** Two new unit cases pin the new `hasInflightPositions` parameter — when set, order-orphan classification is skipped while position-orphan classification still runs. Prevents the false-positive on ETCUSDT/LONG that fired during a position's mid-creation order placement.

## 1.18.0 - 2026-05-03

### Features

- [NEW FEATURE] **Database backups via `spatie/laravel-backup` + Backblaze B2.** Hourly snapshots (off the conclude:30 / refresh:15 / bscs bursts at minute 7) of the `kraite` MySQL database, gzipped + AES-256 zip-encrypted via `BACKUP_ARCHIVE_PASSWORD`, written to two destinations: `local` (storage/app/private/kraite/) for fast restore + `b2` (private Backblaze B2 bucket `kraite-backups` in `eu-central-003`) for off-host durability. `backup:clean` runs daily at 03:45, `backup:monitor` every 6h.
- [NEW FEATURE] **`App\Support\Backup\TieredStrategy` — corruption-resilient retention.** Custom spatie cleanup strategy implementing grandfather-father-son tiering: keep the newest N hourly + N daily + N weekly snapshots (configurable via `kraite.backup_tiers.*`, defaults 3/3/3). Each tier skips weeks/days already represented in the lower tier so the retained set covers progressively older time windows. An undetected corruption window has to span multiple weeks before the grandfather tier rolls forward past it. 8 unit tests pin the bucketing rules.

### Improvements

- [IMPROVED] **Supervisor file-descriptor ceiling raised to 65536.** The three kraite supervisor configs (`kraite-ingestion-binance-prices`, `...binance-user-data`, `...horizon`) now wrap the program command with `bash -c "ulimit -n 65536; exec php ..."`. Prevents "Too many open files" cascades during burst exception rendering. Other supervisor programs on the host (eduka, friday, juty, hyperframes, jarvis, codiant) untouched.
- [IMPROVED] **Bumps `kraitebot/core` to 1.18.0** — `NotificationService` failure containment + drop dead `accounts.margin_ratio_threshold_to_notify` column.

### Dependencies

- [DEPENDENCIES] Adds `spatie/laravel-backup ^10.2` + `league/flysystem-aws-s3-v3 ^3.32`.

## 1.17.0 - 2026-05-03

### Improvements

- [IMPROVED] **Bumps `kraitebot/core` to 1.17.0** — `(float)` → BCMath migration packs 1-12 + nano-pack (107 net casts removed) + `indicators_synced_at` skip-stamp fix.

### Features

- [NEW FEATURE] **13 new tests** added inline alongside the migration:
  - `tests/Feature/Concerns/Position/PositionDailyVariationAccessorTest.php` — 7 tests pinning `Position::daily_variation_percentage` across positive / negative / zero-open / no-row / fractional-precision / null-symbol / no-indicator cases.
  - `tests/Unit/Indicators/PriceVolatilityIndicatorTest.php` — 5 tests pinning the `((high - low) / close) × 100` formula including high-precision crypto values + missing-field guards.
  - `tests/Feature/Jobs/ConcludeSymbolDirectionAtTimeframeJobTest.php` — 1 regression test confirming the `same_indicator_data` skip branch stamps `indicators_synced_at`.

## 1.16.0 - 2026-05-03

### Features

- [NEW FEATURE] **19 new tests** under `tests/Unit/Models/AccountBalanceForTradingTest.php`, `tests/Unit/Support/Health/OrphanReconcilerTest.php`, and `tests/Feature/Health/CheckOrphanCleanupTest.php` pinning the orphan-cleanup behaviour matrix, the Account balance-for-trading helper, and the watchdog integration.

### Improvements

- [IMPROVED] Bumps `kraitebot/core` to v1.16.0 (per-account orphan-handling flags + `Account::balanceForTrading()` + `OrphanReconciler` pure classifier + orphan check #11 in `kraite:cron-check-system-health` + `indicators_synced_at` semantic correction + `NotificationMessageBuilder` match arm fix).

## 1.15.0 - 2026-05-03

### Features

- [NEW FEATURE] **21 unit tests for the new token-scoring helpers** under `tests/Unit/Support/TokenScoring/`. Three Pest test files pin the contracts of `LogElasticityScorer` (zero-input handling, monotonic growth, sign-irrelevance, log compression vs raw multiplication), `CorrelationStabilityWeight` (graceful-degrade defaults, monotonic decrease, clamp behaviour), and `BatchDiversificationPenalty` (empty-batch returns 1.0, threshold-driven trigger, opposite-sign immunity, closest-match wins).

### Improvements

- [IMPROVED] Bumps `kraitebot/core` to v1.15.0 (TokenScoring helpers + selection scoring rewrite + `btc_correlation_stability` column + `nextPendingLimitOrderPrice` rung-selection bugfix).

## 1.14.0 - 2026-05-03

### Features

- [NEW FEATURE] **Schedule entries for `kraite:cron-check-binance-listen-keys-stale` and `kraite:cron-check-system-health`.** Both at every-5-minute cadence with `withoutOverlapping`. Listen-key staleness watchdog catches accounts whose daemon never initialised a listen-key row, or whose keepalive cron has stalled, well before Binance's 60-min hard expiry. Unified system-health watchdog runs the consolidated check matrix replacing the prior narrow `kraite:cron-check-stale-data`.
- [NEW FEATURE] **Pest test `tests/Feature/Concerns/HasTokenDiscoverySymbolOverrideTest.php`.** Pins the contract for the new test-only `symbol_override` god-mode at priority 0 of `HasTokenDiscovery::assignTokensToPositions`. Covers: forces specific symbol when account_id + pair resolve, falls back silently when config is null, when account_id mismatches, when symbol unresolvable on the exchange, when symbol's direction does not match the slot's direction, when symbol is already in an active position on this account.

### Improvements

- [IMPROVED] **`kraite:cron-sync-orders` cadence relaxed from `everyMinute` to `everyFiveMinutes`.** Push-based user-data WS is now production primary for fill detection, in-place-modify drift, cancellations, expiries, and algo lifecycle (full execution-type allowlist live). Polling exists only as a 5-minute safety net for the rare WS-frame-loss / reconnect-race drift case. Cadence history: every minute (pre-2026-04-30) → 15 min during shadow-mode rollout → 5 min on push-primary cutover (2026-05-03).
- [IMPROVED] **Production `USER_DATA_STREAM_BINANCE_DISPATCHED_EXECUTIONS` env extended to 8 exec types.** Final allowlist: `TRADE, AMENDMENT, CANCELED, EXPIRED, ALGO_NEW, ALGO_CANCELED, ALGO_EXPIRED, ALGO_FILLED`. Covers DCA fills + TP fills (TRADE), in-place modifies (AMENDMENT), cancellations (CANCELED), expiries (EXPIRED), algo placement / cancel / expire (ALGO_NEW / ALGO_CANCELED / ALGO_EXPIRED), and SL trigger fires (ALGO_FILLED). `NEW`, `REJECTED`, and `CALCULATED` deliberately stay off — `NEW` would create defensive drift-detection noise on every placement ack, `REJECTED` is already caught synchronously at `apiPlace`, and `CALCULATED` (liquidations) is explicitly out of scope (operator concern, not bot concern).
- [IMPROVED] Bumps `kraitebot/core` to v1.14.0 (test-only symbol override at priority 0 of token discovery + manual-close detection branch in `ProcessUserDataEventJob` + per-execution-type dispatch allowlist + `UserDataStreamEvent::reduceOnly` + synthesized `ALGO_<status>` exec types for `ALGO_UPDATE` frames + `kraite:cron-check-binance-listen-keys-stale` + `kraite:cron-check-system-health` + retired `CheckStaleDataCommand`).

### Removals

- [IMPROVED] **`kraite:cron-check-stale-data` schedule entry retired.** Functionality folded into the unified `kraite:cron-check-system-health` watchdog.

## 1.13.0 - 2026-05-02

### Features

- [NEW FEATURE] **Pest tests for Bitget hedge / one-way mode contracts.** New `tests/Unit/Support/ApiDataMappers/Bitget/BitgetHedgeOneWayModeTest.php` pins the request-payload contracts that diverge between hedge (`posSide`+`tradeSide`+`holdSide`) and one-way (`reduceOnly=YES` on close intent, `holdSide` omitted, `posSide` omitted) modes across the 7 retrofitted mappers, plus the response-keying contract on `MapsPositionsQuery` (hedge → `symbol:LONG/SHORT`, one-way → `symbol:BOTH`).
- [NEW FEATURE] **Pest tests for Bitget position-mode auto-flip.** New `tests/Integration/ExceptionHandlers/BitgetPositionModeAutoFlipTest.php` mirrors the existing Binance auto-flip suite for Bitget's `40774` mismatch code: hedge→one-way flip, one-way→hedge flip, audit trail (Log::warning + Account::modelLog), no false-positive on unrelated exceptions.
- [NEW FEATURE] **`bitgetPositionSideMismatch` factory on `tests/Support/ResponseException.php`.** Emits a Bitget-shaped `400` with vendor code `"40774"` and the canonical "order type for unilateral position must also be the unilateral position type" message — the exact shape Bitget returns when the payload assumes the wrong position mode.

### Improvements

- [IMPROVED] **Horizon supervisor process counts tuned.** Per-queue: `positions=5`, `orders=5`, `cronjobs=5`, `indicators=20`, `priority=8`, `user-data-stream=5`, `<hostname>=1`. Applied to all 4 environments (`local`, `ingestion`, `worker1`, `worker2`).
- [IMPROVED] **Sandbox-environment safety rule in `CLAUDE.md`.** New top-level section explicitly forbids destructive operations (migrate:fresh, DROP, TRUNCATE, rm -rf, force-push, etc.) without per-action approval, even when the environment is labelled "sandbox" or "dev". Driven by the 2026-05-01 incident where `migrate:fresh --env=testing --force` fell back to `.env`'s `DB_DATABASE=kraite` (no `.env.testing` present) and wiped the production-app DB. Recovery cost a `--override` re-run of the recover-positions command across all accounts.
- [IMPROVED] Bumps `kraitebot/core` to v1.13.0 (`kraite:recover-positions` disaster-recovery command + Bitget hedge/one-way support across 7 mappers + Bitget code "40774" auto-flip + 7-gap daemon hardening incl. reaper + dead-letter fallback + heartbeat persistence + listen-key rotation detection + memory/reconnect-storm watchdogs).

## 1.12.0 - 2026-05-01

### Features

- [NEW FEATURE] **Supervisor program `kraite-ingestion-binance-user-data`.** Hosts the new `kraite:stream-binance-user-data` daemon. Same supervisor pattern as the price daemon (autostart=true / autorestart=true), separate stdout log at `storage/logs/binance-user-data.log`. One process, N concurrent WebSockets via the `BaseWebsocketClient::handleCallbackAsync` entry point.
- [NEW FEATURE] **Dedicated `user-data-stream` Horizon supervisor** (8 processes, registered on both `local` and `ingestion` env arrays). Isolates the WS-driven Step throughput from `positions` / `default` / `cronjobs` so cron-driven step bursts never block real-time event reactivity. Bruno's framing: WS daemon = heart, step dispatcher = brain — queue isolation keeps the heart's latency floor independent of the brain's load.
- [NEW FEATURE] **`user-data` log channel** at `storage/logs/user-data.log`, daily rotation, 14-day retention. Daemon WS lifecycle + per-frame one-liners go here, isolated from `jobs.log`.
- [NEW FEATURE] **Schedule entry `kraite:cron-refresh-binance-listen-keys`** at every-minute cadence with `withoutOverlapping`. Listen-key keepalive surface for the user-data daemon — runs regardless of cooldown gate (operational maintenance, not new-work creation). Three consecutive failures on a single account fire `binance_listen_key_keepalive_failed` Pushover.

### Improvements

- [IMPROVED] Bumps `kraitebot/core` to v1.12.0 (Binance user-data-stream daemon + `api_data_stream` audit table + `binance_listen_keys` + `MapsUserDataStream` Binance mapper + per-execution-type dispatch allowlist + `apiSyncDefault` shadow-validation override).
- [IMPROVED] Bumps `brunocfalcao/step-dispatcher` to v1.11.11 (queue allowlist now includes `user-data-stream` so the new Steps land on the dedicated queue instead of falling back to `default`).

## 1.11.0 - 2026-04-30

### Improvements

- [IMPROVED] **Schedule swap: `kraite:watch-price-stream` → `kraite:cron-check-stale-data`.** The retired watchdog auto-restarted the price-stream daemon in a 2-day silent loop during the 2026-04-23 Binance WebSocket URL deprecation. Replaced by an alert-only check that fires `price_data_stale` Pushover when any non-delisted enabled exchange_symbol has `mark_price_synced_at` older than 1 minute. The daemon's internal idle watchdog still handles transient socket stalls; this layer catches unrecoverable cases that need human attention.
- [IMPROVED] Bumps `kraitebot/core` to v1.11.0 (Binance WS migration restores price flow; new top-up coin curated list + sysadmin Coins tab; ops alert canonicals; delisting helpers on ExchangeSymbol).

## 1.10.0 - 2026-04-29

### Features

- [NEW FEATURE] **Renewal cron scheduled** — bumps `kraitebot/core` to v1.10.0. Schedule entry switched from `kraite:cron-deduct-subscriptions` (retired) to `kraite:cron-renew-subscriptions`. Same midnight cadence (`dailyAt('00:00')`, `withoutOverlapping`, `onOneServer`). The new command processes monthly renewals, fires 7-day low-balance pre-warnings, and fires 2-day trial-ending pre-warnings in one pass.

### Tests

- [NEW FEATURE] `tests/Feature/Billing/RenewSubscriptionsCommandTest.php` — 16 cases covering the new cron's renewal-due processing, anchor push, paused/trial/inactive skips, low-balance pre-warning at renews_at-7d, trial-ending pre-warning at trial_end-2d, closing-mode notification on insufficient funds, dry-run, and live-rate read paths.
- [IMPROVED] `tests/Unit/Models/UserBillingTest.php` rewritten — pause/resume, renewal-anchored `isInClosingMode`, `subscriptionCoversNextRenewal`, `renewalShortfallUsdt`. 19 cases.
- [IMPROVED] `tests/Unit/Support/Billing/WalletTest.php` rewritten — `runRenewal` happy path, explicit anchor, insufficient-funds rollback, no-tier guard. Bonus-ladder cases dropped (helper killed).
- [IMPROVED] `tests/Feature/Billing/WalletLedgerContractTest.php` rewritten — ledger contract for credit/debit/runRenewal/admin overrides + new prorate refund. Bonus-row cases dropped.
- [IMPROVED] `tests/Feature/Billing/HasTradingGuardsBillingTest.php` rewritten — trading-guard integration with renewal-anchored closing-mode + paused users + Starter active-account gate. 7 cases.
- [BUG FIX] `tests/Feature/Billing/DeductSubscriptionsCommandTest.php` removed — covered the retired daily-debit command.

## 1.9.1 - 2026-04-29

### Fixes

- [BUG FIX] Bumps `kraitebot/core` to v1.9.1 — Bitget WAP no longer fails on `modify-tpsl-order`. Live verified on production position #792 (APE/USDT LONG, account #4 Main BitGet): TP repriced from $0.17210 → $0.16230 and quantity expanded from 182.30 → 546.90 to cover the full post-DCA-fill position.

### Tests

- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Order/Bitget/CalculateWapPlacePosTpslTest.php` — 4 cases pinning the structural fix: `findSiblingStopLossOrder` returns the SL leg, returns null when missing, source uses `placePosTpsl` not `apiModifyTpsl`, source uses `preparePlacePosTpslProperties` (atomic both-leg request).

## 1.9.0 - 2026-04-29

### Features

- [NEW FEATURE] **Daily subscription deduction scheduled** — bumps `kraitebot/core` to v1.9.0. New `Schedule::command('kraite:cron-deduct-subscriptions')->dailyAt('00:00')->withoutOverlapping()->onOneServer()` in `routes/console.php` wires the per-user daily wallet debit into the scheduler. Skips trial-active users; surfaces closing-mode + low-balance notifications via the existing canonical pipeline.

### Tests

- [NEW FEATURE] `tests/Unit/Support/Billing/WalletTest.php` — 7 cases for atomic credit/debit, ledger contract, insufficient-funds, bonus ladder.
- [NEW FEATURE] `tests/Unit/Models/UserBillingTest.php` — 12 cases pinning `isTrialActive`, `isTrialExpired`, `walletRunwayDays`, `isInClosingMode`, and the `trial_days_override` per-user override semantics.
- [NEW FEATURE] `tests/Feature/Billing/DeductSubscriptionsCommandTest.php` — 11 cases covering the cron's debit / trial-skip / closing-mode / low-balance / live-rate paths.
- [NEW FEATURE] `tests/Feature/Billing/HasTradingGuardsBillingTest.php` — 5 cases pinning the trading-guard billing gate (closing-mode block, Starter active-account restriction, Unlimited free pass).
- [NEW FEATURE] `tests/Feature/Billing/WalletLedgerContractTest.php` — 8 cases pinning the audit-log contract (every credit/debit/bonus writes a row with correct type, signed amount, balance_after, description, meta).

## 1.8.9 - 2026-04-28

### Features

- [NEW FEATURE] **Drift Spotter scheduled** — bumps `kraitebot/core` to v1.8.0. New `Schedule::command('kraite:cron-check-drifts')->everyFiveMinutes()->withoutOverlapping()` in `routes/console.php` wires the proactive 5-minute drift audit into the host scheduler (sits inside the cooldown gate so it shares the same kill-switch as the reactive sync).
- [NEW FEATURE] **Archive purge scheduled** — bumps `brunocfalcao/step-dispatcher` to v1.11.7. New `Schedule::command('steps:purge --only-archive --days=5')->dailyAt('04:30')` runs 30 minutes after the daily archive, trimming `steps_archive` to a 5-day retention window. Live `steps` table and ticks remain untouched.

### Tests

- [NEW FEATURE] `tests/Feature/Cronjobs/CheckDriftsCommandTest.php` — 9 cases pinning the spotter command behaviour (drift / orphan / ghost / mixed / quiet window / mid-flight skip / status passthrough).
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Order/CancelSingleAlgoOrderJobStartOrFailTest.php` — 12 cases covering Binance + Bitget startOrFail guards plus Phase 2 idempotent flag.
- [NEW FEATURE] `tests/Unit/Jobs/Lifecycles/Position/PrepareCancelOrphanOrdersJobTest.php` — 5 cases pinning the orphan-cancel lifecycle wrapper.
- [NEW FEATURE] `tests/Unit/Support/ApiClients/Bybit/BybitApiClientAuthHeadersTest.php` — pins the public-vs-signed header split that fixed the 02:05 retCode 10006.
- [NEW FEATURE] `tests/Unit/Support/ApiExceptionHandlers/IgnorableOrderNotFoundCodesTest.php` — pins Bitget + Bybit ignorable-code expansions for cancel-of-missing-order.
- [NEW FEATURE] `tests/Unit/Support/Drift/DriftCheckServiceTest.php` — 10 cases pinning the drift-comparison algorithm (synced / drift / db_only / exchange_only / transient with mid-flight suppression, 0.1% tolerance band, type aliasing, weighted-average entry recomputation).

## 1.8.8 - 2026-04-27

### Fixes

- [BUG FIX] Bumps `kraitebot/core` to v1.7.11 — fixes silent WAP failure on one-way mode accounts. Affected Karine Esnault / Binance Only Account on 2026-04-27 (JTO/USDT pos 289 — repaired manually).

### Tests

- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Order/CalculateWapBuildPositionKeyTest.php` — 8 cases pinning `buildPositionKey()` for hedge/one-way × LONG/SHORT × base/Binance/Bitget variant.
- [NEW FEATURE] `tests/Feature/WapWorkflow/CalculateWapSnapshotLookupTest.php` — 6 cases pinning the full ApiSnapshot roundtrip: WAP-built key MUST match `MapsPositionsQuery` snapshot key for Binance hedge LONG, Binance one-way LONG/SHORT, Bitget hedge SHORT, Bitget one-way LONG, plus a regression sanity assertion documenting why the bug bit.

## 1.8.7 - 2026-04-27

### Features

- [NEW FEATURE] **BSCS Phase 2.1 complete** — bumps `kraitebot/core` to v1.7.10. All three sub-phases shipped same day: 2.1A cascade detector, 2.1B portfolio shape + 3-tier staleness, 2.1C Fragile + crowding margin multipliers.
- [NEW FEATURE] New schedule `kraite:cron-detect-market-shock` every minute (cascade safety net).
- [NEW FEATURE] New schedule `kraite:cron-fetch-klines --reference-set --canonical=binance --timeframe=15m` every 15 minutes (feeds the cascade detector).

### Tests

- [NEW FEATURE] 6 new test files covering the entire Phase 2.1 surface: `FetchKlinesReferenceSetTest`, `DetectMarketShockJobTest`, `MarketShockCircuitBreakerTest`, `DirectionalBookRiskTest`, `FragileMarginMultiplierTest`, `CrowdingMultiplierTest`.
- [IMPROVED] `BlackSwanIndexTest` extended with 7 new cases for `portfolioRisk()`, `staleness()` 3-tier transitions, and StaleHard fail-open.
- [IMPROVED] `HasTradingGuardsBscsGateTest` fixture pinned to fresh `synced_at` so cooldown gate cases stay orthogonal from staleness behaviour.
- [IMPROVED] `PreparePositionDataTpSlSnapshotTest` extended with 2 cases pinning the Phase 2.1C size-adaptation imports + multiplier wire-in.

## 1.8.6 - 2026-04-27

### Fixes

- [BUG FIX] Bumps `kraitebot/core` to v1.7.6 — `BlackSwanIndex::ageSeconds()` returns the absolute diff (was negative due to Carbon's signed `diffInSeconds`).

## 1.8.5 - 2026-04-27

### Features

- [NEW FEATURE] **BSCS Phase 2 — system cooldown gate live.** Bumps `kraitebot/core` to v1.7.5. When the Black Swan Composite Score reaches the configured threshold (default 80), `kraite:cron-create-positions` is paused for 24h via `HasTradingGuards::canOpenPositions()`. Existing positions untouched. Operator override (`bscs_override_until`) bypasses the gate.
- [NEW FEATURE] New schedule: `kraite:cron-analyse-bscs` at `:55` past the hour (single-server). Reads the latest BSCS score and arms / re-arms / releases the system cooldown.
- [NEW FEATURE] `BlackSwanIndex::current()->toArray()` ready for admin dashboard consumption (full payload: score, band, cooldown / override state, freshness, sub-signal grid from latest snapshot).

### Tests

- [NEW FEATURE] `tests/Unit/Support/MarketRegime/BlackSwanIndexTest` (10 cases).
- [NEW FEATURE] `tests/Feature/MarketRegime/AnalyseBscsJobTest` (7 cases).
- [NEW FEATURE] `tests/Feature/HasTradingGuardsBscsGateTest` (5 cases).

## 1.8.4 - 2026-04-27

### Fixes

- [BUG FIX] Bumps `kraitebot/core` to v1.7.4 — closes the BSCS Phase 1 issues uncovered during first-day observation: `range_blowout` formula corrected to per-day comparison (was over-firing on per-hour-max), Phase 1 invariant pinned (`bscs_block_active=false` regardless of score), freshness window default bumped 5400→6900s.

### Tests

- [NEW FEATURE] `tests/Feature/MarketRegime/ComputeMarketRegimeJobTest` — 5 cases pinning the Phase 1 read-only contract for the BSCS compute job (snapshot write, kraite denormalisation, Phase 1 invariant on `bscs_block_active`, insufficient-history skip, audit trail).

## 1.8.3 - 2026-04-27

### Fixes

- [BUG FIX] Bumps `kraitebot/core` to v1.7.3 — closes TypeError in `ExchangeSymbolObserver::decimalsEqual()` triggered by admin gap saves (admin controller `(float)` casts gap input; observer wrapper was strictly typed `?string`). Every admin save that changed a gap was silently failing propagation. Regression test added in `ExchangeSymbolGapPropagationTest`.

## 1.8.2 - 2026-04-27

### Features

- [NEW FEATURE] `ExchangeSymbolObserver` now propagates ladder-gap percentages (`percentage_gap_long`, `percentage_gap_short`) Binance→siblings (asymmetric) AND the new `backtesting_review_status` admin-state symmetrically alongside the boolean approval gate (core v1.7.2). Operator-driven changes on a Binance row now fan out to every sibling exchange row automatically.

### Fixes

- [BUG FIX] SL placement (Binance + Bitget) now falls back to the account default when the position's `stop_market_percentage` snapshot is null — closes the half-baked-deploy hazard where a worker running new placement code against a position prepared by old code would silently skip SL placement.

### Tests

- [NEW FEATURE] `tests/Unit/Observers/ExchangeSymbolGapPropagationTest` — 5 cases: Binance gap_long/gap_short propagation, combined edit, asymmetric (Bitget no-propagate), idempotent re-save no-op.
- [NEW FEATURE] `tests/Unit/Observers/ExchangeSymbolBacktestingReviewPropagationTest` — 6 cases pinning the symmetric propagation of `was_backtesting_approved` + `backtesting_review_status` from any source row to siblings.
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Order/PlaceStopLossOrderFallbackTest` — 6 cases pinning the snapshot-first / live-resolve fallback chain in `resolveStopLossPercentage()`.

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.7.2.

## 1.8.1 - 2026-04-27

### Features

- [NEW FEATURE] **Per-symbol TP/SL overrides** (core v1.7.1). Three new migrations: `2026_04_27_120000_add_tpsl_overrides_to_exchange_symbols` (`profit_percentage` + `stop_market_percentage` decimals, both nullable), `2026_04_27_120100_add_tpsl_overrides_to_accounts` (`override_tp` + `override_sl` boolean kill-switches), `2026_04_27_120200_add_stop_market_percentage_to_positions` (SL snapshot column, parallel to existing `profit_percentage` snapshot). Position `PreparePositionDataJob` resolves both via `TpSlResolver` and snapshots them; `PlaceStopLossOrderJob` (Binance) + Bitget `PlacePositionTpslJob` read the snapshot.
- [NEW FEATURE] Backfilled `positions.stop_market_percentage` for 26 in-flight active positions from `accounts.stop_market_initial_percentage` (Binance accounts 1+5: 10 each, BitGet account 4: 6) so the new gates don't fail open positions opened before the migration.

### Tests

- [NEW FEATURE] `tests/Unit/Support/TpSlResolverTest` — 8 cases covering the resolution matrix (symbol-NULL, empty-string, value × override true/false × decimal precision preservation).
- [NEW FEATURE] `tests/Unit/Observers/ExchangeSymbolTpSlPropagationTest` — 7 cases pinning the Binance→siblings asymmetric fan-out: TP edit, SL edit, combined edit, non-Binance no-propagate, NULL clear, idempotent re-save guard, orphan rows skip.
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Position/PreparePositionDataTpSlSnapshotTest` — 5 source-string regression assertions pinning the resolver wire-up + snapshot writes.

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.7.1 to pull in TP/SL override resolver + observer + scoring recalibration + new purge-failed-backtested-klines command.

## 1.8.0 - 2026-04-27

### Features

- [NEW FEATURE] Hourly schedule for `kraite:cron-compute-market-regime` at `:50` — the BSCS Phase 1 telemetry cron from `kraitebot/core` v1.7.0. Reads BTC + 4 reference alts klines, persists snapshot, denormalises onto `kraite` singleton. No trading-flow side effects.
- [NEW FEATURE] `database/migrations/2026_04_26_220000_drop_was_backtracking_analysed_from_exchange_symbols` — drops the in-flight `was_backtracking_analysed` column (replaced by the new `was_backtesting_approved` operator-driven flag in core v1.7.0).

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.7.0 to pull in BSCS Phase 1 telemetry, Bitget closing_price recording fix, and the `was_backtesting_approved` per-token trading gate with cross-exchange propagation observer.
- [IMPROVED] `routes/console.php` — `kraite:disable-volatile-tokens` hourly schedule commented out. Operator now controls per-token tradability via the `was_backtesting_approved` flag (set in admin UI or DB after reviewing backtest results); the deny-list sweep was made redundant by the per-token gate. Command itself remains callable manually if the deny-list approach is reintroduced later.

### Tests

- [NEW FEATURE] `tests/Unit/Support/MarketRegime/RegimeCalculatorTest` — 11 cases pinning each of the five BSCS sub-signal formulas + threshold mapping + composite score arithmetic + `RegimeBand` boundary behaviour.
- [NEW FEATURE] `tests/Unit/Support/ApiDataMappers/Bitget/BitgetTradesNormalizationTest` — 7 cases pinning the `side` flip on `tradeSide=close` and the oldest-first reverse the mapper now applies (regression guard for the SFPUSDT mis-recorded `closing_price` issue).
- [IMPROVED] `tests/Unit/Support/ApiDataMappers/Bitget/BitgetApiDataMapperTest` + `BitgetCloseTradeQueryTest` — fixtures updated to reflect the real Bitget hedge-mode response shape (newest-first input, both open and close fills carry the original opening side).

## 1.7.2 - 2026-04-26

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.6.2 to pull in: Bitget algo-order drift correction via `place-pos-tpsl` overwrite (replaces the cancel+recreate workflow that fails for Bitget position-level TP/SL), credential leak fix for `api_request_logs` (Eloquent `$hidden` + new `HeaderSanitizer`), notification-lifecycle hardening (slow-query recursion guard, `Notification` lookup cache, fail-loud builder, `NotificationLogStatus` enum, observer error-logging).

### Tests

- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Order/Bitget/ModifyAlgoOrderJobTest` — 9 cases pinning the new Bitget algo-order modify behavior: drift detection gates, sibling lookup, complete syncs price back to reference, position/order ownership guards.
- [NEW FEATURE] `tests/Unit/Jobs/Lifecycles/Order/Bitget/PrepareOrderCorrectionJobTest` — 4 cases pinning the Bitget override workflow: dispatches modify+sync (NOT cancel+recreate) for algo, falls back to correct+sync for LIMIT, gates on drift + position status.
- [NEW FEATURE] `tests/Unit/Models/CredentialSerializationTest` — 7 cases pinning Eloquent serialization filtering for Account + Kraite (every credential column excluded from `toArray`/`toJson`; direct attribute access + `all_credentials` accessor preserved).
- [NEW FEATURE] `tests/Unit/Notifications/NotificationLifecycleTest` — 5 cases pinning the `NotificationLogStatus` enum values + helpers and the fail-loud `NotificationMessageBuilder` behavior.
- [NEW FEATURE] `tests/Unit/Support/HeaderSanitizerTest` — 9 cases pinning auth-header redaction across Bitget / Binance / KuCoin / Bybit / Authorization / generic with case-insensitive matching and pass-through for non-auth headers (Content-Type, *-TIMESTAMP).

## 1.7.1 - 2026-04-26

### Fixes

- [BUG FIX] `database/migrations/2026_04_26_150000_drop_stop_market_wait_minutes_from_accounts` — drops the obsolete `accounts.stop_market_wait_minutes` column on already-migrated environments. The original-design SL cooldown was retired from the trading flow and no longer reads/writes the column; `kraitebot/core`'s create-schema migration is already updated to omit it on fresh installs.

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.6.1 to pull in the Bitget create-positions hardening (`doubleCheck` fast-path + try/catch, cross-exchange interface rename for trade-fills queries, symmetric audit-log events on TP/SL placement).

### Tests

- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Order/Bitget/PlacePositionTpslJobDoubleCheckTest` — 4 cases pinning the `doubleCheck()` invariants: fast-path short-circuits to `true` without any API call when both `exchange_order_id`s are populated, slow-path returns `false` (not throws) on transient failure, slow-path triggered when either id is null, and null Order properties don't crash. Regression guard for the 2026-04-26 THETAUSDT cancel.
- [NEW FEATURE] `tests/Unit/Support/ApiDataMappers/Bitget/BitgetCloseTradeQueryTest` — 5 cases pinning the cross-exchange interface contract: `BitgetApiDataMapper` exposes `prepareQueryTokenTradesProperties` + `resolveQueryTradeResponse`, `BitgetApi` exposes `accountTrades`, and the trade-fills response parses into the flat shape `extractClosingPriceFromTrades` expects. Regression guard for the silent `closing_price` failure.
- [IMPROVED] `tests/Unit/Support/ApiDataMappers/Bitget/BitgetApiDataMapperTest` — updated 5 existing test cases to call the renamed mapper methods.

## 1.7.0 - 2026-04-26

### Features

- [NEW FEATURE] Pulls in `kraitebot/core` v1.6.0 — **dual position-mode support** for Binance Futures (Hedge + One-Way). Live-validated against Bruno's hedge-mode account (12/12 active, no regression) and Karine's one-way account (11/12 active, all opens via new one-way payload, zero -4061 errors, 290 Binance order calls × HTTP 200). Reactive auto-flip on Binance error family (-4060/-4061/-4062/-4067) handles user-initiated mode changes within one cron tick. Full design in `kraitebot/core docs/02-features/dual-position-mode.md`.
- [NEW FEATURE] `BusinessSeeder` — sets `on_hedge_mode=false` on the Karine row to match her live Binance one-way reality. Bruno's main account stays at the migration-default `true` (hedge), no change.
- [NEW FEATURE] `tests/Support/ResponseException` — three new factory methods (`binancePositionSideMismatch` -4061, `binanceInvalidPositionSide` -4060, `binanceReduceOnlyConflict` -4062) for stubbing the position-mode error family in tests.

### Tests

- [NEW FEATURE] `tests/Unit/Support/ApiDataMappers/Binance/BinanceMapperHedgeAwareTest` — 8 cases pinning the mapper's payload contract across (mode × order type × intent). Hedge sends `positionSide=LONG/SHORT`, one-way omits `positionSide` and sets `reduceOnly=true` on closing-intent orders. Algo path keeps `closePosition=true` in both modes; never sets `reduceOnly` (mutually exclusive per Binance docs).
- [NEW FEATURE] `tests/Unit/Jobs/Models/Account/AssignBestCountByDirectionOneWayTest` — 6 cases pinning the slot-counter's interpretation of one-way response shape (`positionSide=BOTH` + signed `positionAmt`).
- [NEW FEATURE] `tests/Integration/ExceptionHandlers/PositionModeAutoFlipTest` — 6 cases pinning the auto-flip catch: hedge → one-way flip on -4061, symmetric one-way → hedge flip, family-of-codes coverage (-4060, -4062), dual audit log (Log::warning + Account::modelLog), no false-positive on unrelated RequestExceptions.

## 1.6.2 - 2026-04-26

### Features

- [NEW FEATURE] `BusinessSeeder::seedKraiteTrader()` + `migrateAccountOwnership()` — new dedicated Kraite trader user (owner of Main Binance Account), kept separate from the sysadmin (`admin_user_email`) so administrative login and live trading identity are decoupled. Existing accounts get reassigned to the new trader on first run; idempotent on subsequent runs.
- [NEW FEATURE] `config/kraite-ingestion.php` `traders.kraite` block + matching `TRADER_KRAITE_*` env vars — name, email, password, pushover key for the new trader identity.

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.5.10 to pull in the backtest recency fetcher + simulator refinements.

## 1.6.1 - 2026-04-25

### Fixes

- [BUG FIX] Pulls in `kraitebot/core` v1.5.9 — atomic slot reservation in `AssignBestTokensToPositionSlotsJob`. The 2026-04-25 17:33 incident (2 SHORT positions created when only 1 slot was free, slot cap breached, realised loss on positions #241 + #242) was a textbook check-then-act race that's now closed at the database transaction layer with `lockForUpdate` on the accounts row.

### Improvements

- [IMPROVED] Pulls the v1.5.7 command-entry idempotency revert. With the cap enforced as a database invariant the command-side guard was redundant defence-in-depth on top of a real bug. The proper fix lives in the domain logic now.

### Tests

- [NEW FEATURE] `tests/Feature/Jobs/AssignBestTokensAtomicSlotReservationTest` — pins the contract: `createPositionSlots` body uses `DB::transaction` + `lockForUpdate` (source pin so a future refactor can't quietly drop the lock), and the SHORT slot cap holds when the account starts one slot below cap.
- [REMOVED] `tests/Feature/Commands/CreatePositionsCommandIdempotencyTest.php` — pinned the now-removed command-entry guard.

## 1.6.0 - 2026-04-25

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.5.8 to pull in the wired-up group-progress watchdog notification path. The `--watchdog-progress` flag scheduled in v1.5.7 now produces actual operator alerts (severity=critical, 10-min throttle per group); previously the event landed silently. Closes the operational-visibility hole that would have let the next cleanup-phase wedge go unnoticed for hours.

### Tests

- [NEW FEATURE] `tests/Feature/Listeners/SendStaleStepsGroupProgressNotificationTest` — pins the listener mapping: `group_no_progress` event routes to `group_no_progress_detected` canonical, respects the global `notifications_enabled` config gate, does not bleed into other reasons.

## 1.5.9 - 2026-04-25

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.5.7 to pull in the command-entry idempotency guard on `CreatePositionsCommand`. Closes the actual cause of the 2026-04-25 17:33 twin-orchestrator incident. Pairs with the v1.5.6 `DispatchPositionSlotsJob` guard (defence-in-depth).

### Tests

- [NEW FEATURE] `tests/Feature/Commands/CreatePositionsCommandIdempotencyTest` — pins the new contract: the command skips an account that already carries a non-terminal `PreparePositionsOpeningJob` step; first-time dispatches still happen; past concluded workflows do not count as in-flight.

## 1.5.8 - 2026-04-25

### Improvements

- [IMPROVED] `composer.lock` — bumped `kraitebot/core` to v1.5.6 to pull in the `DispatchPositionSlotsJob` idempotency guard. Closes the twin-workflow race that produced the 12 Failed steps + realised loss on positions #241 + #242 during the 2026-04-25 17:33-17:34 wedge debug window.

### Tests

- [NEW FEATURE] `tests/Feature/DispatchPositionSlotsJobIdempotencyTest` — pins the new contract: a 'new' position with a live `DispatchPositionJob` step is skipped on a duplicate dispatch run; a position with only terminal-state prior attempts is treated as orphan and dispatched fresh.

## 1.5.7 - 2026-04-25

### Improvements

- [IMPROVED] `routes/console.php` — moved `kraite:cron-sync-orders` inside the `! $isCoolingDown()` gate. Lets an operator drain ALL new-work creation (not just opens) before patching the dispatcher / consumer side; outside cooldown windows behaviour is unchanged. Added `--watchdog-progress` to the `steps:recover-stale` schedule so the new group-progress alarm fires on the existing every-minute cron.
- [IMPROVED] `composer.lock` — bumped `brunocfalcao/step-dispatcher` to v1.11.6 and `kraitebot/core` to v1.5.5 to pull in the cleanup-phase fixes (`skipAllChildStepsOnParentAndChildSingleStep` + `promoteResolveExceptionSteps` no-op return-true bugs), the per-tick `max_per_tick` cap, the group-progress watchdog, and the orphan-position recovery in `CreatePositionsCommand`.

### Tests

- [NEW FEATURE] `tests/Feature/Commands/CreatePositionsCommandOrphanRecoveryTest` — three cases pinning the orphan-recovery contract: re-dispatches a stranded `status='new'` position with no live step, idempotent when a live step already exists, and re-dispatches when the only existing step is in a terminal state (cancelled mid-flight).

## 1.5.6 - 2026-04-25

### Tests

- [IMPROVED] `tests/Feature/Jobs/Lifecycles/ApiSystem/DiscoverCMCTokensForOrphanedSymbolsJobTest` — updated to match the new self-elect contract enforced in `kraitebot/core` v1.5.4: the test helper no longer pre-sets `child_block_uuid` on the seeded step (mirroring real-world callers that now leave it null at create time), and the empty-children assertion verifies the orchestrator did NOT elect to parent mode (`child_block_uuid` stays null) instead of the prior "manually nulled on empty-path" expectation.

## 1.5.5 - 2026-04-24

### Features

- [NEW FEATURE] `database/migrations/2026_04_24_214654_add_api_system_response_created_index_to_api_request_logs.php` + `2026_04_24_214655_add_state_completed_at_index_to_steps.php` — composite indexes on `api_request_logs(api_system_id, http_response_code, created_at)` and `steps(state, completed_at)` to accelerate the dashboard query hotspots that were showing up in slow-query logs.

### Improvements

- [IMPROVED] `routes/console.php` — wired `kraite:watch-price-stream` to the scheduler (every minute, `withoutOverlapping`). External watchdog for the Binance mark-price daemon; belt-and-suspenders on top of the internal `BaseWebsocketClient` idle watchdog. Not gated by cooldown since fresh prices are load-bearing for selection + S/R gating regardless of trade-pause state.
- [IMPROVED] Test fixtures across 5 Feature suites — stripped `'timeframes' => [...]` from `ApiSystem::firstOrCreate` / `update` / factory calls (the column moved to `kraite.timeframes` globally) and seed the singleton via `Kraite::updateOrCreate(['id' => 1], ['timeframes' => [...]])` where the test needs a specific timeframe fixture. `HasTokenDiscoveryTest` gained a `beforeEach` default seed of `["1h","4h","12h","1d"]`; the account-building helper overrides with `["5m","1h","4h","12h","1d"]` for the scoring scenarios that need the extra timeframes. Removed the now-useless `if (empty($apiSystem->timeframes)) { $apiSystem->update(...) }` defensive blocks.

## 1.5.4 - 2026-04-24

### Fixes

- [BUG FIX] `tests/Unit/Jobs/Atomic/Position/VerifyNotionalPreValidatesLimitLadderTest.php` — corrected the `limitQuantityMultipliers` from `[2, 2, 2, 2]` to `[0.2, 0.2, 2, 2]` to actually reproduce the USELESS #64 incident shape. With the default `[2, 2, 2, 2]` multipliers and `marketOrderQty=415`, rung #1 came in at ~$37 notional — nowhere near the $5 min_notional floor, so the throw never fired and the test sat red at HEAD. The real incident was driven by a corrupt multipliers JSON (`[0.2, 0.2, 2, 2]`) that shrank rung #1 qty to 83 and produced the $3.71 notional the guard exists to catch. Docblock updated with the root cause.

## 1.5.3 - 2026-04-24

### Features

- [NEW FEATURE] `tests/Unit/Support/Backtest/BacktestSimulatorDividerTest.php` — 2-case regression guard for `get_market_order_amount_divider($N) = 2^(N+1)` being applied at the simulator's market-sizing call site (source-inspection level so a future refactor that silently drops the divider re-exposes the min-notional gating divergence between backtest and live trading).
- [NEW FEATURE] `tests/Unit/Support/TradingMappers/DelistingFlagPersistenceTest.php` — 8 cases pinning the `isNowDelisted()` contract across all four TradingMappers. Covers pre-save (`saving()`-hook) context, post-save (`saved()`-hook) context, perpetual-default false-positive guard on Binance, no-change → false, and a source-level architectural consistency check that all four mappers use both `isDirty` and `wasChanged` detection.
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Position/PreparePositionDataDirectionAwareMarginTest.php` — 5 cases pinning the direction-aware margin calculation. LONG reads `margin_percentage_long`, SHORT reads `margin_percentage_short`, the two can diverge on the same account, missing columns fall back to 5.00 for both, and a source-level guard on the method signature.

### Improvements

- [IMPROVED] `database/seeders/BusinessSeeder.php` — seeds the Main Binance Account with `margin_percentage_long=5.00` and `margin_percentage_short=5.00` to align with the direction-aware margin migration in `kraitebot/core`.

### Removals (deadcode cleanup)

- [IMPROVED] Deleted `tests/_Feature/Jobs/QuerySymbolIndicatorsBulkJobTest.php` (underscore-prefixed directory Pest skipped; referenced a `QuerySymbolIndicatorsBulkJob` class that no longer exists). Empty `_Feature/` tree removed.
- [IMPROVED] Removed the empty `function something(): void` stub from `tests/Pest.php`.
- [IMPROVED] Removed `cleanLogsFolder()` helper (zero call sites) and the now-unused `Illuminate\Support\Facades\File` import from `app/helpers.php`.
- [IMPROVED] Removed dead config keys `kraite.candles` + `kraite.default_throttle_seconds` from `config/kraite.php`.

## 1.5.2 - 2026-04-23

### Features

- [NEW FEATURE] `tests/Unit/Support/TruncateDecimalStringTest.php` — 7-case regression guard for `truncate_decimal_string`. Pins: integer inputs with trailing zeros round-trip untouched ("1310" → "1310", "460" → "460", "100" → "100", "2000" → "2000", "655" → "655"); fractional tails still get trimmed ("0.04560" → "0.0456"); precision truncates the fractional part; pure-zero fractional tails collapse to the integer; negative inputs don't emit "-0"; empty integer part (".5"-style) normalizes to "0.5".
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/ExchangeSymbol/QueryAndStoreSupportAndResistanceJobTest.php` — 6 cases covering the TAAPI-shaped wrapped-array pivot payload (the failing production case), the defensive flat shape, named skip reasons for missing-pivotpoints / unrecognised-shape / direction-cleared, and an architectural consistency check that both `ConfirmPriceAlignmentWithDirectionJob` invalidation paths null the pivot columns.

## 1.5.1 - 2026-04-23

### Features

- [NEW FEATURE] `tests/Unit/Support/SupportResistanceProximityTest.php` — 12 cases pinning the S/R proximity math: middle zone (multiplier 1.0), LONG linear fade toward R1, SHORT linear fade toward S1, R3/S3 breakout handling (direction-matched continuation = 1.0, against-direction = 0.0), graceful degrade on missing data, degenerate R1=S1 range, custom `safe_zone` threshold.
- [NEW FEATURE] `tests/Unit/Indicators/PivotPointsIndicatorTest.php` — 5 cases asserting the `PivotPointsIndicator` contract: extends `BaseIndicator`, implements `ValidationIndicator`, targets the `pivotpoints` TAAPI endpoint, `isValid()` and `conclusion()` always return true regardless of data.
- [NEW FEATURE] `tests/Feature/PivotColumnsAndFinalizationWiringTest.php` — 4 cases asserting the seven pivot columns + `pivot_synced_at` exist on `exchange_symbols`, the atomic class exists, `ConcludeSymbolDirectionAtTimeframeJob::createFinalizationSteps` references the new job, and both direction-invalidation paths null the pivot columns.

### Improvements

- [IMPROVED] Supervisor config added at `/etc/supervisor/conf.d/kraite-ingestion-binance-prices.conf` for the new `kraite:stream-binance-prices` WebSocket daemon (autostart / autorestart). Not versioned in this repo but documented in `docs/kraite/00-context/system-overview.md`.

## 1.5.0 - 2026-04-23

### Features

- [NEW FEATURE] `tests/Unit/Jobs/Lifecycles/Position/DispatchPositionSlBeforeTpOrderingTest.php` — 3 cases (one per separate-TPSL exchange: Binance / Bybit / KuCoin) pinning the SL-before-TP invariant in each `DispatchPositionJob` override. Regression guard for the LAB #121 / BSB #109 / LAB #107 TP-fills-before-SL race class.
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Order/PlaceLimitOrderJobIdempotencyTest.php` — 4 cases on `PlaceLimitOrderJob` retry semantics: retry-allowed with pre-existing `exchange_order_id` (LAB #107 regression), first-time placement still works, terminal statuses still rejected, and a method-body assertion that `computeApiable()` guards the `apiPlace()` call.
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Position/CancelAlgoOpenOrdersGhostOrderTest.php` — 2 cases pinning the ghost-algo-order guard: a STOP-MARKET row with `is_algo=1` and `exchange_order_id=NULL` is excluded from cancellation; the SQL filter is source-asserted so the pre-update query can't drop it either.

## 1.4.9 - 2026-04-23

### Features

- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Position/VerifyNotionalPreValidatesLimitLadderTest.php` — 2-case regression guard for the USELESS #64 incident: asserts `Kraite::calculateLimitOrdersData()` rejects a USELESS-shaped scenario (rung #1 notional below min_notional on `[2,2,2,2]`), and that `VerifyOrderNotionalForMarketOrderJob::computeApiable()` actually invokes the calculator with `mark_price` + projected `market_order_quantity` so the ladder is validated BEFORE any market placement.
- [NEW FEATURE] `tests/Unit/Concerns/Order/SyncNormalizationTest.php` — 5-case suite pinning the sync-path normalization contract: stored price preserved on null/zero echo, positive price tick-floored via `api_format_price`, stored quantity preserved on null/non-numeric, numeric quantity lot-aligned via `api_format_quantity`, legitimate zero (Binance algo `executedQty` for unfilled SL) accepted as-is.

### Improvements

- [IMPROVED] `routes/console.php` — `kraite:disable-volatile-tokens` wired on the scheduler hourly at `:45` (staggered away from the other hourly jobs). Sweeps `exchange_symbols` across every api_system and disables tokens on the curated deny-list (memes / speculative / structural-brittle); strictly additive, never re-enables.
- [IMPROVED] `database/seeders/BusinessSeeder.php` — Main Binance Account now seeds with `total_positions_long=6` and `total_positions_short=6` so the live account runs a real trading book (12 slots concurrent) instead of the migration default of 1 per side.

## 1.4.8 - 2026-04-23

### Features

- [NEW FEATURE] `tests/Feature/CalculateWapExchangeClassLoadingTest.php` — 6-case suite pinning the `final`-class fix on `CalculateWapAndModifyProfitOrderJob`: base not declared final, Binance + Bitget exchange variants load without FatalError, JobProxy resolves per-exchange correctly, unmapped exchanges fall back to base.
- [NEW FEATURE] `tests/Feature/CancelOrphanAlgoOrdersWiringTest.php` — pins `JobProxy` resolution of `CancelOrphanAlgoOrdersJob` (Binance variant for Binance accounts, base no-op for bitget/bybit/kucoin) and verifies the step-1 placement inside `SmartReplaceOrdersJob::compute()` (orphan-scrub before recreation).
- [NEW FEATURE] `tests/Feature/OrderObserverDriftDetectionDuringSyncTest.php` — 5 cases locking the drift-detection contract during `syncing`: price/qty drifts dispatched mid-sync, `opening`/`waping` still correctly skipped, plain `active` baseline still works.
- [NEW FEATURE] `tests/Feature/RecreateCancelledOrderClosePositionAlgoTest.php` — 3 cases for the closePosition-style algo exemption in `RecreateCancelledOrderJob::startOrFail()`: `is_algo + reference_quantity=0` allowed, non-algo zero-qty still rejected, regular LIMIT with positive qty still allowed.
- [NEW FEATURE] `tests/Unit/Support/ApiExceptionHelpersChainWalkTest.php` — 7 cases for the `getPrevious()` chain-walk in `containsHttpExceptionIn`: raw + wrapped Binance `-5027` both classified as ignorable, 3-level-deep wraps still walk, wrapped non-ignorable codes correctly rejected, wrapped 503 + `-1021` route to retry classifier.

### Improvements

- [IMPROVED] `routes/console.php` — `kraite:cron-sync-orders` re-enabled on the scheduler (every minute, `withoutOverlapping`). `kraite:purge-model-logs --duration=30` wired on the scheduler daily at 03:30 for the new model_logs retention policy.
- [IMPROVED] `tests/Feature/WapWorkflow/OrderObserverDriftSkipTest.php` — flipped the `syncing` test case to reflect the new contract (drift detection NOW fires during syncing; previously pinned the buggy skip behaviour). Docblock updated with the rationale for why `syncing` must NOT be in the skip list.
- [IMPROVED] `.env` — `NOTIFICATIONS_ENABLED=true` enabling the unified notification pipeline for production operation.

## 1.4.7 - 2026-04-23

### Features

- [NEW FEATURE] `tests/Unit/Support/MathIsPositiveTest.php` — 7-case suite pinning `Math::isPositive()` across null, non-numeric types, empty/sign-only strings, malformed numerics, zero in every supported form, negatives, and strictly-positive values (including scientific notation, comma decimals, and leading `+`).
- [NEW FEATURE] `tests/Unit/Support/ApiDataMappers/Binance/BinanceAccountQueryTradesTest.php` — 3 tests locking the Binance `/fapi/v1/userTrades` query limit at 5 (orderId present/absent both carry the limit).
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Order/SyncPositionOrdersAllFailedMessageTest.php` — asserts the "all failed" `RuntimeException` reports the failure count (not a raw `exchange_order_id`). Buggy interpolation would emit `"All 9876543+ orders failed..."`; the test explicitly refuses that shape.
- [NEW FEATURE] `tests/Unit/Jobs/Atomic/Position/ExtractClosingPriceFromTradesTest.php` — 8-test suite for the new reducing-trade extractor: signature accepts `string $direction`, empty trades returns null, LONG picks SELL, SHORT picks BUY, newest-first short-circuit, positionSide-absent fallback, case-insensitive direction, zero-price trades ignored.
- [NEW FEATURE] `tests/Feature/ModelLogCurrentStepLifecycleTest.php` — 5 tests covering the process-scoped step context: set during `handle()`, cleared by `__destruct` after sync success / exception / `stopJob()` exits, identity-guard prevents cross-job stomp, and `Queue::after` listener clears on real queued-job completion.
- [NEW FEATURE] `tests/Feature/OrderObserverFilledAtGuardTest.php` — 3 tests pinning `filled_at` behaviour: stamped on first NEW → FILLED, NOT re-bumped on subsequent FILLED saves, never set on non-FILLED saves.
- [NEW FEATURE] `tests/Feature/ResolveSyncedPriceTest.php` — 7 tests for the cancelled-algo-price preservation: null / empty / integer-0 / string-"0" / multi-decimal-"0.00000000" all preserve the stored DB price; legitimate non-zero values flow through.
- [NEW FEATURE] `tests/Feature/PreparePositionsOpeningJobNoChildBlockTest.php` — end-to-end guard that `DispatchPositionSlotsJob` is persisted without a `child_block_uuid`. Prevents the block-completion wedge that used to hold `PreparePositionsOpeningJob` in `Running` forever.

### Improvements

- [IMPROVED] `routes/console.php` — commented out the test-ambient cron entries (`kraite:cron-sync-orders`, `kraite:cron-fetch-klines --*`, `kraite:cron-store-accounts-balances`, `kraite:purge-candles`) so only the step-dispatcher family (`steps:dispatch`, `steps:recover-stale`, `steps:archive`) runs during the isolated sync-orders test suite. Re-enable before shipping.

## 1.4.6 - 2026-04-22

### Features

- [NEW FEATURE] `tests/Feature/Jobs/DispatchPerSymbolKlineBlocksJobTest.php` — 2-test suite pinning the new per-symbol orchestrator: one block per symbol with correct (klines@idx1 + correlation/elasticity@idx2) layout, and zero blocks when symbolIds list is empty.
- [NEW FEATURE] `tests/Feature/Jobs/CalculateBtcCorrelationJobTest.php` — 4-test suite validating the correlation optimizations: perfect positive correlation → pearson > 0.99, perfect negative → pearson < -0.99, same symbol fed different klines yields different persisted correlation values, and BTC candle cache is reused across symbols.
- [NEW FEATURE] `tests/Feature/Commands/FetchKlinesCommandPerSymbolBlockTest.php` — 3-test suite for the command's two-phase block layout: shared BTC block has `DispatchPerSymbolKlineBlocksJob` at index 2, per-symbol correlation steps aren't created upfront (lazy spawn), and the orchestrator materializes one block per symbol when executed.

### Improvements

- [IMPROVED] `config/horizon.php` — reallocated worker counts per queue based on observed saturation: `indicators` 20→30 (biggest bottleneck, mixed API-gated + CPU-bound work), `positions` 5→8 (trading latency is user-facing), `cronjobs` 20→8 (orchestrators are lightweight, 20 was over-provisioned), `orders` 10→5 (low volume, only `PlaceLimitOrderJob` lands here). `priority` stays 5. Applied across `local`, `ingestion`, `worker1`, `worker2` environments. Net: 61 → 57 workers.

## 1.4.5 - 2026-04-22

### Improvements

- [IMPROVED] `routes/console.php` — swapped the now-deleted `kraite:cron-check-stale-data` schedule for `steps:recover-stale --recover-dispatched --release-locks`. Stall detection (Running zombies, stuck Dispatched steps, wedged dispatcher locks) moved into `brunocfalcao/step-dispatcher` v1.11; pushover/email alerts remain via `kraitebot/core`'s `SendStaleStepsNotification` listener. Same cadence, same notifications.
- [IMPROVED] `config/horizon.php` — multi-queue supervisor lineup across `local`, `ingestion`, `worker1`, `worker2` environments. Removed `default-supervisor` + `step-dispatcher-supervisor`. Added domain supervisors: `positions-supervisor` (5w), `orders-supervisor` (10w), `cronjobs-supervisor` (20w), `indicators-supervisor` (20w). Kept `priority-supervisor` (5w) for the `priority='high'` bypass lane and hostname/ingestion/worker-N supervisors. Anything that slips to the bare `default` queue now stalls with zero workers — intentional audit signal for unmapped step creations.
- [IMPROVED] `.env` — flipped `STEP_DISPATCHER_LOGGING_ENABLED` to `false` so production stops accumulating per-step log folders.

## 1.4.4 - 2026-04-21

### Features

- [NEW FEATURE] `tests/Feature/WapWorkflow/` — 22-test suite pinning the WAP workflow hardening landed earlier today: `UpdatePositionStatusGuardTest`, `PositionProfitOrderFilterTest`, `OrderObserverDriftSkipTest`, `CalculateWapFollowUpAckTest`.
- [NEW FEATURE] `tests/Unit/StepDispatcher/T19_GroupFanoutTest` — 5 tests exercising the new fan-out threshold in the step-dispatcher package.

### Fixes

- [BUG FIX] `AppLogTest > it stores metadata as JSON` — canonicalize both sides with `ksort` before asserting equality; MySQL JSON columns don't preserve key order on retrieval, and the test had been failing silently as a baseline.
- [BUG FIX] `DiscoverCMCTokenForExchangeSymbolJobTest > skips if exchange symbol already has symbol_id` — call `startOrSkip()` instead of the removed `startOrFail()`; the job's guard was renamed in `kraitebot/core` v1.3.6 but the test was never updated.

### Improvements

- [IMPROVED] Bump `brunocfalcao/step-dispatcher` to v1.9.0 to pick up the group fan-out guard — hourly batch crons (kline fetch, BTC correlation / elasticity, indicator queries) no longer pile 600–1,100 siblings onto a single group.
- [IMPROVED] Drop orphan `composer-patches` `patches` entry from `composer.json`; the referenced `patches/laravel-boost-https-fix.patch` file was deleted in `f191a80` but the config still referenced it, breaking `composer update` with a missing-file error.

## 1.4.3 - 2026-04-21

### Improvements

- [IMPROVED] Schedule `kraite:cron-sync-orders` every minute (withoutOverlapping, outside cooldown gate) so open positions reconcile order state from the exchange on a tight cadence.
- [IMPROVED] Drop `default-supervisor` process count 50 → 20 to match actual throughput needs; the 50-worker bump was exploratory during load testing and held ~2 GB more RAM than needed.

## 1.4.2 - 2026-04-20

### Improvements

- [IMPROVED] Horizon `default-supervisor` bumped 5 → 30 (crypto step jobs dispatch to `default` queue, not `step-dispatcher`) and `balance: 'simple'` added to all local supervisors so workers stay spawned rather than scaling down during queue lulls.
- [IMPROVED] Removed obsolete 6h and 1d kline fetch schedules — timeframes trimmed to `[1h, 4h, 12h]` in the exchange config, so those schedules had no consumers.

## 1.4.1 - 2026-04-20

### Fixes

- [BUG FIX] Schedule `steps:recover-stale` every minute so orphaned Running steps get reclaimed automatically after worker crashes (command existed in the step-dispatcher package but was never wired to the scheduler)

## 1.4.0 - 2026-04-06

### Improvements

- [IMPROVED] Rename `Engine` to `Kraite` across all test files, routes, and config
- [IMPROVED] Rename `tests/Unit/Engine/` directory to `tests/Unit/Kraite/`
- [DEPENDENCIES] Sync kraitebot/core with Engine → Kraite rename

## 1.3.9 - 2026-04-05

### Features

- [NEW FEATURE] Exchange cooldown mechanism — blocks new position creation when exchanges report server instability (503/504)
- [NEW FEATURE] Exchange cooldown tests (31 tests covering model, handlers, observer, and command)

### Improvements

- [IMPROVED] `StoreAccountsBalancesCommand` refactored to step-based workflow with full exception handling
- [IMPROVED] Test database password synced with credentials in `phpunit.xml`

## 1.3.8 - 2026-02-21

### Features

- [NEW FEATURE] Add `kraite-ingestion.php` config file for trader/account env vars

### Improvements

- [IMPROVED] BusinessSeeder uses `config('kraite-ingestion.*')` instead of `env()` — zero direct env access in seeders

## 1.3.7 - 2026-02-21

### Features

- [NEW FEATURE] Add BusinessSeeder for trader/account/exchange integration data (moved from kraitebot/core)

### Improvements

- [IMPROVED] DatabaseSeeder now calls KraiteSeeder (system) then BusinessSeeder (business data)
- [IMPROVED] Replace `env()` calls with `config()` in BusinessSeeder

### Dependencies

- [DEPENDENCIES] Sync kraitebot/core with seeder split

## 1.3.6 - 2026-02-21

### Improvements

- [IMPROVED] Remove 18 artisan commands — now provided by kraitebot/core package

### Dependencies

- [DEPENDENCIES] Sync kraitebot/core with centralized command registration

## 1.3.5 - 2026-02-21

### Dependencies

- [DEPENDENCIES] Sync kraitebot/core after waitlist_subscribers migration consolidation

## 1.3.4 - 2026-02-21

### Features

- [NEW FEATURE] Add AppLog unit tests covering polymorphic relationships, severity defaults, JSON metadata, disable/enable toggle, and multi-model support

### Dependencies

- [DEPENDENCIES] Update `kraitebot/core` with AppLog model, migration, and business timeline logging across position creation chain

## 1.3.3 - 2026-02-21

### Improvements

- [IMPROVED] Remove `JustEndException` and `JustResolveException` references from TestQueueableJob and exception type tests

### Dependencies

- [DEPENDENCIES] Update `kraitebot/core` with algo order endpoint fix, observer silent rejection, and exception cleanup
- [DEPENDENCIES] Update `brunocfalcao/step-dispatcher` with JustEnd/JustResolve exception removal

## 1.3.2 - 2026-02-19

### Dependencies

- [DEPENDENCIES] Update `kraitebot/core` lock reference to include legacy `src/_Jobs` namespace removal (commit `05dc53d`)

## 1.3.1 - 2026-02-19

### Improvements

- [IMPROVED] Add ingestion-side regression tests for `kraitebot/core` decimal formatting helpers and direction-alignment job namespace reference

### Dependencies

- [DEPENDENCIES] Update `kraitebot/core` lock reference to include precision-safe formatting and alignment-step namespace fixes

## 1.3.0 - 2026-02-17

### Improvements

- [IMPROVED] Complete rebranding from Martingalian to Kraite across all configuration files, deployment scripts, supervisor configs, CI/CD workflows, and test files
- [IMPROVED] Rename protected table reference from 'martingalian' to 'engine' in ClearTablesCommand
- [IMPROVED] Update all domain references from *.martingalian.com to *.kraite.com
- [IMPROVED] Update package references from martingalian/core to kraitebot/core throughout codebase
- [IMPROVED] Update database names from martingalian_tests to kraite_tests in all test configurations
- [IMPROVED] Update system paths from /home/martingalian/ to /home/ploi/ and user from martingalian to waygou in supervisor configs
- [IMPROVED] Update vendor directory cleanup in deploy.sh from vendor/martingalian/ to vendor/kraitebot/

### Fixes

- [BUG FIX] Fix StepObserverGroupAssignmentTest by properly cleaning steps_dispatcher table in beforeEach to prevent orphaned group records
- [BUG FIX] Fix MySQL test database authentication by using mysql_native_password plugin for kraite user
- [BUG FIX] Mark AnalyticsControllerTest tests as incomplete since analytics routes are not yet implemented

## 1.2.0 - 2026-02-14

### Improvements

- [IMPROVED] Fix phpunit.xml test database credentials for MySQL 8.4 (martingalian user instead of root)
- [DEPENDENCIES] Update martingalian/core and martingalian/step-dispatcher with BaseStepJob extraction

## 1.1.0 - 2026-02-13

### Security

- [SECURITY] Add Content-Security-Policy header to Nginx (`default-src 'none'; frame-ancestors 'none'`)
- [DEPENDENCIES] Update martingalian/core with webhook security hardening and rate limiting

## 1.0.0 - 2026-02-11

### Improvements

- [IMPROVED] Migrate database from MariaDB 10.11 to MySQL 8.4 LTS (DB_CONNECTION=mysql)
- [DEPENDENCIES] Update martingalian/core with EMA parsing fix and debug logging cleanup
