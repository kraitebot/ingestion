# WhereAreWe — 2026-06-13 (fleet-watchdog lifecycle + Kraite::ip() identity — RELEASE PAUSED, step-dispatcher mid-refactor)

## Date

2026-06-13

## RELEASE PAUSED

Bruno halted `/do kraite-release` at 00:00 because the step-dispatcher
package is under active refactor (dirty working tree: exception-handler
split into per-driver `DatabaseExceptionHandlers`, lifecycle/observer/
transition touches, new cancelled-step cascade test). Pipeline ran
docs + tests only; NOTHING tagged, pushed, or deployed. Resume with
`/do kraite-release` once the refactor lands — step-dispatcher Feature
tests are hard gate 1a.

## Unreleased work queued (live on localhost, NOT on prod)

**kraitebot/core (since v1.54.1)**
- Fleet-silence alert lifecycle: provisioning grace
  (`provisioning_grace_seconds`, 24h default, `missing`-only, anchored
  on `servers.created_at` now written insert-only by the seeder) +
  per-check re-page throttle (`alert_throttle_seconds`, 1h default —
  the canonical's 300s window was shorter than the 7-min cadence, so a
  down box paged every tick) + null-age alert wording fix ("stale with
  an unreadable reported_at stamp" instead of "is s stale").
- `Kraite::ip()` resolves the box's logical roster identity
  (`kraite.fleet_metrics.hostname`) before the OS hostname — fixes the
  localhost ipify blackout (deploy-notes entry 86: Mac hostname has no
  roster row → every IP-scoped call hit live ipify → SSL break killed
  ALL localhost notifications silently, ~1,200 swallowed errors).
- `FleetMetricsRepository` rows carry `registered_at`;
  `fleet.servers` palaemon/aristaeus entries (commit 61e1a7a).

**ingestion (since v1.55.6)**
- New `CheckSystemHealthFleetSilenceTest` (5 tests: grace, post-grace
  page, stale-never-graced, unreadable-stamp wording, throttle) +
  `KraiteIpTest` extended (override wins, ghost-host fallback, empty
  string = unset).
- `phpunit.xml` pins `FLEET_METRICS_HOSTNAME=""` (dev `.env` now
  carries `FLEET_METRICS_HOSTNAME=local`; tests must resolve like a
  prod box).
- Dirty `composer.lock` (core bump) rides along.

**brunocfalcao/step-dispatcher (since v1.13.5)**
- PostgreSQL grammar-quoting in recover-stale + archive (188a9bc,
  pushed untagged) — being absorbed by the ongoing refactor.

## Session summary (2026-06-12 → 13)

1. palaemon + aristaeus joined as trading workers 5 + 6 (fleet now 10
   boxes); deploy-notes entries 79–85.
2. xhigh code review over the unreleased work (9 finder angles, 15
   findings: 1 high, 8 medium, 6 low). Fixed the high (#1 alert-storm
   lifecycle) + the top medium (#2 null-age string) on Bruno's order;
   the rest are triaged in the review table (this conversation,
   2026-06-12).
3. Localhost ipify blackout discovered during verification, root-caused
   and fixed live (entry 86). All localhost LaunchAgents kickstarted;
   post-fix log confirmed quiet.
4. Docs refreshed: new `02-features/fleet-metrics.md`, watchdog spec
   (cadence 7-min, 14 checks, fleet row), README index, entry 86.
   Syntax site refresh deferred to the actual release (behavior not on
   prod yet).

## Key architecture notes (still true)

- TAAPI/Bybit throttle budget is NOT raised by the 2nd IP — single
  global Redis buckets keyed without IP.
- Notification Threshold counting is per `(notification, relatable)`
  over held rows; throttler must be loose for a threshold to fire.
- Non-Binance klines feed per-exchange semaphores — never gate by
  active account.
- Fleet-metrics hyperion bash agent hardcodes key prefix / Redis DB /
  TTL — changing `FLEET_METRICS_*` env desynchronises it (review
  finding #4, open).

## Open / deferred

- Code-review findings #3–#15 (core config horizon.workers drift, bash
  agent set -e abort paths, heartbeat-loop death modes, hostname-queue
  guard, tyche threshold recalibration, MGET batching, etc.) — table
  in the 2026-06-12 review session.
- Atomic throttle reservation fix (parked — would zero the 429s).
- `priority-trading` vs `priority-cron` split (long-standing).
- `SyncLeverageBracketJob` bulk wave watchdog spikes — smaller bulks.
- Bybit `min_delay_ms` dead knob.
- Thread table prefix into `StaleStepsDetected` notification (entry 76
  follow-up).
