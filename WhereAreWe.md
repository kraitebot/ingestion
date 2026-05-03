# WhereAreWe — 2026-05-03 (Float→BCMath migration packs 11-12 + nano-pack + indicator skip-stamp fix)

## Date
2026-05-03

## Session summary

Continuation of the multi-pack `(float)` → BCMath migration that
started after the orphan-cleanup commit (which laid down the
`before-float-transformations` checkpoint tag). Today's session
shipped:

1. **Packs 11-12 of the float→BCMath migration** —
   `SupportResistanceProximity`, `Position::daily_variation_percentage`,
   `AssignBestTokensToPositionSlotsJob` direction filter,
   `DriftCheckService` direction + tolerance helpers,
   Bybit/Bitget/Kucoin recoverer non-zero filters,
   `RegimeCalculator::futVolHot` accumulator,
   `PriceVolatilityIndicator::volatilityPercent`. Twelve new tests
   added inline (seven for daily-variation accessor, five for
   volatility indicator).
2. **MarketShockCircuitBreaker nano-pack** — single low-hanging
   fruit found during the post-pack-12 analysis: the
   `priorBarPct` percent-move helper. Two casts removed, one net
   off after the boundary float at the `?float` return.
3. **Indicator-staleness watchdog skip-stamp fix.** Identified
   while debugging a stale-indicator alert on LINKUSDC: the
   `same_indicator_data` skip branch in
   `ConcludeSymbolDirectionAtTimeframeJob` was the only successful
   end-to-end path that did not stamp `indicators_synced_at`.
   Long-timeframe symbols (1d) sat past the 90-minute watchdog
   threshold for nearly the full day even though the pipeline ran
   every cron tick and correctly decided "nothing new". Fixed by
   stamping the attempt time alongside the existing exhaustion +
   path-invalidation branches. Regression test added.
4. **Notification troubleshoot — three issues investigated.** Stale
   indicators (root cause: skip-branch missing stamp — fixed,
   above). Stale dispatcher tick (root cause: signal mismatch —
   `last_selected_at` only updates on root-step CREATE, not on
   every dispatch tick; punted to its own change because it needs
   a new tick-stamp column on `steps_dispatcher`). Daily "Token
   Delisting Detected" for `BINANCE_NO_CHANGE` /
   `BYBIT_NO_CHANGE` — initial test-leak hypothesis disproved
   (CI uses stub Pushover creds, no daily test cron, zero
   `token_delisting` rows in `notification_logs`); origin still
   unknown, source-trace blocked on Bruno forwarding the next
   message body. Test changes from the disproved hypothesis were
   reverted.

## Current state

### Test suite
- Pest: **1578 passing / 0 failing** (1 risky, 6 incomplete, 4
  todos — all pre-existing). Suite duration ~225s.
- Twelve new tests added this session:
  - 7 × `tests/Feature/Concerns/Position/PositionDailyVariationAccessorTest.php`
  - 5 × `tests/Unit/Indicators/PriceVolatilityIndicatorTest.php`
  - 1 × `tests/Feature/Jobs/ConcludeSymbolDirectionAtTimeframeJobTest.php`
    (`stamps indicators_synced_at on skip when indicator data is unchanged`)

### Float-cast inventory
- Started session at: 107 `(float)` casts (after packs 1-10).
- Ended session at: **91** `(float)` casts.
- This session removed: 16 net (21 sites converted, 5 net new
  boundary casts at return-float surfaces).
- Cumulative since the migration started: 198 → 91 (107 net
  removed across all packs).

### Production state
- Horizon terminated mid-session after the conclude-job-class
  change so workers reload with new bytecode; supervisor respawn
  confirmed.
- Pre-refactor `before-float-transformations` git tag still in
  place on both `kraitebot/core` and `ingestion.kraite.com` as a
  rollback point for the migration as a whole.

## WIP / mid-task

