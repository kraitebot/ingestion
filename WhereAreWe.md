# WhereAreWe — 2026-05-03 (one-way-mode live verification + dispatcher-tick watchdog disabled)

## Date
2026-05-03

## Session summary

Capstone day on a long-running session. Today's work shipped in
three releases (1.17.0 → 1.17.1) and verified end-to-end via a
live walkthrough on Karine Esnault's one-way-mode Binance account.

1. **Float → BCMath migration packs 11-12 + nano-pack** —
   `SupportResistanceProximity`, `Position::daily_variation_percentage`,
   `AssignBestTokensToPositionSlotsJob` direction filter,
   `DriftCheckService` direction + tolerance helpers,
   Bybit/Bitget/Kucoin recoverer non-zero filters,
   `RegimeCalculator::futVolHot` accumulator,
   `PriceVolatilityIndicator::volatilityPercent`,
   `MarketShockCircuitBreaker::priorBarPct`. Twelve new tests
   added inline.
2. **`indicators_synced_at` skip-stamp fix** — the
   `same_indicator_data` skip branch now stamps the attempt time
   alongside the existing concluded / exhausted / path-invalid
   branches. Resolves the LINKUSDC false-positive stale alert
   that ran for nearly a full day on long-timeframe (1d) symbols.
3. **Dispatcher-tick stale watchdog disabled** — signal mismatch.
   `steps_dispatcher.last_selected_at` updates only on root-step
   CREATE, not on every dispatch tick. Between minute-level
   crons MAX naturally exceeds the 60s threshold. Commented out
   in `CheckSystemHealthCommand` runner array with re-enable
   condition documented (per-tick stamp column on
   `steps_dispatcher` in the dispatcher path package).
4. **One-way-mode live verification** — five live scenarios on
   Karine's ONDOUSDT LONG #188: LIMIT modify, TP modify, SL
   modify, LIMIT + SL concurrent delete, TP delete. All five
   end states matched the canonical reference price + qty.
   Step dispatcher carried every restore. WAP one-way path
   confirmed by code inspection + `CalculateWapBuildPositionKeyTest`
   coverage (no live test needed — Karine's account is the
   regression case the test was authored from).

## Current state

### Test suite
- Pest: **1578 passing / 0 failing** (1 risky, 6 incomplete, 4
  todos — all pre-existing). Suite duration ~225s.

### Releases
- `kraitebot/core` 1.17.0 — float→BCMath packs 1-12 + nano-pack
  + `indicators_synced_at` skip-stamp fix.
- `kraitebot/core` 1.17.1 — dispatcher-tick stale watchdog
  disabled.
- `ingestion.kraite.com` 1.17.0 — bumps core, adds 13 new
  tests. composer.lock bumped + pushed for 1.17.1.

### Float-cast inventory
- Started session at: 107 (after packs 1-10 carried in).
- Ended session at: **91**.
- Remaining 91 = legitimate KEEP candidates (display coercion,
  log-using statistics, return-type contracts, downstream
  consumer cascades, final API surfaces).

### Production state
- Horizon terminated mid-session twice — once for the conclude
  job-class change (skip-stamp), once for the dispatcher-tick
  watchdog change. Supervisor respawn confirmed both times.
- One-way-mode push path verified live on real money (Karine's
  Binance account) across LIMIT / PROFIT-LIMIT / STOP-MARKET
  modify + cancel paths, plus a concurrent dual-cancel.

## WIP / mid-task

None. Session is at a clean checkpoint. Migration paused at 91
casts, all live verification passed, all three releases pushed.

## Pending items

1. **Dispatcher-tick stale watchdog re-enable** — needs a
   per-tick stamp column on `steps_dispatcher` in the
   `brunocfalcao/step-dispatcher` path package, written by every
   dispatch attempt regardless of work. Migration + writer +
   watchdog read swap. Standalone PR scope.
2. **Daily `BINANCE_NO_CHANGE` / `BYBIT_NO_CHANGE` Token
   Delisting notifications** — source still unknown. Test-leak
   hypothesis disproved. Need Bruno to forward the next received
   message (title + body + sent_at + channel) so the origin can
   be traced.
3. **`indicator_stale_<PAIR>` cardinality** (carry-over) —
   currently fires per ExchangeSymbol row, so one token across 4
   exchanges produces 4 alerts. Direction is shared via
   copy-phase; should reduce to per-token. Low priority.
4. **Carry-over from prior sessions** — database backup
   strategy, daemon GAP 5 sharding, backtesting approval
   workflow, AERGO-style degraded-position recovery command.

## Key decisions made this session

- **Stop the float→BCMath migration at 91, not 0.** Remaining
  casts encode design contracts (display coercion, log-using
  statistics, return-type surfaces, cascading consumer
  contracts). Forcing more either changes message format,
  introduces float→BCMath→float roundtrip noise, or pulls in a
  multi-file cascade refactor that's out of pack-of-ten scope.
- **Dispatcher-tick fix is its own change.** Disabling the
  false-positive check today, the proper fix (per-tick stamp
  column) lands as a standalone PR against the step-dispatcher
  path package, not mixed with the float migration packs.
- **`indicators_synced_at` is a liveness signal, stamped on
  every successful end-to-end run.** All four success branches
  (concluded, exhausted, path-invalid, skip) stamp it.
- **Skip live WAP test on one-way mode.** Code path
  (`CalculateWapAndModifyProfitOrderJob::buildPositionKey()`)
  and test coverage (`CalculateWapBuildPositionKeyTest` +
  `CalculateWapSnapshotLookupTest`) already cover Karine's
  account explicitly — the test was authored from the
  `:BOTH`-key bug Karine's account surfaced.
- **Test-fix for `_NO_CHANGE` notifications was wrong premise.**
  CI uses stub Pushover creds, no daily test cron, zero
  `token_delisting` rows in `notification_logs`. Test changes
  reverted.
- **Float coerces trailing zeros, string preserves them.**
  Pinned by writing a baseline test for `NotificationMessageBuilder`
  display blocks and watching `-2.10` vs `-2.1` diverge under
  string coercion. Display-block casts stay as `(float)`.

## Float→BCMath migration — running ledger

| Pack | Sites | Net removed | Suite | Notes |
|---|---|---|---|---|
| 1-10 (carried) | ~91 | ~91 | green | order mappers, recovery, billing, exchange info |
| 11 | 10 | 8 | green | SR, daily-variation, AssignBest BOTH, drift normalizeDirection, Bybit recoverer |
| 12 | 9 | 7 | green | Drift tolerance, Bitget/Kucoin recoverers, futVolHot, volatility |
| nano | 2 | 1 | green | MarketShockCircuitBreaker priorBarPct |
| **Total** | **~112** | **~107** | **1578 ✓** | 198 → 91 |

## One-way-mode live verification — running ledger

| # | scenario | path | restore time |
|---|---|---|---|
| 1 | LIMIT modify | `ORDER_TRADE_UPDATE` AMENDMENT → `CorrectModifiedOrderJob` | ~2s |
| 2 | TP modify | `ORDER_TRADE_UPDATE` AMENDMENT → `CorrectModifiedOrderJob` | ~2s |
| 3 | SL modify | `ALGO_UPDATE` cancel-and-replace lifecycle | ~6s |
| 4 | LIMIT + SL concurrent delete | merged `SmartReplaceOrdersJob` workflow with parallel `RecreateCancelledOrderJob` fan-out | ~9s |
| 5 | TP delete | `ORDER_TRADE_UPDATE` CANCELED → `SmartReplaceOrdersJob` lifecycle | ~7s |
