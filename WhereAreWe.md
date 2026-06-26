# WhereAreWe — 2026-06-26 (dependency-bump release v1.56.4)

## Date

2026-06-26

## Current fleet state

All three Kraite repos are live and tagged — fleet runs identical
versions across all 10 boxes:

- **ingestion** — shipping **v1.56.4** (this release)
- **kraitebot/core** — v1.58.0
- **brunocfalcao/step-dispatcher** — v1.14.1

The 2026-06-13 "RELEASE PAUSED" state is over: everything that was
queued then (fleet-silence alert lifecycle, `Kraite::ip()` roster
identity, the step-dispatcher refactor) shipped across the 1.55.x →
1.56.x ingestion line and core 1.57.x → 1.58.0.

## This release (v1.56.4)

**Pure third-party dependency bumps — no Kraite source change.** A
`composer update` refreshed vendor packages (aws-sdk-php
3.385.3 → 3.386.1, guzzle 7.12.1 → 7.12.3, and peers). No
ingestion, core, or step-dispatcher source touched; no behavior,
schema, or contract change. Shipped fleet-wide for version parity —
every trading box must run the identical ingestion tag.

## Nothing else pending

Working tree clean apart from the lock bump. No queued features, no
paused refactor, no open release.

## Key architecture notes (still true)

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
