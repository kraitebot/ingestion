# WhereAreWe — 2026-07-02 (user-data scale-hardening release v1.56.6)

## Date

2026-07-02

## Current fleet state

All three Kraite repos are live and tagged — fleet runs identical
versions across all 10 boxes:

- **ingestion** — shipping **v1.56.6** (this release)
- **kraitebot/core** — v1.58.2 (this release)
- **brunocfalcao/step-dispatcher** — v1.14.1 (unchanged)

## This release (v1.56.6 / core 1.58.2)

**User-data stream no longer storms at fleet scale.** The daemon is one
process hosting one WebSocket per account, so a restart resets every
account together. Three amplifiers would have made that a storm at 100
accounts, now bounded: (A) one boot-summary notification per restart
instead of one "connected" per account (connect is log-only; failures
still page); (B) staggered connects (~4/sec ramp) so N handshakes never
fire from athena's IP at once; (C) an account-aware memory ceiling
(200MB + 25MB/account) replacing a fixed 512MB that normal load crossed
around ~43 accounts and crash-looped the daemon. See deploy-notes entry
92. Follow-up (not shipped): shard the daemon into K processes to cap
one-restart blast radius further.

## Prior release (v1.56.5 / core 1.58.1)

**Price-feed daemon self-heals a wedged event loop.** On the morning of
2026-07-02 a transient network blip froze the mark-price daemon's
ReactPHP DNS resolver on athena. "Reconnect forever" kept the process
alive but never recovered — ~46,000 failed reconnects over ~4 hours,
zero fresh prices — until a manual restart cleared it in seconds. Only
a fresh PROCESS clears a loop-level ReactPHP DNS/UDP wedge; the existing
1.1.1.1 + fresh-connector-per-attempt mitigations do not.

Fix: strict-data WebSocket streams (mark-price) now self-exit after 5
minutes with no *data* frame so supervisor respawns a clean process,
turning a multi-hour price blackout into a ~10s blip. Tracks
last-data-frame time separately (never reset by a reconnect or ping) so
both failure phases trip it. User-data stream is exempt (silence is
normal on a quiet account). Also carries a routine vendor `composer
update` (aws/guzzle/symfony peers). Zero money impact from the incident
— no open positions; BASUSDT was tradeable, not held.

See deploy-notes entry 91.

## Nothing else pending

Working tree clean after this release. No queued features, no paused
refactor, no open release.

## Key architecture notes (still true)

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

## Open / deferred (long-standing, not blocking)

- Atomic throttle reservation fix (parked — would zero the 429s).
- `priority-trading` vs `priority-cron` split.
- `SyncLeverageBracketJob` bulk wave watchdog spikes — smaller bulks.
- Bybit `min_delay_ms` dead knob.
- Thread table prefix into `StaleStepsDetected` notification.
