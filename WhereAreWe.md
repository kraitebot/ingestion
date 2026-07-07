# WhereAreWe — 2026-07-08 (backtest-grade cap + TAAPI 404 fix release v1.58.0)

## Date

2026-07-08

## Current fleet state

v1.58.0 release in flight (tag + deploy this session):

- **ingestion** — **v1.58.0** (this release)
- **kraitebot/core** — v1.62.0 (ships 1.60.0 + 1.61.0 + 1.62.0)
- **brunocfalcao/step-dispatcher** — v1.15.0 (ships 1.14.x + 1.15.0)
- Fleet ran v1.57.0 / core 1.59.0 before this release.

## This release (v1.58.0 / core 1.62.0 / step-dispatcher 1.15.0)

Bundles three core releases and one step-dispatcher release that were
tagged across 2026-07-06/08 sessions:

- **Backtest grade capped by the decision band (core 1.61.0).** The
  percentage-weighted score diluted absolute stop failures on large
  samples (16 stops / ~1400 sims still graded B while the proposal
  said reject). Now >10 stops grades at best D, 5–10 at best C.
- **Sizing-skipped sims counted + `days_to_ignore` in meta (core
  1.60.0)** — feeds the evidence floor on the admin backtesting page.
- **Fleet heartbeat carries the running core version (core 1.62.0)**
  — admin deploy panel can see rollout drift without SSH.
- **TAAPI 404 "no candle data" is a legitimate no-data answer (core
  1.62.0).** New exotic-quote Binance listings (BTC/U, ETH/USD1,
  DATAIP/USDC…) hard-failed the verification probe hourly (80-92
  failed steps/day). 404 + "no candle data" now marks
  verified-with-no-data, same as the 400 shape; other 404s still
  fail. Regression: `TouchTaapiDataForExchangeSymbolJobIgnoreExceptionTest`
  (4 cases). Deploy-notes Entry 96.
- **step-dispatcher 1.15.0** — canonical `workflowState(uuid)`
  aggregation + `WorkflowState` enum; 1.14.x DB-engine portability +
  pgsql identifier quoting; suite now 192 Feature tests.
- Routine vendor refresh (aws-sdk, laravel 12.63, peers).

## Product state (unchanged this release)

Tradeable Binance pool is deliberately 3 tokens (ATOM/AVAX/BNB, all
SHORT as of 2026-07-07) — Bruno curates enablement one-by-one via the
admin backtesting console. 131 more candidates pass every automatic
gate and await his review. Low count is intentional, never a bug.

## Key architecture notes (still true)

- The scheduler skips EVERYTHING in maintenance mode — any watchdog
  scheduled through the same scheduler it monitors is blind to
  scheduler-wide failure modes. At least one check must run
  `evenInMaintenanceMode()`.
- "Reconnect forever" is availability, NOT recovery — long-lived
  ReactPHP daemons need a sustained-failure self-exit so a fresh
  process can clear a loop-level wedge.
- TAAPI/Bybit throttle budget is NOT raised by the 2nd IP — single
  global Redis buckets keyed without IP.
- Notification Threshold counting is per `(notification, relatable)`
  over held rows; throttler must be loose for a threshold to fire.
- Non-Binance klines feed per-exchange semaphores — never gate by
  active account.
- Fleet is 10 boxes: hyperion (DB+Redis), athena (ingestion), pheme
  (web), eos/iris/nyx/hemera/palaemon/aristaeus (interchangeable
  trading workers), tyche (indicators+cronjobs).
- Providers can signal one semantic outcome via multiple status
  codes — gate on (code, body-pattern) pairs, and never let a
  "couldn't check" path re-select forever (Entry 96).

## Open / deferred (long-standing, not blocking)

- Atomic throttle reservation fix (parked — would zero the 429s).
- `priority-trading` vs `priority-cron` split.
- `SyncLeverageBracketJob` bulk wave watchdog spikes — smaller bulks.
- Bybit `min_delay_ms` dead knob.
- Thread table prefix into `StaleStepsDetected` notification.
- Out-of-band scheduler dead-man on hyperion (proposed post-Entry-93,
  Bruno declined for now — runbook + sentinel layers deemed enough).
- No backtesting chapter on the syntax docs site — the v1.61.0 grade
  cap landed in `domains/token-selection`; a dedicated chapter needs
  Bruno's call.
