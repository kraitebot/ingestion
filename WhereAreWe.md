# WhereAreWe — 2026-05-03 (Token-scoring rewrite + push-primary cutover)

## Date
2026-05-03

## Session summary

A two-part session: morning operational rollout of the user-data
stream as production primary; afternoon TDD-driven rewrite of the
token-selection scoring stack.

The morning leg flipped the user-data WS daemon from "shadow + selective
opt-in" to "push primary, polling safety net" after live-verifying
push-driven WAP end-to-end (LIMIT fill → ApplyWapJob Step created in
the same wall-clock second, full WAP cycle 35s end-to-end against the
prior 15-minute polling worst case). The manual-close detection branch
fired correctly three times. Allowlist extended from 7 to 8 exec
types (added `ALGO_FILLED`); polling cadence tightened from 15 min
to 5 min.

The afternoon leg added three pure-math multipliers to selection
scoring driven by Bruno's read of the APE 131 SHORT post-mortem (right
setup, wrong timing, BTC-bottomed-out the moment we entered):

- `LogElasticityScorer` — log-compresses elasticity so freak high-
  amplitude tokens stop dominating tokens with stronger correlation.
- `CorrelationStabilityWeight` — penalises symbols whose rolling-
  window correlation series is jittery vs ones that hold a steady
  signal across windows.
- `BatchDiversificationPenalty` — downscores a candidate whose 1d
  correlation profile is too close to any symbol already picked in
  the same selection batch, forcing structural variety across the
  6-LONG / 6-SHORT book.

All three were TDD-driven (21 unit tests written red first → helpers
implemented green → wired into selection). New `btc_correlation_stability`
JSON column on `exchange_symbols`, populated by `CalculateBtcCorrelationJob`.
Backfilled klines (5 candles → 200 candles per timeframe per symbol)
and re-dispatched correlation jobs to populate stability for 557 of
558 Binance symbols.

Live-verified end-to-end via ONDO LONG (id 188) winning a freshly-
freed slot on account 1 with the new scoring stack active.

## Current state

### Test suite
- 21 new unit tests in `tests/Unit/Support/TokenScoring/` — all green.
- 182 / 182 passing across token-discovery + correlation + exchange-
  symbol suites — no regression.
- Existing user-data-stream / WAP / position-lifecycle suites
  unchanged this session, last fully run on 2026-05-02.

### Deploy state
- `kraitebot/core` working tree carries the TokenScoring helpers,
  HasTokenDiscovery rewrite, CalculateBtcCorrelationJob stability
  capture, and the new migration. Pending commit + push.
- `ingestion.kraite.com` working tree carries today's new unit tests
  + WhereAreWe + CHANGELOG (after push-primary cutover earlier).
  Pending commit + push.

### Production data
- Account 1: 6 / 6 LONG + 6 / 6 SHORT live, including ONDO 179 (the
  scoring-verification pick).
- 557 / 558 Binance symbols populated with `btc_correlation_stability`.
  Non-Binance exchanges still null (no native kline series; they
  receive correlation values via Binance copy).
- 921K total candles in DB after the kline backfill.

### Configuration applied earlier this session
- `USER_DATA_STREAM_BINANCE_DISPATCHED_EXECUTIONS` =
  `TRADE, AMENDMENT, CANCELED, EXPIRED, ALGO_NEW, ALGO_CANCELED,
  ALGO_EXPIRED, ALGO_FILLED`
- `kraite:cron-sync-orders` schedule: `*/5 * * * *`
- `position_creation.symbol_override` reverted to commented-out
- ETC + JUP `exchange_symbols` columns reverted to original values
  via raw query builder (no observer cascade)
- Migration `2026_05_03_000100_add_btc_correlation_stability_to_exchange_symbols`
  applied to prod DB

### Supervisord
All 6 kraite programs RUNNING. Ingestion Horizon respawned twice
this session (once for the TRADE allowlist flip, once for the
TokenScoring code reload).

## WIP

None.

## Pending items

1. **Commit + push the afternoon's TokenScoring work** (current step).
2. **Asymmetric elasticity per BTC regime** — current elasticity is
   computed across the whole window without conditioning on BTC
   direction. A bull-market 15× elasticity may be 3× in a bear
   regime. Storing per-regime values would require schema work and
   a backtest justification.
3. **Recent-loss memory** — a token that hit SL last week gets re-
   picked at full score. Decay multiplier on recent-failed tokens
   proposed but unspecified.
4. **Soft sign filter** — currently hard-reject in BTC-bias mode.
   Soft 0.2 multiplier would let high-conviction outliers survive.
   Deferred.
5. **Fast-track BTC-sign cross-check** — fast-tracked symbols skip
   scoring; if BTC regime flipped between close and re-entry, fast-
   track can be wrong-side. Direction-only sign check on fast-track
   candidates proposed but unspecified.
6. **Carry-over from prior sessions** — database backup strategy,
   indicator alert auto-resolve verification, daemon GAP 5 sharding,
   backtesting approval workflow, AERGO-style degraded-position
   recovery command.

## Key decisions made this session

- **TDD discipline preserved through the helper rewrite.** All three
  helpers landed via failing tests first, then green implementation,
  then integration into the trait. 21 new unit tests + 0 regressions
  in the wider 182-test suite.
- **Pure-math helpers under `Support/TokenScoring/`, not inline in
  `HasTokenDiscovery`.** Keeps each multiplier independently testable
  and lets the trait's selection methods read as a clean composition.
- **Diversification compares 1d correlation only.** Short-timeframe
  correlation is too noisy; 1d is the most stable view and the
  closest analogue to "this token tracks BTC daily." Threshold (0.10)
  and minimum penalty (0.5) are hard-coded; config knob can land if
  production data shows the defaults need tuning.
- **Hard sign filter preserved.** Soft-penalty alternative deferred —
  the hard filter has not generated notable false rejections.
- **Stability is the std-dev of the full sliding-window correlation
  series**, independent of the rolling-method (`recent` / `average` /
  `weighted`) used for the headline rolling value. Stability measures
  dispersion, not the headline.
- **Push primary, polling 5-min safety net** (morning leg).
- **Liquidations explicitly out of scope** (morning leg) — no
  `CALCULATED` exec type, no liquidation-aware scoring multiplier,
  no `position_liquidated` notification canonical.
- **Symbol override remains a test-only knob** (carried over). Used
  only when rehearsing WAP / close / drift flows on a known token.
