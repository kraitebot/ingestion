# Changelog

All notable changes to this project will be documented in this file.

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
