# Changelog

All notable changes to this project will be documented in this file.

## 1.56.3 - 2026-06-23

### Features

- [NEW FEATURE] **Unit-divergent cross-exchange contracts are switched off and kept out of trading** (kraitebot/core 1.58.0). A symbol that lists the same asset under a different contract unit than Binance (KuCoin/Bybit `FLOKI` vs Binance `1000FLOKI`) carried a replicated mark_price wrong by the contract ratio (~1000×). The symbol refresh now runs an index-4 price-alignment check: each naming-divergent `symbol_id` sibling's live exchange price is compared to its Binance sibling within tolerance, and a divergent one is flagged `is_price_aligned=false` + `is_manually_enabled=false` (excluded from `scopeTradeable`) with one deduped notification. New `tests/Feature/PriceAlignmentTest.php` covers the divergent-disable, same-unit-stays, and parent-candidate-selection paths. Lock repinned to kraitebot/core 1.58.0.

## 1.56.2 - 2026-06-22

### Bug fixes

- [BUG FIX] **Naming-divergent symbols bridge their Binance direction by canonical `symbol_id`** (kraitebot/core 1.57.0). BitGet FLOKI / SHIB and Bybit SKYAI1 are the same assets as Binance 1000FLOKI / 1000SHIB / SKYAI but had no hand-seeded `token_mapper` row, so they never received a direction and perpetually tripped the indicator-stale watchdog. Overlap detection + the conclude-cycle direction copy now match on `symbol_id` (naming-agnostic identity), with exact-token + token_mapper kept as the fallback. New `tests/Feature/SymbolIdIdentityBridgeTest.php` covers the bridge, the ticker-collision guard, and the token-fallback no-regression case. Lock repinned to kraitebot/core 1.57.0.

## 1.56.1 - 2026-06-21

### Bug fixes

- [BUG FIX] **A delisted BitGet symbol's kline fetch self-heals on the first failure** (kraitebot/core 1.55.1). BitGet's kline endpoint reports a gone contract with code 40034, which the delisting classifier did not recognise (only 40309), so `FetchKlinesJob` failed every cycle for a delisted BitGet symbol until the hourly proactive sweep caught it. Exposed by the 2026-06 TON→GRAM rebrand (BitGet pulled TONUSDT → 40034 each kline cycle; reference data only, no trading impact). New `tests/Unit/Support/SymbolDelistedClassifierTest.php` coverage (40034 → delisted, 40808 malformed-param → not) plus a `bitgetSymbolNotFound40034()` fixture. Lock repinned to kraitebot/core 1.55.1.

## 1.56.0 - 2026-06-21

### Features

