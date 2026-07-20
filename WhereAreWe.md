# WhereAreWe — 2026-07-20 (Bitget unified accounts + own-activity protection)

## Date

2026-07-20

## This release (2026-07-20)

- **Bitget unified (UTA) accounts are first-class for onboarding and reads:**
  the account's mode (classic vs unified) is detected automatically on first
  contact and cached; balance, positions, open orders, and the
  withdrawal-safety check route to the correct Bitget API generation.
  Unified accounts register and are fully readable but cannot trade until
  the v3 order surface ships (hard readiness gate).
- **A user's own trading can never be destroyed by the bot:** the
  `allow_other_*` protection flags are now evidence-driven in both
  directions — synced from live exchange state by the health watchdog
  (before any cleanup decision) and by the position-opening chain (before
  any sizing). A user position appearing on a Kraite-exclusive account
  enables protection instead of being auto-closed; activity disappearing
  restores exclusive mode. A standing user limit order counts as positions
  scope. The Binance ghost-algo scrub is skipped on shared accounts.
- **Shared accounts still get leftover hygiene:** provable Kraite leftovers
  (locally closed within the match window, still alive on the exchange) are
  flat-closed even with protection on.
- **Registration failures speak human:** the wizard names the exact missing
  permission, whitelist gap, or key rejection per probe, never hangs on a
  dead orchestrator, and stale connectivity bans can no longer poison a
  retry (diagnostic steps bypass the ban veto).
- **Throttling survives warm Redis:** a framework quirk returning cached
  integers as strings crashed Bitget bursts; normalised and
  regression-tested.

---

# WhereAreWe — 2026-07-19 (mobile BSCS visibility)

## Date

2026-07-19

## This release (2026-07-19)

- **The trader can see global market risk on the iPhone dashboard:** one
  full-width BSCS tile shows the score, band, current posture, freshness, block
  state, and block threshold.
- **The mobile API remains bounded and read-only:** it exposes the actionable
  regime summary without sub-signal composition or cooldown internals, and it
  adds no trading control.

---

# WhereAreWe — 2026-07-19 (read-only mobile API)

## Date

2026-07-19

## This release (2026-07-19)

- **Mobile authentication is isolated and revocable:** valid trader
  credentials issue a 30-day device token limited to dashboard reads. Logout
  revokes only the current device token, and failed logins are rate-limited.
- **Mobile data stays trader-owned:** the API returns only the authenticated
  trader's accounts. A foreign account identifier receives the same missing
  response and never reveals ownership.
- **The first API surface is deliberately read-only:** account switching,
  dashboard KPIs, and open positions are exposed through bounded, briefly
  cached responses. Trading, account edits, billing, and engine controls are
  absent.

---

# WhereAreWe — 2026-07-19 (safe Bitget opening and API-key onboarding)

## Date

2026-07-19

## This release (2026-07-19)

- **Bitget openings begin from live exchange truth:** each USDT or USDC
  opening reads the current position mode before changing margin, leverage, or
  orders. Hedge and one-way orders use the V2 field shape expected by Bitget.
- **Bitget protection is retry-safe:** combined TP and SL identities exist
  before the exchange mutation, so a worker retry resumes the same protection
  instead of creating duplicates.
- **Bitget pacing covers shared exchange limits:** every request respects its
  endpoint tier, a shared private endpoint budget, and the aggregate source-IP
  ceiling. Signed retries wait and receive fresh authentication.
- **Registration rejects dangerous API keys:** Binance and Bitget onboarding
  explains the required permissions and cannot activate an account when
  withdrawal access is enabled or cannot be verified across the fleet.

---

# WhereAreWe — 2026-07-18 (focused public site and direct billing checkout)

## Date

2026-07-18

## This release (2026-07-18)

- **The public site is focused on one decision:** navigation, a single DCA
  compounding hero, live fleet performance, registration action, and footer.
  Longer product, pricing, and FAQ sections are removed from the landing page.
- **Public performance includes realized profit:** the hero shows the
  since-launch realized PnL from closed positions on active tradeable accounts,
  alongside realized ROI. Cancelled and failed histories do not contribute.
- **Wallet top-up goes straight to the gateway:** a valid amount opens the
  NOWPayments checkout in a new tab without an intermediate confirmation.
- **Cloned local users remain easy and safe to inspect:** the local-only login
  quick-pick uses the configured clone password and is absent outside local.

---

# WhereAreWe — 2026-07-18 (Bitget pacing and clean fast-track history)

## Date

2026-07-18

## This release (2026-07-18)

- **Bitget requests now respect the exchange's real limit tiers:** every
  network attempt, including client retries and multi-call jobs, reserves
  an endpoint-specific slot. Public traffic is isolated by source IP and
  signed traffic by API key; signed retries receive a fresh timestamp and
  signature after waiting.
