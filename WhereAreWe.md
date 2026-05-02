# WhereAreWe — 2026-05-03 (User-data stream promoted to production primary)

## Date
2026-05-03

## Session summary

Operational session, no new code. Verified the user-data stream
push path end-to-end against live Binance fills on Bruno's Main
Binance Account, then flipped production from "shadow + selective
opt-in" to "push primary, polling safety net".

A test position (ETC LONG #177) was opened via the test-only
`symbol_override` knob with a 0.25 % limit-ladder gap and a 5 %
take-profit. When the L1 LIMIT filled on the exchange, the WS
`TRADE` frame arrived in the same wall-clock second, the worker
called `Order::updateSaving`, and `OrderObserver` dispatched
`ApplyWapJob`. End-to-end WAP cycle (fill → exchange-side TP
modify) completed in ~35 seconds. Polling sync would have taken
up to 15 minutes under the prior cadence.

The `maybeDetectManualPositionClose` branch — added in the
2026-05-02 hardening pass but never exercised live before today —
fired correctly three times during verification on operator-
initiated closes (JUP 175, ETC 176, ETC 177). Each time it
dispatched the position-replacement orchestration via
`triggerStatus='EXTERNAL_FILL'` without double-firing.

Production allowlist was extended from 7 to 8 exec types
(added `ALGO_FILLED` for the cleaner direct SL-fire route into
`OrderObserver::dispatchClosePosition`). Sync-orders polling
cadence reset from 15 min to 5 min — push handles real-time, polling
exists only for rare WS-frame-loss / reconnect-race drift.

## Current state

### Production
- Push path is the production primary for fill detection and order
  state transitions.
- Live test position ETC 177 closed cleanly via operator manual-close
  (manual-close-detection branch fired). System fully drained of
  test residue.
- 11 active positions on Main Binance Account at session close
  (5 LONG including pre-existing, 6 SHORT). Other accounts unchanged
  since the 2026-05-02 session.

### Test suite
Not run this session — operational rollout, no code changed.

### Deploy state
- `kraitebot/core` v1.13.0 (no bump this session).
- `ingestion.kraite.com` working tree: 2 files modified
  (`.env` allowlist + `routes/console.php` cron cadence) and the
  WhereAreWe.md update. Push pending.

### Configuration applied
- `USER_DATA_STREAM_BINANCE_DISPATCHED_EXECUTIONS` =
  `TRADE, AMENDMENT, CANCELED, EXPIRED, ALGO_NEW, ALGO_CANCELED, ALGO_EXPIRED, ALGO_FILLED`
- `kraite:cron-sync-orders` schedule: `*/5 * * * *` (was every minute
  before 2026-04-30, 15 min during shadow-mode window).
- Horizon respawned post-config-clear; verified it picked up the
  new allowlist.
- `config/kraite.php` `position_creation.symbol_override` reverted
  to commented-out example; ETC + JUP `exchange_symbols` columns
  reverted to original values via raw query builder writes (no
  observer cascade).

### Supervisord
All 6 kraite programs RUNNING (kraite-horizon, kraite-scheduler,
kraite-ingestion-horizon, kraite-ingestion-scheduler,
kraite-ingestion-binance-prices, kraite-ingestion-binance-user-data).
Ingestion Horizon respawned by supervisor immediately after the
`horizon:terminate` issued post-config-clear.

## WIP

None.

## Pending items

1. **Tests for selective dispatch + manual-close detection.** The
   1.12.0 / 1.13.0 changes shipped with limited automated coverage
   for the push-driven dispatch and the manual-close detection
   branch. Live verification covered the happy path; test coverage
   for edge cases (idempotency-key collision, position-not-found,
   already-pending-Step dedup) would harden the surface further.
2. **Carry-over from prior session.** Database backup strategy,
   indicator alert auto-resolve verification, daemon GAP 5
   sharding, backtesting approval workflow, AERGO-style degraded-
   position recovery command — see git history of this file.

## Key decisions made this session

- **Push primary, polling safety net.** The shadow-mode rollout
  window served its purpose; the push path is observably correct
  on real fills.
- **5-minute polling cadence is the right safety net granularity.**
  Push handles fast cases; polling catches rare WS-frame-loss /
  reconnect-race drift. 15 min was too lax once push went primary;
  1 min would burn API budget without functional gain.
- **`ALGO_FILLED` added to allowlist; `NEW` / `REJECTED` /
  `CALCULATED` deliberately stay off.** NEW would create defensive
  drift-detection noise on every placement ack. REJECTED is already
  caught synchronously at `apiPlace`. CALCULATED (liquidations) is
  explicitly out of scope — the bot is not responsible for
  liquidation handling.
- **Symbol override remains a test-only knob.** Production setups
  leave the example commented in `config/kraite.php`. Live
  verification proved priority-0 wiring + slot-cap respect + direction-match
  guard + alreadyOpen guard all correct.