- [NEW FEATURE] **Fleet observability goes live — silence watchdog + roster-identity IP resolution** (kraitebot/core 1.55.0). The system-health watchdog's fleet-silence check is now lifecycle-aware: a box mid-provision (roster row younger than `FLEET_METRICS_PROVISIONING_GRACE_SECONDS`, default 86400) no longer pages, a `stale` box always does, and an alertable box re-pages once per `FLEET_METRICS_ALERT_THROTTLE_SECONDS` (default 3600) instead of on every 7-minute tick. `Kraite::ip()` now resolves the box's public IP through its logical roster identity (`FLEET_METRICS_HOSTNAME`) before `gethostname()`, closing the silent ipify-fallback blackout (deploy-notes #86). `phpunit.xml` pins `FLEET_METRICS_HOSTNAME=""` so the dev `local` identity never leaks into the suite. New `tests/Feature/Cronjobs/CheckSystemHealthFleetSilenceTest.php` covers the grace / throttle / stale / corrupt-stamp paths; `tests/Unit/Models/KraiteIpTest.php` extended for the roster-override resolution. Composer lock repinned to kraitebot/core 1.55.0 + brunocfalcao/step-dispatcher 1.14.1 (the job-engine regression-coverage release), alongside routine third-party dependency refreshes resolved and validated by the full suite.

## 1.55.6 - 2026-06-12

### Infrastructure

- [IMPROVED] **palaemon + aristaeus trading workers added to `horizon.workers`.** The fifth and sixth interchangeable trading workers consume `positions` / `orders` / `priority` plus their per-host probe queue.

## 1.55.5 - 2026-06-12

### Bug fixes

- [FIXED] **Local Horizon worker env-scoped to clear the prod fleet-topology drift gate.** The dev box's worker block is scoped to `local` so `kraite:verify-fleet-topology --fail-on-drift` stays green on production without the Mac's row polluting the prod roster.

## 1.55.4 - 2026-06-12

### Infrastructure

- [IMPROVED] **tyche Horizon workers right-sized for 2 vCPU.** The indicators / cronjobs pools are throttle-bound (TAAPI window), so process counts were tuned to the box's two cores rather than over-provisioned.

## 1.55.3 - 2026-06-10

### Bug fixes

- [BUG FIX] **A delisted/closed exchange symbol no longer fails the whole leverage-bracket batch** (kraitebot/core 1.53.3). The per-symbol atomic now flags a closed/invalid symbol for delisting and completes cleanly instead of throwing and poisoning the shared parent `SyncLeverageBracketsJob` every hourly refresh. Applies to Bybit, KuCoin and Bitget (shared atomic + per-exchange `isSymbolDelisted`). New regression `tests/Feature/Jobs/Atomic/ExchangeSymbol/SyncLeverageBracketJobDelistingTest.php` drives the real `Http::fake` pipeline (closed symbol → flagged + completes; genuine errors still surface).

## 1.55.2 - 2026-06-09

### Bug fixes

- [FIXED] **Dispatcher-stall alert now names the correct table set** (kraitebot/core 1.53.2). The `group_no_progress_detected` resolution SQL hardcoded `FROM steps`; a `trading_steps` group stall printed default-set diagnostics. The listener now threads the active dispatcher prefix into the message so a `trading_steps` wedge prints `trading_steps` queries (with a "Table set:" line). Coverage: two prefix cases in `tests/Feature/Listeners/SendStaleStepsGroupProgressNotificationTest.php`.

## 1.55.1 - 2026-06-09

### Bug fixes

- [FIXED] **Group-progress watchdog false positive on sparse, event-driven dispatcher groups** (brunocfalcao/step-dispatcher 1.13.5). `detectGroupNoProgress` measured staleness only against a group's last terminal step, never against how long the pending work had itself been waiting, so the `trading_*` set (fed by hours-apart Binance user-data events) false-fired a CRITICAL `group_no_progress_detected` page whenever the every-minute watchdog tick read a freshly-created step in the ~1s window before its dispatch. Observed 2026-06-09: `trading_steps` group `gamma` paged "wedged 72 minutes" on a `ProcessUserDataEventJob` that lived one second. The watchdog now also gates on the oldest non-throttled Pending step's age. See deploy-notes Entry 76.
- [FIXED] **`kraite:recover-positions` deferred forever on healthy positions** (kraitebot/core 1.53.1). `RecoverPositionsCommand::hasInflightStepFor` counted the parked `NotRunnable` rescue step that every opened position leaves behind as in-flight work, so recovery never proceeded — the case that blocked rescuing 8 positions wedged in `syncing` on 2026-06-09. Now excludes `NotRunnable` alongside terminal states. Coverage in `tests/Feature/Commands/RecoverPositionsNotRunnableInflightTest.php`.
- [FIXED] **Stale-steps notification throttle is now row-driven** (kraitebot/core 1.53.1). `SendStaleStepsNotification` reads the notification row's `cache_duration` instead of a hardcoded 600s, so a Notification Threshold armed on `group_no_progress_detected` is no longer starved by the throttle. Defaults to 600 — unchanged until a threshold is switched on.

## 1.55.0 - 2026-06-08

### Features

- [NEW FEATURE] **Notification Threshold** (kraitebot/core 1.53.0) — opt-in escalation gate on top of the throttler; a flagged notification is only delivered once it recurs N times within a window, sub-threshold occurrences are recorded-not-sent. Inert by default. Feature coverage in `tests/Unit/Notifications/NotificationThresholdTest.php` (re-earn, window expiry, multi-count, no-threshold passthrough).
- [NEW FEATURE] **Phase 3 — regime-scaled leverage + position-count ramps** (kraitebot/core 1.53.0); Critical BSCS is now absolute (respect_bscs + operator override removed). Open-time-only. TDD coverage shipped in 1.54.0's follow-up commit.

### Bug fixes

- [FIXED] **Group-progress watchdog no longer pages on throttle backpressure** (brunocfalcao/step-dispatcher 1.13.4) — `steps:recover-stale --watchdog-progress` now excludes `is_throttled` (rate-limit-waiting) steps from the "can't drain" tally, killing the chronic phantom `group_no_progress` alerts on athena's gamma/kappa/epsilon groups under TAAPI 429 saturation. Propagates fleet-wide with this deploy.

### Config

- [CONFIG] **TAAPI throttle tightened for headroom + pacing** — `TAAPI_THROTTLER_REQUESTS_PER_WINDOW` 75→65 and `TAAPI_THROTTLER_MIN_DELAY_MS` 50→200 on the indicator consumers (athena, tyche). Per-box `.env` (not in VCS); applied + Horizon-restarted out of band. Reduces the ~20% chronic 429 reject rate driven by the non-atomic shared window counter firing in unspaced bursts.
- [CONFIG] **Prod-only DB backup gating** — `backup:run`/`clean`/`monitor` schedule wrapped in `app()->isProduction()` so localhost no longer pollutes the shared B2 bucket (shipped in 1.54.0's follow-up commit).

## 1.54.0 - 2026-06-07

### Infrastructure

- athena now consumes a secondary `indicators` Horizon pool (10 procs) in `kraite.horizon.workers`. Gives the kline/indicator lane a second outbound public IP so StepRouter spreads the per-IP Bybit kline burst (retCode 10006) across athena + tyche and can rotate the lane off a rate-limited IP. Sized below tyche's 20 to protect the scheduler + dispatch daemon.

### Features

- TDD coverage for the new kraitebot/core runtime configuration: account-config gates, kraite-singleton settings, notification cascade, per-account `respect_bscs`, and the dispatcher-group drain recheck.

### Config

- Removed the dead `CAN_TRADE` / `CAN_OPEN_POSITIONS` env keys.
- Ships kraitebot/core v1.52.0.

## 1.53.11 - 2026-06-06

### Bug fixes

- [FIXED] **Pin `kraitebot/core 1.51.10`** — `ClosePositionAtomicallyJob` pump-cooldown check cast the 1d TAAPI `candle` close to string, but that indicator is queried with `results=2`, so `data['close']` is an oldest→newest array (e.g. `[9.053, 8.848]`). The cast threw `Array to string conversion` *before* `apiClose()` ran, leaving the position `failed` with its TP/SL already cancelled (naked). Now normalized to the most recent (last) array element; scalar single-result responses handled too. Surfaced during the localhost real-money test while investigating the prod↔localhost shared-key cross-fire. See core changelog.

## 1.53.10 - 2026-06-06

### Bug fixes

- [CHANGED] **Pin `kraitebot/core 1.51.9`** — position-mode auto-flip oscillation fix. Concurrent -4061s from a simultaneous LONG+SHORT open flipped `on_hedge_mode` twice (net-zero) and stuck the account on the wrong mode; the cooldown re-check now lives inside the row lock. Surfaced + fixed during the first live go-live. See core changelog + deploy-notes entry 73. Regression test added (`PositionModeAutoFlipTest`).

## 1.53.9 - 2026-06-06

### Bug fixes

- [CHANGED] **Pin `kraitebot/core 1.51.8`** — per-IP notification subjects now embed the IP so mail providers stop deduping the rotation alerts (one email per banned worker arrives). Surfaced during live rotation testing: 4 workers banned, 4 alerts fired, only 2 reached the inbox due to identical subjects. See core changelog + deploy-notes entry 72.

## 1.53.8 - 2026-06-06

### Bug fixes

- [FIXED] **`config/services.php` Resend key read the wrong env var.** Mapped `resend.key` to `env('RESEND_KEY')` while every `.env` uses `RESEND_API_KEY` (the `resend-laravel` convention) — so `config('services.resend.key')` was always null and mail threw `Resend::client(): $apiKey null given`. Now `env('RESEND_API_KEY')`. (admin/console/kraite.com already had the correct mapping; only ingestion carried the typo.) Patched live across the fleet on 2026-06-06; this commits it so a future deploy can't revert it. Full Resend wiring write-up in deploy-notes entry 71.

## 1.53.7 - 2026-06-06

### Bug fixes

- [FIXED] **Trader Pushover channel never delivered.** `BusinessSeeder::seedBrunoNidavellirTrader()` listed the Pushover channel but never set `pushover_key` (the key was in `.env.traders` as `TRADER_BB_PUSHOVER_KEY`, just unmapped), and wrote the bare string `'pushover'` into `notification_channels` instead of `PushoverChannel::class` — which `AlertNotification::via()` hands verbatim to Laravel's channel manager, so it never resolved. Every notification threw a swallowed TypeError on the Pushover channel (mail still delivered). Fixed the seeder + added `pushover_key` to `config/kraite-ingestion.php`; live trader rows backfilled on prod + local.
- [CHANGED] **Pin `kraitebot/core 1.51.7`** — `position_opening_failed` throttle now per-account/1h (see core changelog). Live `notifications` row updated on prod + local.

## 1.53.6 - 2026-06-06

### Go-live preparation

- [NEW FEATURE] **Pin `kraitebot/core 1.51.6` — `ProfitableTokensBacktestApprovalSeeder`.** 141 profitable tokens from the operator's 12-month Binance history, ready to backfill `was_backtesting_approved` on production (the approvals were lost in the 2026-05-31 `migrate:fresh`). Run explicitly via `db:seed --class` — not wired into any automatic seed path.

## 1.53.5 - 2026-06-06

### Features

- [NEW FEATURE] **Deferred position-trail retention.** Pins `kraitebot/core 1.51.5`: configurable `kraite.positions.trail_retention_hours` (default 0 = purge-on-close, production intent 24) + the `kraite:cron-purge-position-trails` sweeper. Scheduled daily at 03:20 — after the every-3-hours DB backups have captured the trail, before the 03:30 generic log purges. Config copy + schedule entry + 8 tests (6 sweeper contract, 2 observer retention gate) in this project.

## 1.53.4 - 2026-06-05

### Trading-path bug fixes (first live smoke test)

- [FIXED] **Pin `brunocfalcao/step-dispatcher 1.13.3` — StepObserver no longer clobbers router-resolved queues on `priority='high'` steps.** Every high-priority workflow (closes, corrections, recover-stale promotions) was being pushed to the consumer-less logical `priority` queue since the v1.53.0 naming flip; closes stranded with live limit rungs left on the exchange. Auto-route now fires at creation only.
- [FIXED] **Pin `kraitebot/core 1.51.4` — dispatch daemon idle gate reads per-prefix DB truth, not the default-prefix flag file.** Trading ladders crawled at one index hop per minute (flag-starvation, woken only by the next minute-cron); a full open took ~6 minutes. Verified post-fix: hops 1-4s, full open ~30s.
- Both bugs are present-but-dormant on prod (`can_trade=0`) — this release ships the fixes ahead of go-live. Full forensics in deploy-notes entries 69-70.

### Operations

- [FIXED] **`deploy.sh` no longer wipes `db-backups/` on every deploy.** `git clean -fd` now excludes the directory (`-e db-backups`) and `db-backups/` is gitignored — pre-deploy snapshots accumulate as point-in-time rollback history instead of being destroyed by the next release. Tonight's snapshot was also archived to `/home/athena/db-backups-archive/` to cover the transitional deploy that still runs the old script pre-checkout.

## 1.53.3 - 2026-06-05

### Fleet topology

- [FIXED] **`kraite.horizon.workers.pheme` logical queue renamed `pheme-web` → `web`.** The `{hostname}-{logical}` composer double-prefixed the physical queue to `pheme-pheme-web`; logical `web` now composes to the documented `pheme-web`. Same rename ships in `kraitebot/core 1.51.3` (package-level copy of the workers map) — the drift gate asserts both stay aligned.

### Dependencies

- [CHANGED] **`composer.lock` minor/patch refresh (15 packages).** laravel/framework 12.61.1, horizon 5.47.2, guzzle 7.11.0 (+ promises 2.5.0 / psr7 2.11.0), pest 4.7.2, phpunit 12.5.28, laravel/mcp 0.7.2, boost 2.4.9, aws-sdk 3.384.3, et al. Constraint chain verified internally consistent; zero dev-* additions; kraitebot/core, step-dispatcher, laravel-helpers untouched. Full suite green post-bump (2338 passed, 7471 assertions).

### Housekeeping

- [FIXED] **Retro tag `kraitebot/core v1.51.1` created** — the 1.51.1 release (2026-06-01) shipped with a CHANGELOG entry and a lock-pin commit but the tag itself was never pushed; the sequence jumped v1.51.0 → v1.51.2. Tagged on the original release commit so history matches the CHANGELOG.
- [IMPROVED] **Docs reconciled to live pheme wiring** — per-app Horizon supervisors (admin / console / kraite), `QUEUE_CONNECTION=redis` everywhere, latent default-queue gap + pending `REDIS_QUEUE=pheme-web` fix captured in deploy-notes entry 68; stale queue counts and the obsolete deferred-Horizon posture corrected across servers.json, system-overview, server-preparation, and the syntax site.

## 1.53.2 - 2026-06-02

### Fleet topology

- [IMPROVED] **`kraite.horizon.workers.pheme` block added.** Pheme — the new dedicated web host that joined the fleet on 2026-06-01 — gets a 2-supervisor declaration: `pheme-web` (2 procs, web-originated background jobs) and `pheme` (1 proc, per-hostname connectivity probe slot). Pheme is structurally excluded from the StepRouter candidate pool because its block does not declare any of the trading logical queues (`positions / orders / priority / cronjobs / indicators / user-data-stream`). The drift gate in `kraite:verify-fleet-topology --fail-on-drift` now accepts pheme because the matching `fleet.servers.pheme` entry shipped in `kraitebot/core 1.51.1`.
- [IMPROVED] **`composer.lock` pinned to `kraitebot/core 1.51.2`.** Picks up the package-level `kraite.horizon` block (mirrors ingestion's project-level config) so non-ingestion web apps (admin/console/kraite.com on pheme) see the full topology without a project-level override.

## 1.53.1 - 2026-05-31

### Routing

- [IMPROVED] **`tyche` worker now subscribes to the `priority` lane (5 procs).** Without it, every stale `tyche-cronjobs` / `tyche-indicators` step promoted by `steps:recover-stale --recover-dispatched` (which rewrites `queue='priority'`) routed exclusively to a trading worker (eos/iris/nyx/hemera) because tyche wasn't in the priority candidate pool. That broke the tyche-isolation principle — TAAPI throttler waits + heavy cron fetches could starve real-time trading on the trading workers' priority supervisors. Tyche being in the pool gives it a 1/5 share of promoted steps. Known imperfection: the resolver still picks among the 5 candidates at random, so 4/5 promoted steps continue to leak to trading workers. A full fix would split the logical `priority` queue into per-category lanes (`priority-trading` vs `priority-cron`) so the StepObserver promotion targets the right pool based on the step's original queue — tracked as follow-up.

### Capacity

- [IMPROVED] **`tyche` process counts bumped to handle the realistic indicator + cron load.** `indicators` 10 → 20, `cronjobs` 3 → 20, per-host `tyche` 1 → 5, plus the new `priority` 5. The previous values were the original "single worker, single fan-out" sizing from the early fleet; the actual hourly indicator + per-symbol cron batches have grown well past what 10 + 3 procs can drain inside a one-minute scheduler tick without queue depth accumulating.

## 1.53.0 - 2026-05-31

### Routing

- [BREAKING] **Physical queue convention flipped from `{logical}-{hostname}` to `{hostname}-{logical}`.** A `positions` step now routes to `eos-positions` (not `positions-eos`); `cronjobs` to `tyche-cronjobs`; etc. The flip applies symmetrically to both ends: `Kraite\Core\Support\StepRouter::buildPhysicalQueue()` composes the new shape on dispatch, and the deferred transformer in `CoreServiceProvider::syncHorizonEnvironmentsFromKraiteConfig()` emits matching `horizon.environments` supervisor blocks so every Horizon worker subscribes to the right physical queue. `extractLogicalQueue()` strips the `{hostname}-` prefix on retry passes (was suffix stripping). Pairs naturally with the per-hostname-prefix convention used everywhere else in the fleet (per-host logs, per-host supervisors, per-host backup paths).
- [NEW FEATURE] **`StepRouter::candidateMap()` is now env-aware.** Pool composition by `HORIZON_ENV` (fallback `APP_ENV`): `local` → pool is `[local]` only; `production`-style envs (HORIZON_ENV set to a hostname like `eos`, `athena`) → pool drops the `local` key; `testing` → no filter, the seeded config wins. Without this filter Bruno's Mac would happily pick a prod-only hostname (e.g. `eos`) for a step, push it onto `eos-positions`, and no local Horizon supervisor would ever consume it — the orphan loop that materialised yesterday as 1.96M zombie Redis jobs on the dev box, plus the wedged `kraite:cron-refresh-exchange-symbols` symptom that drove the investigation.
- [IMPROVED] **Routing integration tests (`StepRouterTest`) updated to the new convention** — every assertion that referenced `positions-eos` / `cronjobs-tyche` / etc. now reads `eos-positions` / `tyche-cronjobs`. 14 tests still green.

### Deploy

- [NEW FEATURE] **`deploy.sh` step 11 — force-restart long-running PHP daemons after every deploy.** Long-running `kraite:dispatch-daemon`, Horizon supervisors, and WS streamers (`kraite-stream-binance-prices`, `kraite-stream-binance-user-data`) load class definitions into memory at process start. Without an explicit restart, a code change on disk goes unseen by these daemons even though CLI invocations (artisan, schedule:run children) pick it up immediately. Concrete reproduction 2026-05-31: the queue convention flip above shipped successfully but the local `kraite:dispatch-daemon` (started days earlier) kept dispatching to OLD-convention queues that no Horizon supervisor subscribed to — recover-stale then promoted the stuck steps to the literal `priority` queue (also unconsumed) and the loop accumulated until the Mac ran out of Redis memory. Per-role unit list: ingestion = horizon + dispatch-daemon + 2 WS streamers; everything else = horizon only. Server is cooled down by step 1 so the restart is safe.

### Dependencies

- [IMPROVED] **`kraitebot/core` bumped to v1.51.0** — ships the queue-convention flip and env-aware candidate pool described above. Required for the queue rename to compose correctly end-to-end.

## 1.52.0 - 2026-05-30

### Fleet

- [NEW FEATURE] **`hemera` joins the trading worker pool.** New 7th server (Hetzner CX23, Helsinki HEL1, public IP `77.42.68.254`, private IP `10.0.0.8`). Identical worker shape to eos/iris/nyx: positions (5 procs), orders (8), priority (3), `hemera` per-host queue (1). Same Binance per-IP weight rationale — a fourth distinct IP further spreads exchange API call load. `config/kraite.php` `horizon.workers` block gets the matching supervisor entry; `kraitebot/core` v1.50.0 ships the sibling `kraite.fleet.servers` entry so the drift gate (`kraite:verify-fleet-topology`) stays aligned. Fleet now: 7 boxes (hyperion DB + athena ingestion + eos/iris/nyx/hemera trading workers + tyche indicators).

### Dependencies

- [IMPROVED] **`kraitebot/core` bumped to v1.50.0** — adds hemera to `kraite.fleet.servers` so the rotation engine can translate `77.42.68.254` back to hostname during ban filtering, and the drift gate at deploy step 10 sees the matching servers-table row.

## 1.51.3 - 2026-05-30

### Deploy

- [FIX] **`deploy.sh` re-execs from the freshly-checked-out script body after `git checkout $DEPLOY_TAG`.** Bash reads scripts incrementally from disk, so the checkout would replace `deploy.sh` on disk while bash continued executing the in-memory copy from launch — any new steps added in the deployed tag's script body got silently skipped. Concrete incident 2026-05-30 v1.51.2 rollout: athena was on a pre-step-10 `deploy.sh`, its checkout to v1.51.2 contained the fleet-topology drift gate at step 10, but bash never executed step 10 because it kept reading the in-memory 9-step copy. Workers happened to already be on the 10-step shape from the prior partial deploy so they tripped the gate; athena silently "succeeded" with the gate skipped. New step 3.5 re-execs `bash "$PROJECT_DIR/deploy.sh"` once after the checkout + composer.json swap, guarded by `KRAITE_DEPLOY_REEXECED=1` to cap recursion at depth one. Steps 1–3 are idempotent on the second pass (cooldown verifies STATUS:COOLED_DOWN, composer auth check, git already-at-tag checkout) so the extra ~5s of pre-flight is the only cost. The fix lands properly on the FIRST deploy after v1.51.3 — the v1.51.2 → v1.51.3 transition itself still uses the old in-memory v1.51.2 deploy.sh (which has step 10), so that release ships cleanly without the re-exec.

## 1.51.2 - 2026-05-30

### Dependencies

- [IMPROVED] **`kraitebot/core` bumped to v1.49.0.** Brings the new `KraiteSeeder::seedServers()` that reads from `config('kraite.fleet.servers')` and writes the full fleet roster (local + hyperion + athena + eos + iris + nyx + tyche) to the `servers` table. Replaces the previous helper pair (`productionApiServers()` / `localApiServer()`) which filtered legacy `servers.json` keys (`ingestion / worker-1 / worker-2`) that haven't existed since the 2026-05-24 fleet rebuild, leaving every fresh DB with only a single `gethostname()` row — which in turn caused the `kraite:verify-fleet-topology` drift gate at deploy step 10 to trip on every box but athena.

### Operations

- [FIX] **Drift gate at deploy step 10 now passes on a fresh seed.** Pre-fix, `php artisan migrate:fresh --seed --force` on athena left the `servers` table with only `athena`, which the gate flagged for every other worker key in `config/kraite.php`. Post-fix, the seed writes 7 rows (the full fleet) and the gate reports `Fleet topology aligned`.



### Seeders

- [NEW FEATURE] **`BusinessSeeder::seedBrunoNidavellirTrader()` — non-local seed for `bruno@nidavellir.trade`.** Mirrors the shape of `seedKarineTrader()` but only fires on non-local environments. The previous behaviour was sysadmin-only on production, which left every fresh prod box with no trading user / account at all. Now `php artisan migrate:fresh --seed --force` on athena lands the sysadmin **and** a Binance account belonging to `bruno@nidavellir.trade` — the real trading identity used by the live fleet.
- [IMPROVED] **Karine + Bruno seeded accounts now land with `can_trade=false` AND `is_active=false`.** Previously the Karine account seeded with `is_active=true` and `can_trade` left unset (defaulting to whatever the schema default was). The new explicit `false / false` makes the seed contract obvious: a freshly-seeded account never trades until an operator explicitly flips the gates via the admin panel. Symmetric for Karine (local|testing) and Bruno @ nidavellir (non-local).
- [REMOVED] **`seedKarineTrader()` no longer fires on non-local environments.** The local/testing-only gate is now exclusive — `BusinessSeeder::run()` falls through to `seedBrunoNidavellirTrader()` for production / staging / any non-local env via an early-return after the Karine branch. Same overall guarantee: the sysadmin is the only user every env shares; the trader user differs by env.

### Configuration

- [NEW FEATURE] **`config/kraite-ingestion.php` `bruno_nidavellir` block — env→config bridge for the new trader seed.** Reads `TRADER_BB_BINANCE_API_KEY` + `TRADER_BB_BINANCE_API_SECRET` from `.env`. The `TRADER_BB_*` env block previously carried Bybit credentials too — those are intentionally ignored here. If Bybit ever needs to come back for this user, a sibling account-shape entry in `BusinessSeeder` is the right path, not fattening this config block.
- [IMPROVED] **`config/kraite-ingestion.php` `karine` block comment updated** — wording now says "local-only smoke trader; production seeds bruno_nidavellir instead", matching the actual seeder behaviour after this release.

### Operations

- [NEW FEATURE] **`.env.traders` local credential vault (gitignored).** New file at the project root carries the full inventory of all 5 trader blocks (TRADER_BB, TRADER_KRAITE, TRADER_B, TRADER_KC, TRADER_BG) — identity, pushover keys, and exchange credentials. The `.env` itself now only carries the two prefixes the running seeder actually consumes (`TRADER_B` for Karine on local|testing, `TRADER_KRAITE` for the sysadmin identity overlap). Keeps the active `.env` tight and operational while the wider credential inventory stays available for future seeder code paths without scattering the secrets.
- [IMPROVED] **`.gitignore` — `.env.traders` added explicitly.** The existing `*.env` pattern only matches files ending in `.env`; `.env.traders` ends in `.traders` so it fell through without an explicit entry. Belt-and-suspenders catch.
- [IMPROVED] **`TRADER_BB` block renamed in `.env.traders` from "Bruno Falcao (Binance+Bybit)" to "Bruno Falcao (Binance)"** and the two `TRADER_BB_BYBIT_*` lines dropped from the vault. This trader is now Binance-only by design; the rename + drop keeps the credential surface honest.

### Documentation

- [IMPROVED] **`WhereAreWe.md` rewritten as a 2026-05-30 session snapshot.** Captures the docs drift sweep + the seeder rework + the .env restructure. Outdated 2026-05-24 fleet-rebuild content moved out (still in git history for anyone who wants to read the rebuild story).
- [IMPROVED] **`~/Herd/docs/kraite/*` reconciled with production reality** — fleet cost (€69.16), PHP-FPM pool path (PHP 8.5), Horizon process counts (positions 5 / orders 8 / priority 3 / indicators 10 / cronjobs 3 / `<hostname>` 1 — total 71 procs across the 5 Horizon boxes), 03-logs ghost-folder references removed from the README index, notification-routing-audit re-stamped as a snapshot with a "shipped since" supplement. Syntax site (`~/Code/syntax.kraite.test/`) gets the same process-count refresh across server + subsystem pages.



### Features

- [NEW FEATURE] **`deploy.sh` step 10 — fleet-topology drift check.** Added after `config:cache` and before `horizon:terminate` (which respawns the workers). Runs `php artisan kraite:verify-fleet-topology --fail-on-drift --quiet-on-success` (the command ships in kraitebot/core v1.48.0). Drift here means a key in `config('kraite.horizon.workers')` has no matching `servers.hostname` row — when that happens, the StepRouter cannot translate banned IPs back to hostname candidates and ban filtering silently fails for the drifted worker. The check exits 1 on drift, which under `set -Eeuo pipefail` aborts the whole deploy BEFORE supervisors respawn against a broken config. Catches the "added a worker to config but forgot to insert the servers row" mistake every time.
- [NEW FEATURE] **`config/kraite.php` is now the single source of truth for fleet topology** — the new `horizon` block (under keys `defaults` + `workers`) declares which logical queues each worker subscribes to with what process counts. The StepRouter (added in kraitebot/core v1.48.0) reads this same block to derive routing candidates, eliminating the previous two-map drift risk that would silently wedge steps in queues with no consumer.
- [IMPROVED] **`config/horizon.php` is now a thin transformer** that reads `config('kraite.horizon')` and emits one `environments` block per worker, with each supervisor's queue subscription composed as `{logical}-{hostname}` (e.g. `positions-eos`). The hostname's own queue (where logical name == hostname) is NOT suffixed — matches `StepRouter::buildPhysicalQueue()`. Adding a worker = one edit to `config/kraite.php`; both files update in lockstep.
- [REMOVED] **Legacy `ingestion` / `worker1` / `worker2` environment blocks** in `config/horizon.php` deleted. They referenced the pre-2026-05-24 6-box fleet (athena / apollo / ares / artemis / zeus / helios) and were dead config — no live box has had `APP_ENV` set to any of those values since the fleet rebuild.

### Tests

- [NEW FEATURE] **`tests/Integration/Routing/StepRouterTest.php`** — 14 integration tests pinning the new dispatch-time queue-routing engine. Covers: routing to a per-hostname queue when no bans exist; ban filtering removes a single banned worker from the candidate set; system-wide bans (`account_id IS NULL`) apply to every account on the same api_system; cross-exchange isolation (Binance ban doesn't affect Bybit routing); expired bans (`forbidden_until` in the past) are ignored; orchestrator (no-account) steps skip the ban filter; unknown logical queues return null (defer to step-dispatcher's default); `account_blocked` short-circuits to terminal cascade without rotation; all-permanent-banned terminal cascade fires the `account_all_workers_blacklisted` notification + deactivates the account; temporary-only ban exhaustion returns null (no deactivation, retry naturally); strip-suffix recovers logical category on retries (`positions-eos` → `positions`); unknown hostname suffix is NOT stripped.

### Tests — restructured

- [REMOVED] **`tests/Integration/Rotation/WorkerIpRotationTest.php`** deleted — covered the v1.47.0 pickup-time rotation engine in `BaseApiableJob::compute()`, which has been removed. Same outcomes are now tested in `StepRouterTest` from the dispatch-time entry point.
- [REMOVED] **`tests/Integration/ForbiddenHostname/ForbiddenHostnameBlockingTest.php`** deleted — its 9 cases tested compute()-time pre-flight ban detection, which has been removed in the kraitebot/core v1.48.0 architectural shift. The ban-filtering behaviours are covered more comprehensively by `StepRouterTest` (which targets the dispatch-time layer where routing decisions now live).
- [IMPROVED] **`tests/Integration/Notifications/ForbiddenHostnameNotificationTest.php`** — dropped the `Notification Deduplication` describe block (single test) for the same reason; the per-ban observer dedup intent is exercised in `ForbiddenBanTtlTest` via the updateOrCreate upsert behaviour, and the terminal-cascade notification dedup is exercised in `StepRouterTest`. The 5 other describe blocks (User Notifications, Admin Notifications, Notification Data) are untouched and still pass.
- [IMPROVED] **`tests/Integration/Rotation/ForbiddenBanTtlTest.php`** retained as-is — pins the 1-hour TTL on `ip_not_whitelisted` and `account_blocked` rows + the upsert-refresh-no-double-notify behaviour. Both are orthogonal to where routing decisions live, so they survive the architectural shift unchanged.

## 1.50.0 - 2026-05-25

### Tests

- [NEW FEATURE] **`tests/Integration/Rotation/WorkerIpRotationTest.php`** — five tests pinning the worker-IP rotation engine added to `BaseApiableJob::compute()` in `kraitebot/core` v1.47.0. Covers: (a) rotation re-dispatches a step to a clean worker's per-hostname queue when the current IP is banned, (b) no-op when the current IP is clean, (c) terminal failure + account deactivation when every fleet IP is exhausted, (d) `account_blocked` short-circuits straight to deactivation without attempting rotation, (e) the `account_all_workers_blacklisted` portfolio-at-risk notification fires to the account owner on the deactivation cascade.
- [NEW FEATURE] **`tests/Integration/Rotation/ForbiddenBanTtlTest.php`** — three tests pinning the new 1-hour TTL stamped onto `ip_not_whitelisted` and `account_blocked` `forbidden_hostnames` rows (was `null` / sticky-forever pre-rotation), plus the upsert refresh behaviour on re-detection (refreshes `forbidden_until`, leaves the row id stable, doesn't re-fire `ForbiddenHostnameObserver::created()`).

### Test-spec updates

- [IMPROVED] **`tests/Integration/ForbiddenHostname/ForbiddenHostnameBlockingTest.php`** — the two assertions for permanent ban types (`ip_not_whitelisted`, `account_blocked`) updated from "step stays Pending (retry)" to "step is Failed + account is deactivated", matching the v1.47.0 spec change. Temporary ban tests (`ip_rate_limited`, `ip_banned` with expiry, system-wide) unchanged — those still retry naturally because their bans auto-recover.
- [IMPROVED] **`tests/Integration/Notifications/ForbiddenHostnameNotificationTest.php`** — the "no duplicate notification" dedup test now also asserts the new `account_all_workers_blacklisted` notification fires once on the second step's deactivation cascade. The per-ban `server_account_blocked` dedup intent still holds.

## 1.49.9 - 2026-05-24

### Documentation

- [FIX] **WhereAreWe.md — athena server-prep stanza now reads `php8.5-fpm` instead of `php8.4-fpm`.** Matches the production Ubuntu 26.04 LTS / PHP 8.5 reality already documented elsewhere in the same file. Single-line doc drift left over from the v1.49.8 PHP 8.5 sweep.

## 1.49.8 - 2026-05-24

### Config

- [FIX] **PHP 8.5 deprecation cleared in `config/database.php`.** Replaced the unnamespaced `PDO::MYSQL_ATTR_SSL_CA` constants on the `mysql` and `mariadb` connection blocks with the namespaced `Pdo\Mysql::ATTR_SSL_CA` equivalent. PHP 8.5 logs a deprecation notice on every connect for the old form; PHP 9.x would drop the symbol entirely. Production fleet (Ubuntu 26.04 LTS, PHP 8.5) + local dev (Herd 8.5) now connect deprecation-clean.

### Housekeeping

- [REMOVED] **Dropped `scripts/ingestion-scheduler.conf`.** Leftover Ploi-era supervisor stub from a previous hosting generation — referenced `/home/ploi/...` paths and `user=waygou`, neither of which exist on the current 5-box Hetzner fleet. The live scheduler is a system cron under `/etc/cron.d/kraite-scheduler` per the 2026-05-24 fleet rebuild; the stub was dead on disk.

## 1.49.6 - 2026-05-22

### Deploy pipeline

- [NEW FEATURE] **`composer.production.json` is now the source of truth for production dependencies.** The repo ships two manifests:
  - `composer.json` — dev-time path repos (`../packages/*`) + `dev-master` constraints. Used by Bruno's local Mac (symlinked packages).
  - `composer.production.json` — VCS repos on GitHub + versioned constraints (`^1.36`, `^1.12`, etc.) + `minimum-stability: stable`. Used by every production server.
  `deploy.sh` now swaps `composer.production.json` over `composer.json` after `git checkout $DEPLOY_TAG`. The previous `/tmp/deploy-composer.json` backup-restore dance is gone.
- [FIX] **Server-local composer.json drift is over.** Old flow kept the production manifest only on the server (backed up to `/tmp` before each `git reset`, restored afterwards) — meaning any change to the dev manifest in the repo (e.g. dropping `app/helpers.php` from autoload, removing `laravel/ui`) never reached production. Drift only surfaced when something blew up at runtime. New flow makes the production manifest a tracked file reviewed on every PR.
- [FIX] **`laravel/ui` removed from the production manifest.** It was already gone from the repo's `composer.json` in v1.49.4 but the server-local prod manifest still carried it as `^4.6`. Next deploy uninstalls it cleanly.
- [FIX] **`autoload.files: ["app/helpers.php"]` removed from the production manifest.** Same root cause as `laravel/ui` — repo dropped it in v1.49.4, server-local prod manifest kept it. This was the failure that crashed the v1.49.5 deploy mid-run on athena (composer post-autoload-dump tried to `require` a deleted file).

### Documentation

- [NEW FEATURE] `deploy.sh` header docblock now describes the two-manifest pattern explicitly so future operators know which file is the source of truth.

## 1.49.5 - 2026-05-22

### Tests

- [REFACTOR] Extracted inline `TestableWebsocketClient` and `FakeWsClient` test helpers out of their host test files into properly-namespaced `Tests\Support\` classes so other specs can reuse them without redefining global classes.
- [REFACTOR] `TieredStrategyTest` now instantiates the strategy via `ReflectionClass::newInstanceWithoutConstructor()` instead of an anonymous subclass — keeps `TieredStrategy` `final` (pint policy) without breaking the unit test.

### Code quality

- [TYPES] Typed `RouteBackupEventToSystemHealthAlert::THROTTLE_SECONDS` constant (PHP 8.3 typed constants) so project-wide type-coverage stays at 100%.
- [LINT] Applied pint formatting fixes across 18 files (final_class, fully_qualified_strict_types, mb_str_functions, no_unused_imports, no_blank_lines_after_phpdoc, concat_space, no_extra_blank_lines, phpdoc_align).
- [LINT] Applied rector's `AddClosureVoidReturnTypeWhereNoReturnRector` across 114 test closures so the lint gate stays green.

### Tooling

- [FIX] Restored `patches/laravel-boost-https-fix.patch` (it had been truncated to zero bytes; sha256 now matches `patches.lock.json` so `composer update` no longer reports a broken patch).
- [FIX] Dropped two non-existent paths from `rector.php` (`./packages`, `./resources`) left over from earlier project layouts — `composer test:lint` no longer aborts before rector runs.

## 1.49.4 - 2026-05-17

### Fixes

- [BUG FIX] Seeded users are now marked `status=active` during `migrate:fresh --seed` instead of falling back to pending.
- [BUG FIX] Business seeding now includes Karine's trader account in the testing environment so console browser tests have deterministic account fixtures.

### Tests

- [NEW FEATURE] Added seeder coverage for Resend API key persistence on the shared Kraite credentials row.

### Dependencies

- [DEPENDENCIES] `kraitebot/core` path-package reference bumped to `5e15c70`.

### Removed (dead-code sweep)

- [REMOVED] **Frontend leftover island.** `resources-backup/` (63 files of dead Blade views/css/js/images), `app/helpers.php` (`theme()` + `theme_map_color()` — only consumed by the dead views), and `config/theme.php` (only consumer was the dead helper) all deleted. `app/helpers.php` removed from `composer.json` autoload `files`.
- [REMOVED] **Empty scaffold directories**: `app/{Actions,Console,Enums,Models,Services}/`, `tests/Fixtures/`, `lang/` (held only `vendor/backup/` translations from spatie/laravel-backup). All gone — `.gitkeep` placeholders removed.
- [REMOVED] **Browser testsuite** — `tests/Browser/WelcomeTest.php` was a single test whose entire body sat inside a `/* */` comment block. Suite wiring stripped from `tests/Pest.php` (`->in('Browser', ...)`) and `phpunit.xml` (`<testsuite name="Browser">`).
- [REMOVED] **`app/Providers/HorizonServiceProvider.php`** — class defined the `viewHorizon` Gate but was never registered in `bootstrap/providers.php`, so the Gate definition never ran.
- [REMOVED] **`routes/web.php`** — contained only `declare(strict_types=1);`. `bootstrap/app.php` `withRouting()` no longer references it.
- [REMOVED] **`laravel/ui`** composer require — zero `Laravel\Ui\*` imports anywhere in ingestion or any consumed package.
- [REMOVED] Stale `phpunit.xml` source exclude entries (`app/Mail/AlertMail.php` — file no longer exists; `app/Support/Tests/` — needed only when EchoJob shipped under a coverage-excluded path).
- [REMOVED] Unread `kraite.indicators.jobs_per_index_batch` config key from `config/kraite.php`.

### Improvements

- [IMPROVED] **`app/Support/Tests/EchoJob` docblock** now warns about the FQCN-string coupling from `StepDispatcher\Database\Factories\StepFactory` (which references `'App\Support\Tests\EchoJob'` as a string literal — IDE/refactor tools will NOT pick up renames).

## 1.49.3 - 2026-05-16

Infrastructure-only patch on top of v1.49.2: fixes the new pre-migration mysqldump backup against the `kraite` MySQL user's privilege set.

### Infrastructure

- [BUG FIX] **`deploy.sh` mysqldump now passes `--no-tablespaces` and drops `--events`.** The `kraite@%` MySQL user lacks the `PROCESS` privilege (required by MySQL 8's default tablespace dump) and the `EVENT` privilege. On the v1.49.2 athena deploy mysqldump exited non-zero with "Access denied; you need the PROCESS privilege" before writing any rows — the new hard-gate aborted the deploy as designed (no migrations ran). `--no-tablespaces` skips the tablespace dump explicitly; `--events` is dropped because the kraite schema doesn't declare scheduled events anyway. `--single-transaction --routines --triggers` still produce a consistent snapshot with stored routines + triggers.

## 1.49.2 - 2026-05-16

Infrastructure-only patch on top of v1.49.1: fixes a `composer install` failure that surfaced during the v1.49.1 athena deploy.

### Infrastructure

- [BUG FIX] **`deploy.sh` runs `composer update` for the four path packages BEFORE `composer install`.** Pre-fix, the shipped `composer.lock` carries `kraitebot/core` + the three `brunocfalcao/*` packages as `dev-master` (because locally they resolve via path repos). Only `kraitebot/core` has a `branch-alias` (`dev-master → 1.x-dev`); the three brunocfalcao packages do not, so their `dev-master` lock entry does not satisfy production constraints `^6.0` / `^1.12` / `^1.0`. `composer install` aborts with "Required package … is in the lock file as dev-master but that does not satisfy your constraint …". Running `composer update <named-packages>` first regenerates those four lock entries with their tagged versions, after which `composer install` is a clean no-op. No application-code change.

## 1.49.1 - 2026-05-16

Infrastructure-only patch: hardens the pre-migration DB backup that runs inside `deploy.sh` on the ingestion role (athena). No application-code change, no migration, no behavioural shift on the trading path.

### Infrastructure

- [IMPROVED] **Pre-migration DB backup is now a HARD GATE.** `deploy.sh` writes the snapshot to `$PROJECT_DIR/db-backups/pre-deploy-YYYYMMDD_HHMMSS.sql.gz` (was `storage/backups/...`), keeping every historical backup in a flat directory at the project root — easier to find for operator rollback than burrowing into Laravel's storage tree. If `mysqldump` exits non-zero, OR the resulting gzip is smaller than 1KB (catches the "connection OK but no dump privileges" silent-empty case), the deploy aborts BEFORE `php artisan migrate --force` runs. A migration can no longer execute without a fresh, restorable snapshot on disk.
- [IMPROVED] **mysqldump flags expanded** from `--single-transaction` to `--single-transaction --routines --triggers --events`. Captures stored routines, triggers, and scheduled events alongside the table data so a restore from `db-backups/` reconstitutes the full schema — not just rows.

## 1.49.0 - 2026-05-16

Bug-fix + small-feature roll-up. Catches a lapsed-subscription edge case that stranded orphan positions, tightens the Larastan surface on the new waitlist migration, removes the last risky / skipped test from the suite, and lays the schema groundwork for user avatars. Full suite: 2319/2319 green, 0 risky, 0 skipped, 0 Larastan errors.

### Features

- [NEW FEATURE] **`add_avatar_to_users_table` migration** — adds a nullable `avatar` VARCHAR(2048) column to `users` after `email`. Pure schema groundwork for the upcoming avatar upload / OAuth-avatar-import flow; no application code consumes the column yet.

### Fixes

- [BUG FIX] **Larastan errors on `add_status_to_users_table` migration eliminated** by importing `Illuminate\Database\Query\Builder` and typing the inner `where()` closure parameter as `Builder $query`. The previous untyped `function ($query)` left PHPStan inferring `mixed`, which made `whereNotNull` / `orWhere` look like calls on `mixed` and tripped `method.nonObject` twice.
- [TEST FIX] **`T08ExceptionTypesTest::it Cleans laravel.log`** now asserts `expect(true)->toBe(true)` so Pest no longer flags the laravel.log-cleanup helper as a risky test (no-assertions). Brings the file in line with every sibling `T0X` cleanup helper.
- [TEST FIX] **`B2DiskRetryConfigTest` S3Client boot test no longer skipped.** Replaces the `markTestSkipped` guard with `config()->set()` of fake B2 disk credentials + `Storage::forgetDisk('b2')`. The SDK only validates config shape during boot — no network call is made — so any non-empty values exercise the same code path as production credentials. Boots one more test out of skipped status.

### Dependencies

- [DEPENDENCIES] `kraitebot/core` path-package reference bumped to **v1.46.2** — the orphan-position recovery path in `CreatePositionsCommand` now runs BEFORE the `isReadyToTrade()` subscription gate, so a lapsed subscription stops stranding existing `status='new'` positions whose `DispatchPositionJob` step was swept.

## 1.48.0 - 2026-05-15

Drops the ingestion-local Coupon/CouponUser models + AttachPrivateBetaCoupon listener (they live in kraitebot/core v1.46.0 now). Pure cleanup release — Pest spec still 31/31 green after rewriting imports to the new namespace.

### Improvements

- [IMPROVED] **Deleted** `app/Models/Coupon.php`, `app/Models/CouponUser.php`, `app/Listeners/AttachPrivateBetaCoupon.php`. The kraitebot/core copies are the single source of truth — the listener now implements `ShouldQueue` so dispatches from kraite.com (which couldn't see this app's `App\Listeners` namespace before) finally route through the Redis queue to ingestion's Horizon workers and attach the coupon.
- [IMPROVED] **Test imports rewritten** from `App\Models\{Coupon,CouponUser}` + `App\Listeners\AttachPrivateBetaCoupon` to the `Kraite\Core\*` namespaces. 31/31 Pest tests still pass.

### Dependencies

- [DEPENDENCIES] `kraitebot/core` path-package reference bumped (v1.45.0 → v1.46.0 — listener + models lifted, Event::listen wired).

## 1.47.0 - 2026-05-15

Locks the kraitebot/core v1.45.0 event-on-activation contract with Pest. No schema change.

### Tests

- [NEW FEATURE] **`tests/Feature/AccountActivationDispatchesPreparePositionsTest.php`** — 4 Pest tests covering the new `AccountObserver::updated` behavior: fires on the false→true transition, does NOT fire when can_trade was already true, does NOT fire when gate 3 (subscription) fails, and is deduped against an already-pending step for the same account.

### Dependencies

- [DEPENDENCIES] `kraitebot/core` path-package reference bumped (v1.44.0 → v1.45.0 event-on-activation observer).

## 1.46.0 - 2026-05-15

Pairs with kraitebot/core v1.44.0 — the BillingManager / SubscriptionState facade and the new `Account::isReadyToTrade()` 3-gate. This release locks the consumer surface with a Pest spec; no schema change.

### Tests

- [NEW FEATURE] **`tests/Feature/AccountIsReadyToTradeTest.php`** — 11 Pest tests covering the 3-gate matrix: BillingManager / SubscriptionState wiring, `isActive` polarity vs `isInClosingMode`, paused-state, past-anchor, trial-active-without-wallet, and every single-gate-flips-to-false scenario for `Account::isReadyToTrade()`.

### Dependencies

- [DEPENDENCIES] `kraitebot/core` path-package reference bumped (`633b0f1` → v1.44.0 billing facade + Account readiness gate).

## 1.45.0 - 2026-05-15

Plan rename `starter` -> `basic` to match the registration-flow copy locked during the private-beta onboarding elicitation.

### Improvements

- [IMPROVED] **`rename_starter_subscription_to_basic` migration** updates the entry-tier row in `subscriptions` in place — `canonical: starter -> basic`, `name: Starter -> Basic`. Idempotent (down() reverses). FK references (`users.subscription_id = 1`) keep working because the row is renamed, not replaced.

## 1.44.0 - 2026-05-15

Adds the public-facing `users.uuid` column used by the new admin.kraite.com registration-completion URL. Pairs with kraitebot/core v1.43.0 (auto-stamp at create) and kraite.test v0.10.0 (verify-link redirect target).

### Features

- [NEW FEATURE] **`users.uuid` column** (char(36), unique, NOT NULL). Backs the `admin.kraite.com/register/{uuid}` URL that private-beta confirmers land on after clicking the verify link from kraite.com. Migration runs in three phases — nullable add, per-row `Str::uuid()` backfill via `lazyById()`, then NOT NULL + unique index — so existing rows never trigger a constraint violation during the ALTER. Down() drops the unique index then the column.

## 1.43.0 - 2026-05-15

Listener path migration to the now-shared event class. Pairs with kraitebot/core v1.42.0 (event lifted into the package) and kraite.test v0.9.0 (firing site live).

### Improvements

- [IMPROVED] **`AttachPrivateBetaCoupon` listener** now type-hints `Kraite\Core\Events\UserEmailConfirmed` instead of the ingestion-local `App\Events\UserEmailConfirmed`. Test imports follow.
- [IMPROVED] **Local `app/Events/UserEmailConfirmed.php` removed** — the event is now shipped from `kraitebot/core` so kraite.com (the firing site) and ingestion (the listener) share a single class definition.

### Dependencies

- [DEPENDENCIES] `kraitebot/core` path-package reference bumped (`19882e7` → v1.42.0 — shared `UserEmailConfirmed` event).

## 1.42.0 - 2026-05-15

Foundation for the always-on, structural Coupon system. Phase 1: schema + entity + auto-attach listener. Billing-side integration (`User::billing()->topUp()`, bonus-line emission per coupon, `CouponUserObserver` mail dispatch) lands in a follow-up.

### Features

- [NEW FEATURE] **`coupons` table.** Stand-alone discount template entity. Columns: `slug` (unique machine key), `name`, `description`, `type` (`percentage` | `absolute`), `value` (decimal 14,4), `valid_from`, `valid_until` (nullable — open windows), `max_usage` (global attachment cap, nullable = unlimited), `max_usage_per_user` (per-user redemption cap, nullable = unlimited), `is_active` (operator hard switch). No soft-deletes — used coupons are immortal per the audit rule.
- [NEW FEATURE] **`coupon_user` pivot table.** Permanent attachment ledger. One row per `(user_id, coupon_id)` pair (unique). Per-attachment `valid_from`/`valid_until` window, a `usage_count` counter, `attached_at`, `last_used_at`. Both FKs use `restrictOnDelete()` per the project-wide no-cascade rule. Pivots never detach — active state is derived from columns.
- [NEW FEATURE] **`kraite.in_private_beta` flag.** Global on/off switch on the singleton `kraite` row. When `true`, the `AttachPrivateBetaCoupon` listener auto-attaches the seeded private-beta coupon on `UserEmailConfirmed`. Defaults to `true` on existing row at migration time so the current cohort gets coverage; flip to `false` once private beta ends.
- [NEW FEATURE] **Seeded `private_beta_25` coupon.** Type `percentage`, value `25.0000`, no end date, no usage caps. The canonical reward attached on every confirmed email during the private-beta era. Idempotent `updateOrInsert` on slug.
- [NEW FEATURE] **`App\Models\Coupon`** with `globallyActive` query scope + `isGloballyActive()` instance method (mirrors of the same gates) — covers `is_active`, `valid_from`/`valid_until` window, and `max_usage` budget. `bonusFor(string $sourceAmount)` returns the BCMath bonus string for any source amount, respecting `type`. Singleton accessor `Coupon::privateBeta()` for callers.
- [NEW FEATURE] **`App\Models\CouponUser`** custom pivot exposing `isActive()` — derived from pivot window + parent coupon's `max_usage_per_user` cap against the row's `usage_count`. Designed for Phase-2 observer mail dispatch on `created`.
- [NEW FEATURE] **`App\Events\UserEmailConfirmed`** — fired the moment a user's `email_verified_at` flips from null to a timestamp. Carries `userId` only (listeners refetch the model to see latest DB state).
- [NEW FEATURE] **`App\Listeners\AttachPrivateBetaCoupon`** — auto-discovered listener handling `UserEmailConfirmed`. Attaches the private-beta coupon iff `kraite.in_private_beta = true`, the user exists, the seed row exists, and the user does not already have it. Wrapped in a `DB::transaction` with a `lockForUpdate()` on the candidate pivot row so concurrent fires of the same event race-safely produce at most one attachment.

### Tests

- [NEW FEATURE] **`tests/Feature/AttachPrivateBetaCouponListenerTest.php`** — 5 Pest tests: attaches when flag on, no-op when flag off, idempotent on repeat-fire, never leaks across users, no-op gracefully when the seed row is missing.
- [NEW FEATURE] **`tests/Feature/CouponActiveStateTest.php`** — 11 Pest tests covering the full active-check matrix at both global (Coupon) and per-user (pivot) layers, plus the `bonusFor()` BCMath shape for both `percentage` and `absolute` types, plus the `privateBeta()` singleton accessor returning the seeded row.

### Open for Phase 2 (intentionally not in this release)

- `User::billing()->topUp($x)` funnel emitting paid line + per-coupon bonus lines under a shared `transaction_uuid`.
- `User::billing()->rollback($transaction_uuid)` emitting negative mirror lines (append-only).
- `CouponUserObserver` dispatching the per-coupon notification canonical on pivot `created`.
- Wiring `kraite.test`'s `PrivateBetaController` to fire `UserEmailConfirmed` after `email_verified_at` is persisted (needs the event class accessible from kraite.test — likely lifted into `kraitebot/core` at that point).
- `coupons_applied` generic notification canonical + match arm in `kraitebot/core` for the multi-coupon batch attachment mail.

## 1.41.0 - 2026-05-15

Coordinated rename of onboarding notification canonicals from `waitlist_*` to `private_beta_*`. Pairs with kraitebot/core v1.41.0 (match arms + body text) and kraite.test v0.8.0 (marketing surface).

### Improvements

- [IMPROVED] **New migration `rename_waitlist_canonicals_to_private_beta`** updates the two onboarding rows in `notifications` in place — `waitlist_email_verification` → `private_beta_email_verification`, `waitlist_welcome_password_reset` → `private_beta_welcome_password_reset` — refreshing description / detailed_description / usage_reference to the new "Kraite private beta" wording. Rows renamed in place so existing `notification_logs` FK references remain valid. Reversible via `down()`.

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
