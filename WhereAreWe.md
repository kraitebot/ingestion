# WhereAreWe — 2026-07-10 (GO-LIVE + reference-symbol purge exemption v1.58.3)

## Date

2026-07-10

## Current fleet state

**LIVE TRADING SINCE 2026-07-10 00:27.** Bruno's Binance account
(account 1) is active: 6 LONG + 6 SHORT slots, 5% margin per position,
20x/15x leverage, divider 32. First positions: BCH/FIL/QNT LONG +
CC SHORT — all verified 100% synced against Binance via
DriftCheckService (28/28 orders exact).

v1.58.3 release in flight (avgPrice cancel-mapper fix):

- **ingestion** — v1.58.3 (this release)
- **kraitebot/core** — v1.62.2 (purge exemption + CandleFactory schema fix)
- **brunocfalcao/step-dispatcher** — v1.16.1 (unchanged)
- Fleet ran v1.58.1 / core v1.62.0 before this release.

## This release (v1.58.2 / core 1.62.1)

**Reference-symbol candle-purge exemption** — the go-live blocker fix.
Rejecting BTC in admin backtesting caused the daily failed-backtest
kline purge to delete BTC's entire price history; BTC is the alignment
series for every correlation/elasticity computation, so token selection
silently starved (zero positions opened, no error, stop_reason null).
The purge now exempts the BTC reference token + market-regime basket
regardless of review status. NULL-asset rows still purge (three-valued
NOT IN guard). 5 regression tests. Also fixes CandleFactory schema
drift (candle_time → candle_time_utc/local). Deploy-notes Entry 97.

**First deploy with open positions.** Cooldown/warmup with live
real-money positions on the exchange: positions live exchange-side
during the maintenance window; user-data stream + 5-min polling sync
catch up any fills that land mid-deploy. Post-warmup drift check is
mandatory before calling the release done.

## Product state

- Account 1 live. Owner billing RESOLVED 2026-07-10: Bruno's
  `subscription_renews_at` pinned to 2038-01-01 (TIMESTAMP column
  ceiling — the practical "unlimited"). Closing-mode gate reads the
  future anchor → subscription permanently active; renewal cron skips
  future anchors → no wallet debits, no pre-warn pages. Trial expiry
  Jul 17 is now irrelevant (gate falls through to the anchor).
- BTC/USDT sits approved + trading flags OFF on all exchanges —
  protects its candles, keeps it untradeable. Never re-reject it.
- Tradeable pool ~15 tokens and growing as Bruno approves in admin.
- SHORT slots fill only when BTC signal flips or negative-correlation
  candidates exist — sign filter working as designed, not a fault.

## Key architecture notes (still true)

- The scheduler skips EVERYTHING in maintenance mode; at least one
  health check runs `evenInMaintenanceMode()`.
- "Reconnect forever" is availability, NOT recovery — strict-data
  daemons self-exit for supervisor respawn.
- TAAPI/Bybit throttle budget is NOT raised by the 2nd IP.
- Non-Binance klines feed per-exchange semaphores.
- Fleet: hyperion (DB+Redis), athena (ingestion), pheme (web),
  eos/iris/nyx/hemera/palaemon/aristaeus (workers), tyche (indicators).
- Reference symbols are infrastructure, not trading candidates —
  cleanup keyed on trading verdicts must exempt them (Entry 97).
- Providers signal one outcome via multiple status codes — gate on
  (code, body-pattern) pairs (Entry 96).

## Open / deferred

- ~~Owner-account subscription expiry~~ RESOLVED 2026-07-10: renewal anchor set to 2038-01-01 (TIMESTAMP column ceiling) — subscription permanently active, renewal cron skips future anchors, no wallet debits. Revisit in 2037 :)
- Populate stop_reason on silent workflow stops (assign job returned
  null on the Entry-97 failure — cost diagnosis time).
- Atomic throttle reservation fix (parked).
- `priority-trading` vs `priority-cron` split.
- Bybit `min_delay_ms` dead knob.
- Thread table prefix into `StaleStepsDetected` notification.
- No backtesting chapter on syntax site (grade cap lives in
  domains/token-selection).

## Parked (brainstorm 2026-07-10/11)

- **Public track-record dashboard on kraite.com** — exchange-reported
  PnL (existing exchange-pnl pipeline) → daily-updated public page:
  equity curve, green/red calendar (ALWAYS both — no cherry-picking),
  stats, methodology + disclaimer block. Track-record account flag so
  experiments never pollute the series. Start the series on a clean
  month AFTER alpha ends; never restart once public. Later: monthly
  statements + read-only API due-diligence offer.
- **Subscription tiers decided**: Light 75 USDT/mo (ONE exchange
  account, portfolio 2.5K min – 10K max; min protects fee/profit
  ratio), Unlimited 150 USDT/mo. Portfolio-cap enforcement not built.
- **BSCS correlated-crash stress test** — IN PROGRESS (session
  2026-07-11): can the regime brake react faster than 6 same-direction
  ladders fill in a violent BTC move; worst-case book loss under
  current config (~12%/fully-laddered slot at SL).
- **Cascade rung retraction + guarded SL re-anchor** (parked
  2026-07-11, stress-test outcome): on shock-breaker fire, cancel the
  UNFILLED last rung (half the ladder capital) per crash-side
  position; re-anchor SL to "3% below deepest live rung" ONLY when
  that lands safely below mark — else keep existing SL (never tighten
  toward mark in a cascade). On all-clear, restore rung only if still
  below mark. Tail math: ambush 6-long book ~74% today → ~29% with
  this (Bruno's last-rung-only choice). MUST be replayed against the
  6 historical events in backtest sim before shipping. Bruno parked:
  accepts current risk profile for now.
