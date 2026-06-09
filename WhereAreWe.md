# WhereAreWe — 2026-06-09 (step-dispatcher v1.13.5 — group-progress watchdog oldest-pending gate)

## Date

2026-06-09

## Latest change — step-dispatcher v1.13.5 (group-progress watchdog false positive)

The `group_no_progress` watchdog paged CRITICAL on athena for `trading_steps`
group `gamma` — a `ProcessUserDataEventJob` that lived one second (created
18:00:01, done 18:00:02). Nothing was wedged. Root cause: the watchdog measured
staleness only against a group's last terminal step, never against how long the
pending work had itself been waiting, so sparse event-driven groups (the
`trading_*` set, fed by hours-apart Binance user-data events) false-fired
whenever the every-minute tick read a freshly-created step mid-flight. Fix adds
an oldest-pending gate: a group only trips once its oldest non-throttled Pending
step has aged past the 600s threshold. Genuine wedges (2026-04-25 16h shape)
still fire. Regression test added to `GroupProgressWatchdogTest`; full hard gate
green (step-dispatcher 75, ingestion 2392). Follow-up parked: thread the table
prefix into the `StaleStepsDetected` event + notification so trading-set stalls
stop printing `steps` diagnostics. See deploy-notes Entry 76.

## Session summary

Shipped a full fleet release overnight. Three headline changes:

1. **Notification Threshold** (new, opt-in) — an escalation gate on top of the
   throttler: a flagged notification is only delivered once it recurs N times
   within a window; sub-threshold occurrences are recorded (`passed_threshold`)
   but not sent. Inert by default. Built for the Bybit `retCode 10006` noise.
2. **Phase 3 BSCS** — regime-scaled leverage + position-count ramps; Critical
   is now absolute (per-account `respect_bscs` + operator override removed).
3. **TAAPI throttle tuning** — cap 75→65, min-delay 50→200ms on the indicator
   consumers (athena + tyche), after diagnosing the chronic ~20% 429 rate as a
   shared-bucket + zero-headroom + non-atomic-counter problem.

Also propagated **step-dispatcher 1.13.4** fleet-wide: the group-progress
watchdog now excludes `is_throttled` steps, killing the phantom
`group_no_progress` pages under TAAPI 429 backpressure.

## What shipped

**kraitebot/core v1.53.0**
- Notification Threshold (3 cols on `notifications`, `passed_threshold` on
  `notification_logs`; throttler-first → threshold gate; re-earn via id
  high-water-mark cache anchor; per-scope lock; fail-open). DB-throttle window
  narrowed to count only delivered rows.
- Phase 3 regime ramps (leverage `floor(base×ratio)`, count `floor(max×ratio)`,
  gate-only; `positions.bscs_band` + `bscs_score`; override/respect_bscs drops).

**brunocfalcao/step-dispatcher v1.13.4** (released earlier, now fleet-wide)
- Group-progress watchdog ignores throttle-waiting steps.

**ingestion v1.55.0**
- Notification threshold feature tests; pins core via `^1.36` (prod manifest).
- TAAPI throttle `.env` tuning (per-box, not in VCS).

## Release facts

- Tests: step-dispatcher 74, ingestion 2389 (0 failures). CI green.
- Deploy: all 6 boxes on v1.55.0 / core v1.53.0, dev-master CLEAN. athena ran
  migrations (notification threshold + Phase 3) behind a 752M pre-deploy DB
  backup hard-gate. athena needed `FORCE_DEPLOY=1` — cooldown wouldn't drain 70
  non-trading stuck steps (69 ConcludeSymbolDirection + 1 SyncLeverageBrackets,
  classified safe; see deploy-notes entry 75).
- Overnight watch (18× 30-min passes): 0 failed steps throughout, 8 open
  positions stable, Horizon RUNNING on all 6. TAAPI 429-rate dropped to a
  steady ~9-14% band during waves (was ~20%); group_no_progress fired only
  twice (02:00) on a genuine non-throttled SyncLeverageBracket bulk wave —
  correct behaviour, self-recovered.
- Docs: raw specs (`02-features/notification-threshold.md`,
  `step-dispatcher.md` watchdog, README), deploy-notes 74+75, and the syntax
  site (`subsystems/notifications` new, `domains/indicators` throttle table
  fixed) refreshed + rebuilt + live on pheme.

## Key architecture notes (still true)

- TAAPI/Bybit throttle budget is NOT raised by the 2nd IP — single global Redis
  buckets keyed without IP. The cap+pacing tuning reduces 429s ~40% but doesn't
  zero them (non-atomic race + window-phase mismatch remain).
- Notification Threshold counting is per `(notification, relatable)` over
  held rows; the throttler must be loose (`cache_duration=0`) for a threshold
  to ever fire.
- Non-Binance klines feed per-exchange semaphores — never gate by active acct.

## Open / deferred

- Atomic throttle reservation fix (parked — would zero the 429s).
- `priority-trading` vs `priority-cron` split (long-standing follow-up).
- `SyncLeverageBracketJob` bulk wave (~1700-1830 rows) briefly spikes pending +
  can trip the backlog watchdog — candidate for smaller bulk creation.
- Bybit `min_delay_ms` env is wired but the bybit config block never reads it
  (dead knob) — noted, not fixed.
