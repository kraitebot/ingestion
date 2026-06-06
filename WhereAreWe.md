# WhereAreWe — 2026-06-06 (localhost real-money test → dual-system cross-fire + close-path bug)

## Date

2026-06-06

## Session summary

Attempted a localhost 3-long / 3-short real-money test on Binance **mainnet**
(local account 2 = Bruno Falcao). The test exposed two real issues — one
environmental, one a genuine code bug — both now understood, one fixed.

Key reminder learned this session: **localhost is NOT a sandbox.** The Binance
connector points at `https://fapi.binance.com` and the local accounts carry
real keys. Opening positions locally places real-money orders, identical to
prod. There is no testnet path in this codebase.

## What happened

1. Enabled local account 2, kicked `kraite:cron-create-positions`.
2. Market was fully bearish — all 35 tradeable Binance symbols concluded
   `direction=SHORT`, **zero LONG candidates**. So only the 3 short slots
   could fill.
3. First batch opened cleanly on Binance (pos RUNE / SOL / LINK — real
   exchange order IDs, market filled + DCA ladder + SL + TP).
4. ~90s later the positions were **closed and a fresh batch re-opened** —
   real-fee churn. Investigated.

## Root causes

### 1. BSCS BlackSwan cooldown (expected gate, not a bug)
`canOpenPositions()` was returning false because `kraite.bscs_cooldown_until`
was in the future (BlackSwan regime gate). Opens were parked fleet-wide.
Forced through with an operator override (`bscs_override_until` on the local
Kraite singleton, +2h). That override expires ~23:19 tonight.

### 2. Dual-system cross-fire (THE churn cause — environmental, fixed)
Prod (athena fleet) and localhost run the **same Binance API keys** (Bruno
Falcao exists in both the prod hyperion DB and the local DB). Binance
broadcasts every order/position event to **both** user-data WS listeners.

- Prod's orphan watchdog (`CheckSystemHealthCommand` → `OrphanReconciler`,
  scheduled `cron-check-system-health`) saw local's orders/positions as
  orphans (absent from prod's DB) and **cancelled/closed them**.
- Local then saw those cancels (order-gone events) and its
  `PreparePositionReplacementJob` reacted — close + reopen. That was the churn.

**Fix applied (prod hyperion DB, account 1 Bruno Falcao only):**
set `allow_other_orders=true` + `allow_other_positions=true`. With both true,
`OrphanReconciler` cancels only orders matched to prod's *own* recently-closed
positions and ignores unknown positions — so prod no longer touches anything
local opens. Effective next watchdog tick (flags read from the DB row each run,
no restart needed). Local accounts keep `allow_other_*` = false (strict
self-management — local's own orders are never orphans to it).

User-data path verified benign on prod: `maybeDetectManualPositionClose`
requires a reduce-only/close FILL + an order not local + prod owning an active
position on that symbol — none true for local's tokens, so it no-ops.

### 3. `Array to string conversion` in `ClosePositionAtomicallyJob` (real bug — FIXED locally)
The pump-cooldown block (runs *before* the actual `apiClose()`) read
`$dailyIndicator->data['close']` expecting a scalar. The 1d indicator is the
TAAPI **`candle`** indicator (`indicator_id=2`, construct
`binancefutures_<sym>_1d_candle_2_0`, **results=2**), which returns OHLCV as
arrays ordered oldest→newest (TAAPI: "most recent value returned last"). So
`data['close']` is e.g. `[9.053, 8.848]` — `(string)` on the array threw, the
close failed, and the position was left `failed` with its TP/SL already
cancelled (naked-ish).

Fix: normalize `data['close']` to the **most recent** value (last array
element) before the numeric comparison, handling both array (results≥2) and
scalar (single-result) shapes. `php -l` clean, `pint` passed. **LOCAL
`kraitebot/core` path repo only — prod core has NOT received this yet.**

## Current state (EOD 2026-06-06)

- **Local:** both accounts disabled (`can_trade=false`, `is_active=false`,
  reason "EOD stop - resume tomorrow"). Horizon restarted (PID rolled —
  loaded the close fix). `dispatch-daemon`, `stream-binance-prices`,
  `stream-binance-user-data` all running. Local `steps` / `trading_steps` /
  `positions` / `orders` / `model_logs` / `api_request_logs` were truncated
  mid-session (clean slate). Local BSCS override expires ~23:19.
- **Prod:** account 1 Bruno Falcao `can_trade=false`, `is_active=true`,
  `allow_other_orders=true`, `allow_other_positions=true`, sign filter
  (`require_matching_correlation_sign`) = true on athena+tyche. Not trading.
  Earlier test config already reverted (TP 0.360, SL 3%, margin 5/5, max 3/3,
  BAS+FLOKI tlo 4 / gaps 8.5–9.5).
- **Uncommitted (local, NOT pushed/deployed):** the `ClosePositionAtomicallyJob`
  close-path fix (core). Plus the still-open `composer.lock` bump + `web`
  logical-queue rename from the 06-04 session.

## Pending / tomorrow

- **Resume the localhost 3L/3S test:** re-enable local account 2, kick
  create-positions, watch the full open lifecycle now that the cross-fire and
  the close bug are addressed. Verify a clean close (no array-to-string).
- **0-LONG market:** likely still bearish tomorrow. Decide shorts-only vs
  force-flipping a few symbols' `direction` to LONG for a balanced run.
- **DEPLOY the close fix to prod:** `ClosePositionAtomicallyJob` array-close
  bug breaks prod closes whenever a symbol's 1d candle `close` is an array —
  needs a core tag + fleet deploy before prod relies on it.
- **Still pending from 06-04/06-05 (unchanged):** ship step-dispatcher
  v1.13.3 + core v1.51.4 (StepObserver queue clobber + dispatch-daemon
  idle-gate) before prod go-live (deploy-notes 69-70); commit composer.lock
  bump + `web` queue rename.

## Notes for next session

- The dual-system rule: **never run prod + localhost against the same Binance
  keys without `allow_other_*`=true on the passive side.** Prod is now set;
  if a third environment ever shares the keys, it needs the same treatment.
- The `bscs_override_until` lever forces opens through a BlackSwan cooldown —
  remember it's a real-money risk assertion, and it expires (re-set if a test
  spans past the window).