- **Fast-track re-entry trusts only completed trades:** only positions
  whose final status is `closed` can bypass normal token scoring.
  `cancelled` and `failed` histories remain diagnostic records and never
  become fast-track evidence.

---

# WhereAreWe — 2026-07-17 (Bitget onboarding and safe cloned logins)

## Date

2026-07-17

## This release (2026-07-17)

- **Bitget is available in public registration:** the website accepts Bitget
  credentials with the required passphrase, reads its normalized futures
  wallet balance, and keeps inactive exchanges visible but unselectable.
- **Registration produces a renewable trial:** completion starts the trial
  through the billing contract and establishes its first renewal anchor.
- **Account controls show operational truth:** admin separates fleet
  connectivity from final trading readiness, explains inactive subscriptions,
  and locks quote changes while trading or open exposure exists.
- **Connectivity recovery is scoped:** a successful full-fleet retest clears
  only the account's connectivity bans and can reactivate an account that was
  automatically stopped when every safe route disappeared.
- **Production clones have a deliberate local login:** after import, every
  cloned user receives the configured local-only password and stale remember
  tokens are cleared. The local system remains frozen throughout.

---

# WhereAreWe — 2026-07-16 (actionable production alerts)

## Date

2026-07-16

## This release (2026-07-16)

- **WAP notifications no longer interrupt quiet hours:** successful WAP
  application remains visible but uses normal Pushover priority.
- **Deployment recovery does not create false stale-data pages:** ingestion
  warmup gives balance and indicator producers 10 minutes to catch up while
  every independent system-health check remains active.
- **Indicator alerts understand active repairs:** a symbol is not called stale
  while its recent query or conclusion workflow is running. Terminal and old
  abandoned work cannot hide genuine staleness.
- **Contract-unit safety remains strict:** Bitget PEPE and SHIB contracts quote
  individual tokens while Binance references 1,000-token contracts. Their
  mismatch alerts were valid, so those symbols remain excluded until trading
  gains an explicit unit-conversion model.

---

# WhereAreWe — 2026-07-16 (Bitget USDC and safe local snapshots)

## Date

2026-07-16

## This release (2026-07-16)

- **Bitget supports both stablecoin futures products:** catalogue refresh
  atomically merges USDT and USDC perpetuals. Account and symbol operations
  carry the matching product and margin coin through balances, positions,
  orders, prices, leverage, margin, protection, recovery, and close.
- **Quote identity is preserved:** USDT and USDC contracts for the same token
  stay separate, with independent exchange metadata. Missing or unsupported
  Bitget quotes fail before an exchange request.
- **Local production snapshots are fully isolated:** `kraite:freeze` blocks
  schedules, workers, daemons, WebSockets, notifications, mail, and outbound
  HTTP while preserving local UI and data editing. `kraite:clone` requires
  exact migration parity and leaves the system frozen after import.
- **Unsafe unfreeze is impossible:** protected trading and dispatcher state
  must be empty first; interactive cleanup or `--force` removes it before the
  freeze marker can be cleared.
- **Billing is simpler:** users can change plans and top up, but no longer see
  pause or resume controls. Existing paused state remains honoured so legacy
  data cannot reopen trading or trigger renewal unexpectedly.

---

# WhereAreWe — 2026-07-15 (exchange listing lifecycle truth)

## Date

2026-07-15

## This release (2026-07-15)

- **Delisting warning and terminal removal are separate:** a warning
  marker blocks new openings, while a delivery time at or before now is
  the terminal exchange-removal truth. Active exposure remains covered
  by price and kline monitoring, sync, WAP, protection, and close.
- **Catalogue absence follows each exchange's evidence:** missing rows
  from Binance or Bitget's full catalogue become terminal. Missing rows
  from Bybit or KuCoin's active-only catalogue are warning-only until an
  explicit closed or invalid-symbol response confirms removal.
- **Returning symbols recover automatic listing state:** an active row
  clears its warning and terminal timestamp. A returning Binance row also
  restores same-asset overlap on other exchanges.
- **Inactive and ineligible catalogue rows remain evidence, not trading
  candidates:** existing rows are retained without becoming eligible, and
  inactive rows are not created as new tradeable records.
- **Manual enablement is sysadmin-owned:** opening failures and hourly
  allow-list enforcement now use a separate automatic system block with a
  recorded reason. Price mismatch uses its own alignment gate. None of
  these automated flows rewrites `is_manually_enabled`.
- **Historical manual disables are preserved:** existing production values
  are not reclassified because their original cause cannot be proved.

---

# WhereAreWe — 2026-07-15 (confirmed exchange truth and order safety)

## Date

2026-07-15

## This release (2026-07-15)

