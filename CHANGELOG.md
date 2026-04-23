# Changelog

All notable changes to this project will be documented in this file.

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