None. Migration paused at pack 12 + nano-pack on Bruno's call
("don't kill the cow in one go"). Remaining 91 casts classified
in detail and held as KEEP — see
`~/docs/kraite/03-logs/2026-05-03_float_to_bcmath_migration.md`
for the full categorisation.

## Pending items

1. **Dispatcher-tick stale watchdog signal mismatch** (queued).
   The `dispatcher_tick_stale` health check reads
   `steps_dispatcher.last_selected_at` and assumes it updates on
   every tick, but it only updates when `getNextGroup()` is
   called (root-step creation). Between hourly conclude bursts
   the MAX naturally exceeds the 60s threshold and fires
   false-positive alerts. Fix needs a new tick-stamp column on
   `steps_dispatcher` written by every dispatch attempt
   regardless of work — touches the `brunocfalcao/step-dispatcher`
   path package, so it's a separate change.
2. **Daily `BINANCE_NO_CHANGE` / `BYBIT_NO_CHANGE` Token Delisting
   notifications** — source still unknown. Need Bruno to forward
   the next received message (title + body + sent_at + channel)
   so the origin can be traced. Production code path for
   `token_delisting` confirmed inactive: zero matching rows in
   `notification_logs` since 2026-05-02; all `delivery_ts_ms`
   writes are perpetual-default which the mapper rejects.
3. **`indicator_stale_<PAIR>` cardinality** (carry-over from
   prior session) — currently fires per ExchangeSymbol row, so
   one token across 4 exchanges produces 4 alerts. Direction is
   shared via copy-phase; should reduce to per-token. Low
   priority.
4. **Carry-over from prior sessions** — database backup strategy,
   daemon GAP 5 sharding, backtesting approval workflow,
   AERGO-style degraded-position recovery command.

## Key decisions made this session

- **Stop the float→BCMath migration at 91, not 0.** The
  remaining casts encode design contracts (display coercion,
  log-using statistics, return-type surfaces, cascading consumer
  contracts). Forcing more either changes message format,
  introduces float→BCMath→float roundtrip noise, or pulls in a
  multi-file cascade refactor that's out of pack-of-ten scope.
- **Float coerces trailing zeros, string preserves them.**
  Pinned by writing a baseline test for the
  `NotificationMessageBuilder` display blocks and watching
  `-2.10` vs `-2.1` diverge under string coercion. Conclusion:
  display-block casts stay as `(float)`. Test was deleted after
  the assertion since the conclusion was "leave as-is".
- **`indicators_synced_at` is a liveness signal, stamped on
  every successful end-to-end run.** Adds the skip branch to
  the existing concluded / exhausted / path-invalid branches.
  Without this, every long-timeframe symbol mid-day looks like
  a pipeline outage to the watchdog.
- **Dispatcher-tick fix is its own change.** New column on
  `steps_dispatcher` + writer in the dispatcher path package +
  watchdog read swap. Cleaner as a standalone PR than mixed in
  with the float migration packs.
- **Test fix for `_NO_CHANGE` notifications was wrong premise.**
  The leak hypothesis (test creates fixture before
  `Notification::fake()`) was disproved by the CI workflow
  inspection (stub Pushover creds) and the production
  `notification_logs` evidence (zero `token_delisting` rows).
  The test changes were reverted.

## Float→BCMath migration — running ledger

| Pack | Sites | Net removed | Suite | Notes |
|---|---|---|---|---|
| 1-10 (carried) | ~91 | ~91 | green | order mappers, recovery, billing, exchange info |
| 11 | 10 | 8 | green | SR, daily-variation, AssignBest BOTH, drift normalizeDirection, Bybit recoverer |
| 12 | 9 | 7 | green | Drift tolerance, Bitget/Kucoin recoverers, futVolHot, volatility |
| nano | 2 | 1 | green | MarketShockCircuitBreaker priorBarPct |
| **Total** | **~112** | **~107** | **1578 ✓** | 198 → 91 |

Ledger numbers are approximate for packs 1-10 (carried context).
The hard count from
`grep -rh "(float)" .../core/src | wc -l` is authoritative and
currently reads 91.