- **Manual exchange closes shed residual DCA risk immediately:** a
  zero-quantity Binance account update now starts an independent
  high-priority cancellation for that position's live LIMIT opening
  orders. The normal replacement workflow still runs and owns final
  position reconciliation; TP and SL orders are not swept by the new
  safety action.
- **Hedge and one-way matching are explicit:** `LONG` and `SHORT`
  account updates match the same local direction; one-way `BOTH`
  matches the sole locally-open position for the account and symbol.
  Duplicate frames and concurrent replacement work do not create
  duplicate emergency cancellations.
- **Same-pair opening guard is direction-independent:** exchange
  snapshots block a new opening when the pair exists under `LONG`,
  `SHORT`, or `BOTH`. Token discovery's existing cross-direction
  exclusion now has an explicit regression test.
- **REST absence is never trusted once:** replacement, WAP,
  partial-fill quantity sync, drift follow-up, and recovery share a
  validated Binance / Bitget / Bybit / KuCoin position snapshot.
  Vendor errors inside HTTP 200 preserve the last trusted snapshot.
  Exact symbol + logical-side matching prevents an opposite hedge leg
  from masquerading as the bot-owned position.
- **Destructive flat actions require confirmation:** a first valid flat
  REST read schedules a high-priority second read after 20 seconds.
  Only the second valid flat result may cancel Kraite-owned opening
  LIMITs; reappearance, invalid data, and opposite-side rows preserve
  every order. Drift stays alert-only.
- **Recovery preserves ownership until exchange cleanup succeeds:**
  normal recovery confirms flat twice and cancels opening LIMITs while
  the position is still locally open. `--override` now cancels those
  exchange orders before deleting local ownership. Any cancellation
  failure preserves the position, orders, steps, and reference state;
  dry-run never sends a cancellation.

---

# WhereAreWe — 2026-07-14 (workflow ownership and billing hardening)

## Date

2026-07-14

## This release (2026-07-14)

- **Workflow roots now have indexed ownership:** opening, sync, WAP,
  close, replacement, quantity-sync, correction, and drift-heal roots
  record their account or position when created. Live-workflow guards
  share one Pending/Dispatched/Running contract instead of repeating
  JSON scans and subtly different state lists.
- **All remaining orchestrators build once atomically:** parent child-block
  election and child creation now commit under one parent-row lock. A
  retry after commit no-ops; a mid-build failure leaves no partial tree.
  This closes the duplicate-child and potential duplicate-exchange-action
  retry shape across opening, WAP, close, replacement, correction,
  balance, connectivity, symbol-refresh, and leverage workflows.
- **Existing exposure survives delisting filters:** delisted symbols stay
  excluded from new-position candidate work, but open positions retain
  mark-price monitoring and may use their Binance sibling for atomic
  price comparison.
- **Billing/opening gates are consolidated:** an account must be active,
  its user active, trading enabled, subscription currently valid, and on
  capped plans be the designated account. Paid time is proved by the
  renewal anchor, not by wallet affordability alone. Payment webhooks now
  credit only newly received deltas, and legacy trial users missing their
  renewal anchor are backfilled.
- **Operational safety:** `waping` consumes the position's unique open
  slot, Horizon depth reads the physical queues workers consume, and the
  destructive symbol-direction `--clean` flag is refused outside local
  or testing while overlapping runs share one lock.

---

# WhereAreWe — 2026-07-14 (ETC cleanup, DATA reference, B2 retry)

## Date

2026-07-14

## This release (2026-07-14)

- **ETCUSDT safe pre-entry rejection no longer becomes a lifecycle
  failure (core):** position #763 correctly rejected an entry below
  the configured minimum margin before placing any order. Its cleanup
  then found no exchange orders and incorrectly failed the sync step,
  which promoted the otherwise safe rejection into an alert and token
  disablement. Empty cleanup sync is now an explicit skipped no-op;
  verified existing-order sync failures remain failures.
- **DATAUSDT no longer compares against a delisted Binance reference
  (ingestion + core):** Tyche compared the live 1:1 IP replacement
  against a stale delisted Binance row and emitted a false 10% mismatch.
  Candidate and atomic reference selection now require an active,
  non-delisted Binance row. This gate controls new-opening eligibility
  only; every active position remains in sync, WAP, protection, and
  close flows regardless of the symbol's current listing state.
- **Transient B2 failures receive one whole-backup retry (ingestion):**
  the 03:07 backup completed its 915.57 MB archive, then B2 rejected
  multipart part 8 with a server-side `InternalError`. The next two
  scheduled backups succeeded. Scheduler-level retries are now two
  attempts with a 60-second delay, on top of the destination adapter's
  existing adaptive retries.
- **Overnight WAP audit passed:** BCHUSDT position #411 completed its
  WAP and retained live TP/SL protection. FILUSDT position #394 was the
  only other overnight WAP; the existing Scope 2b repair restored its
  TP coverage. No other active position was under-covered.

---

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
