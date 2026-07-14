# WhereAreWe — 2026-07-14 (FILUSDT stuck-WAP fix + Scope 2b self-heal; recovery fleet fan-out)

## Date

2026-07-14

## This release (2026-07-14)

- **FILUSDT stuck-WAP incident fixed, two layers (core):** Binance
  omits `avgPrice` on modify responses for never-filled orders — the
  unguarded read crashed the WAP TP-resize AFTER Binance had accepted
  it, the observer's correction then reverted the resize, and position
  #394 sat with a TP covering 47.3 against a 141.9 exchange position.
  (1) modify + query mappers now null-safe on `avgPrice` (cancel was
  already guarded). (2) New drift-spotter **Scope 2b self-heal**:
  every 5 min, `active` positions whose FILLED entry ladder exceeds
  the resting NEW TP quantity get ApplyWapJob re-dispatched
  (observer-identical dedupe; failed steps don't block; mid-flight
  statuses skipped; no quiet window; runs while cooled;
  `--skip-wap-heal` kill switch; `position_wap_self_healed` pushover).
  FIL heals automatically on the first post-deploy spotter pass.
  Deploy-notes Entry 104.
- **Disaster recovery v2 — fleet fan-out (core):** default execution
  now dispatches one per-account recovery job across the workers with
  a shared API throttle + settle-poll (`--poll-timeout-minutes`);
  `--inline` keeps the v1 single-box sequential path (dry-run always
  forces it). Docs: 02-features/disaster-recovery.md.
- **Same night, non-release:** prod DB was cloned onto localhost; the
  Mac's always-on engine fought the real Binance account over the
  cloned positions (401s blocked it — IP not whitelisted). Local DB
  wiped + reseeded; prod untouched. Lesson in Entry 104.

---

# WhereAreWe — 2026-07-13 (public registration live on kraite.com; money-guard; Black plan)

## Date

2026-07-13

## This release (2026-07-13)

- **Public registration wizard shipped on kraite.com** (private beta
  retired): profile → fleet-wide connectivity test (hard gate) →
  trading-currency pick (live balances, BFUSD-aware) → plan pick
  (Basic/Unlimited, immediate 7-day trial, soft underfunded warning)
  → automatic exchange scan + one-time-token auto-login handoff to
  admin.kraite.com. Admin's invite registration surface deleted.
- **Sizing safety coupling (core):** `allow_other_positions=true` now
  forces margin sizing onto available-balance — a user's own locked
  capital is never counted. The wizard flips the switch automatically
  when its scan finds pre-existing positions/orders.
- **Black plan:** the zombie free "Starter" row (seeder resurrection
  after the 2026-05-15 basic rename) is now deliberate — canonical
  `black`, free forever, uncapped, invite-only, hidden from the
  wizard. Seeder seeds basic/unlimited/black correctly now.
- **Trading money-guard (other session, ships with this release):**
  CheckDrifts Scope 3+4 cooling contract — deterministic triggers
  (broken structure, failed-position burst, failed-step storm,
  exchange-error storm) halt opens globally, write a monitoring/
  incident + direct Pushover, alarm-once latch; `kraite:monitor-narrate`
  (Haiku, every 20min) documents open incidents, never decides.

---

# WhereAreWe — 2026-07-12 (SME code-review batch #2: workflow-engine hardening — 12 fixes)

## Date

2026-07-12

## v1.62.0 release (2026-07-12) — Sol Ultra code-review batch #2 (core 1.66.0, step-dispatcher 1.17.0)

Second external SME review (GPT-5.6 "Sol Ultra"), this one aimed at the
workflow ENGINE: 12 of 16 findings shipped, 4 discarded with evidence.
Deploy-notes Entry 100 has the full record. Where Entry 99 was signal +
security + ops, this batch is engine correctness — the failure modes are
duplicate exchange orders and phantom positions, so it matters more:

- Retried orchestrators can no longer duplicate a half-built child chain
  (atomic + idempotent build across all 6 position orchestrators) — the
  worst case was a second market entry on a live account.
- A Skipped parent with a Dispatched child no longer wedges the dispatcher
  group forever (step-dispatcher: new skip transitions + honest progress).
- Ladder builds are atomic (no more phantom NEW orders), positions can't
  get stuck in `new` looping the open-fail-cancel cycle, concurrent order
  recreation is locked + unique-indexed, Bitget correction dedupe sees its
  own class, Bitget TP/SL picks the live sibling not a cancelled ghost,
  WAP follow-up ack is transactional, a filled TP persists+closes instead
  of reverting to a phantom-active position, and the dead verifyPrice
  contract is gone.

Ships a schema migration (orders.recreated_from_order_id → unique), run
on athena after the pre-deploy backup; prod carried zero duplicate
lineage rows so it applied clean. Live positions rode through untouched.


## Prior: Date

## v1.61.0 release (2026-07-11) — code-review batch (core v1.65.0)

Adversarial adjudication of a 6-report external SME review: 7 of 10
findings shipped, 2 discarded with evidence, 1 parked. Deploy-notes
Entry 99 has the full record. Headline fix (the only one touching a
trading decision): direction conclusion now has a same-run provenance
gate — a partial TAAPI refresh can no longer blend this-hour and
last-hour indicators into a phantom direction that drives position
opening. Plus pre-multi-user security hardening (connectivity authz
via AccountPolicy, ZeptoMail replay dedup, CSRF exact-URIs — zero
exposure today, single-user) and ops resilience (watchdog stale-mutex
cap, retry_after 90→900, destructive-schedule overlap guards). Parked
still: atomic TAAPI throttle reservation (efficiency-only, feature-
sized). Live positions carried through the deploy untouched, as always.


## Prior: Date

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

## v1.59.0 release (2026-07-11)

Live-window cascade detection LIVE fleet-wide: shock breaker evaluates
rolling 1-min mark-price samples (offsets 15/60, persistence 2 ticks),
kline path as fallback + kill switch (MARKET_SHOCK_LIVE_WINDOW).
Replay evidence: ~/blackswan/reports/fast-breaker-replay-20260711.txt.
market_price_samples buffer table migrated on athena. Verified post-
deploy: samples growing 5/min, detector Completed each minute,
insufficient_series during first-hour buffer fill (expected), no false
cooldown. Admin v0.16.0 (Positions page) on pheme — deploy stalled
mid-npm on an SSH client timeout (heartbeat went silent → watchdog
paged "pheme no live metrics"); resumed detached, warmed, heartbeat
re-seeded via kraite:fleet-report --seed. Lesson: pheme deploy blocks
run detached (nohup + log) from now on. 30-min SMOKE WATCH cron active
(catastrophe authority: stop bot + close positions, pre-authorized).

## v1.60.0 release (2026-07-11) — BSCS drawdown floor

Research session (~/blackswan/reports/signal-candidates-20260711.txt):
- Drawdown floor SHIPPED (core v1.64.0): BTC >=15% below ~21d high
  floors hourly score at Fragile. 4/6 events at T-6h incl. the missed
  Jun-2022 (36.6%). ~zero calm phantoms. Floor-only semantics; kill
  switch MARKET_REGIME_DRAWDOWN_FLOOR. Live verified: value_pct=2.11%,
  floor dormant (correct — calm market).
- Perp basis KILLED by data: 1/6 events, calm noise above signal —
  funding rate's failure mode repeated. Not implemented.
BSCS now covers three horizons: days (hourly score+ramps), bleed
regimes (drawdown floor), minutes (live-window breaker).
