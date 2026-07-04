# WhereAreWe — 2026-07-05 (stuck-maintenance sentinel release v1.57.0)

## Date

2026-07-05

## Current fleet state

All three Kraite repos are tagged — deploy of v1.57.0 to the fleet is
PENDING (`/do kraite-deploy` when Bruno decides):

- **ingestion** — **v1.57.0** tagged (this release, not yet deployed)
- **kraitebot/core** — v1.59.0 tagged (this release, not yet deployed)
- **brunocfalcao/step-dispatcher** — v1.14.1 (unchanged)
- Fleet currently runs v1.56.6 / core 1.58.2.

## This release (v1.57.0 / core 1.59.0)

**Health watchdog survives maintenance mode + stuck-maintenance
sentinel.** Incident 2026-07-02→04: the v1.56.6 release warmup never
ran on athena, leaving the box in maintenance mode for 53 hours.
Laravel's scheduler skips every event while the app is down, so the
whole cron chain died silently — listen-key keepalive, sync fallback,
DB backups, and every watchdog INCLUDING the health command itself.
Only symptom: Binance `listenKeyExpired` pages every 70 minutes
(metronome-precise = key expiring with zero keepalives). Zero money
impact (no open positions since 2026-06-06); backups silently dead
for two days. Recovery was one `php artisan up`.

Shipped:

- `kraite:cron-check-system-health` is now scheduled
  `evenInMaintenanceMode()`. While the app is down it runs exactly ONE
  check — `maintenance_mode_stuck` (new, #14) — and skips the full
  pass so normal deploy windows never produce transient pages.
- The new check pages CRITICAL when the down-marker is older than
  `kraite.health_watchdog.maintenance_stuck_minutes` (default 45),
  re-paging every 30 minutes. Unreadable marker fails open.
- Runbook gates: warmup Step 5b hard-verifies the box answers "UP"
  after warming; kraite-health grid gained a **Maint** column that
  hard-fails on any down-marker; kraite-release rule — a release is
  not done until every deployed box is out of maintenance.
- Regression: `CheckSystemHealthMaintenanceStuckTest` (5 cases).
- Also carries a routine vendor `composer update` (aws-sdk, horizon
  v5.7→v5.8 + peers).

See deploy-notes entries 93 (this incident) and 91-92 (the 2026-07-02
daemon incidents from the same release window).

## Nothing else pending

No queued features, no paused refactor. v1.57.0 awaits deploy.

## Key architecture notes (still true)

- The scheduler skips EVERYTHING in maintenance mode — any watchdog
  scheduled through the same scheduler it monitors is blind to
  scheduler-wide failure modes. At least one check must run
  `evenInMaintenanceMode()`.
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
- Out-of-band scheduler dead-man on hyperion (proposed post-Entry-93,
  Bruno declined for now — runbook + sentinel layers deemed enough).
