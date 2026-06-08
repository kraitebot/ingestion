# WhereAreWe — 2026-06-07 (release v1.54.0 / core v1.52.0 — athena 2nd-IP kline lane + runtime config)

## Date

2026-06-07

## Session summary

Shipped a full fleet release. Headline change: **athena now runs a secondary
`indicators` Horizon pool (10 procs)** so the kline/indicator lane has two
outbound public IPs (athena + tyche) instead of one. This is the structural
fix for the Bybit `retCode 10006` rate-limit bursts that were hitting tyche —
StepRouter now spreads the per-IP kline burst across both IPs and can rotate
the lane off a rate-limited IP. Verified live: indicators steps split 50/50
across `tyche-indicators` and `athena-indicators` immediately after deploy.

Also bundled the runtime-configuration work built earlier in the session.

## What shipped

**kraitebot/core v1.52.0**
- Per-account `respect_bscs` (open positions without waiting on the BSCS cooldown).
- Runtime-configurable `kraite` singleton: `can_trade` master kill-switch,
  `notifications_enabled`, `td_correlation_type`, correlation/elasticity
  computation toggles, trail-retention hours — all with normally-open fallbacks.
- Per-account token-discovery gates: `use_correlation_sign_filter`,
  `use_btc_bias_restriction`.
- Global→account notification cascade (global gate + per-user opt-out; Critical
  always delivered).
- Dispatcher-group drain recheck — `VerifyDispatcherGroupDrainedJob` injected
  +15min on a stall to report whether the group drained (Info) or is still
  stalled (High).
- PnL aggregates now sum exchange-reported `positions.pnl` (was the inflated
  `profit_percentage/100 × margin`).
- Removed dead `CAN_TRADE` / `CAN_OPEN_POSITIONS` env keys.

**ingestion v1.54.0**
- athena `indicators => 10` in `kraite.horizon.workers`.
- TDD coverage for all the core runtime-config features (5 new feature tests).

## Release facts

- Tests: step-dispatcher 72/72, ingestion 2372 passed (0 failures). CI green.
- Deploy: all 6 boxes on v1.54.0 / core v1.52.0. athena ran 5 migrations
  (additive — `respect_bscs` + token-discovery gates on accounts, runtime cols
  on kraite, `notifications_enabled` on users, drain-recheck notification seed)
  behind a 707M pre-deploy DB backup hard-gate.
- Health: all supervisors RUNNING, dispatch healthy, 0 failed steps.
- athena indicators-supervisor confirmed consuming `redis:athena-indicators`.
- Docs: raw specs (`~/Herd/docs/kraite/`), servers.json, kraite-profile/-deploy
  commands, and the syntax site (servers/athena, servers/tyche,
  subsystems/horizon-queues) all refreshed; syntax.kraite.com live (HTTP 200).

## Key architecture notes (still true)

- Throttle budget is NOT raised by the 2nd IP — `bybit_throttler` /
  `taapi_throttler` are single global Redis buckets keyed without IP, so fleet
  Bybit admission stays ~82/5s. The win is burst de-concentration + failover.
- The deeper fix (atomic throttle reservation — the canDispatch/recordDispatch
  race) remains parked by Bruno as "too complicated at this moment."
- Non-Binance klines are NOT dead work — each exchange's symbols compute their
  own correlation/elasticity/direction from that exchange's candles (the
  dashboard semaphores). Never gate kline fetch by active account.

## Open / deferred

- Atomic throttle reservation fix (parked).
- `priority-trading` vs `priority-cron` split (long-standing follow-up).
