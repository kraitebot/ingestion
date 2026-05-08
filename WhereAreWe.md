# WhereAreWe — 2026-05-08 (dispatcher parallelism + saturation telemetry + open-path freshness gate)

## Date
2026-05-08

## Session summary

Three structural shifts shipped today, all targeted at the
200-account go-live readiness conversation:

1. **Stale mark-price freshness gate inside token discovery**
   (`HasTokenDiscovery::executeTokenAssignment()`). The bot now
   refuses to evaluate opens against any symbol whose sidecar
   `mark_price_synced_at` is older than 30 seconds (configurable
   via `kraite.token_discovery.mark_price_max_age_seconds`,
   `TOKEN_DISCOVERY_MARK_PRICE_MAX_AGE_SECONDS`, set to 0 to
   disable). A general daemon stall now produces zero opens
   across every account in the same tick rather than a wave of
   stale-priced bad picks. Null sidecar (legacy column path,
   brand-new symbol, test fixture) is allowed through; the
   throwing computations in `HasTradingComputations` catch the
   null-everything case downstream as defence in depth.

2. **Per-group parallel dispatch wiring** in
   `routes/console.php`. Replaced the single
   `steps:dispatch` everySecond entry (which looped all ten
   groups serially in one PHP process — per-group cadence ~5s)
   with ten dedicated `--group=<name>` entries, each
   `everySecond()->runInBackground()`. Per-group cadence drops
   to ~1s — about 5× lift on dispatchable promotion rate before
   the per-group `max_per_tick` cap kicks in.
   `runInBackground()` is load-bearing: without it the
   scheduler runs the ten commands in-process serially and tick
   age regresses to 11–17s.

3. **Saturation telemetry**. Step-dispatcher now writes four
   per-tick Redis counters keyed by `(group, UTC minute
   bucket)`:
   `ticks_observed`, `ticks_capped`,
   `ticks_capped_with_leftover`, `total_dispatched`, plus a
   `max_pending_after` running gauge. A new
   `kraite:cron-flush-dispatcher-saturation` runs every minute,
   reads the previous completed minute for all ten groups, and
   writes one row per group to a new
   `steps_dispatcher_saturation` table. Saturation % per
   bucket = `ticks_capped_with_leftover / ticks_observed × 100`.
   Sustained near-100% across all groups = the dispatcher cap
   is the actual bottleneck and adding more groups will help;
   sub-100% = the cap is not the bottleneck and adding groups
   will not move the end-to-end number.

Plus a set of production fix-ups:

- **B2 backup multipart retries**. `config/filesystems.php`
  `b2` disk now declares
  `retries => ['mode' => 'adaptive', 'max_attempts' => 10]`.
  Backblaze's sporadic per-part 500s (May 5 ×3, May 8 ×1) used
  to abort the entire upload at the SDK's default 3-attempt
  legacy mode. Adaptive mode adds client-side rate limiting on
  top of standard exponential backoff so a throttled B2
  endpoint cannot feed itself.
- **api_request_logs cleanup**. Deleted 1,051,800 rows where
  `http_response_code = 200`. Table file shrunk from 4.6 GB to
  136 MB after `OPTIMIZE`. 13,648 rows preserved (errors,
  non-200, null-status).
- **model_logs truncate**. TRUNCATE wiped 3.36 M rows; the
  `.ibd` shrunk from 3.1 GB to 176 KB instantly.
- **Eta + gamma poison-pill clearing**. Cancelled steps
  #2582779 (eta) and #2825663 (gamma) — both
  `UpdatePositionStatusJob` priority='high' index=9 in blocks
  with no index=8 row. Step-dispatcher 1.11.13 already prevents
  these from wedging a group via the priority pass-1
  fall-through fix; cancelling the rows just removes them from
  the per-tick re-fetch noise.

Also: a deep audit of the full step-dispatcher tick lifecycle
confirmed no global table scans exist anywhere across the
eighteen query sites. Every query carries either a `group`
filter (covered by `idx_steps_state_group_dispatch_type`), a
`block_uuid`/`child_block_uuid` filter (UUIDs are globally
unique by construction), or a primary-key lookup. `EXPLAIN`
verified `range`/`ref` access on all hot paths, never `ALL` or
`index`.

## Current state

- All releases pushed and live:
  - `brunocfalcao/step-dispatcher` 1.11.14 (priority pass-1
    fall-through 1.11.13 + saturation counters 1.11.14)
  - `kraitebot/core` 1.33.0 (mark-price gate + saturation
    table + flush command + per-account fan-out hardening)
  - `kraitebot/ingestion` 1.33.0 (per-group parallel dispatch
    + flush schedule entry + B2 retry config + new gate test)
- Step dispatcher health: 10 groups all ticking sub-second
  cadence under parallel forks. 0 Pending poison pills. First
  `steps_dispatcher_saturation` rows already being written.
- Test suite: all green. New ingestion-side
  `HasTokenDiscoveryStaleMarkPriceGateTest` (3 cases) and
  `B2DiskRetryConfigTest` (2 cases) pinned. Step-dispatcher
  package suite 31/31.
- Schedule list verified: ten `steps:dispatch --group=<name>`
  entries every-second + flush command every-minute.

## WIP

None. Working tree clean across step-dispatcher and ingestion
repos. Two unrelated untracked files in `kraitebot/core`
(`Enums/ProjectionFormat.php`, `Enums/ProjectionScenario.php`,
`Support/Financial/`) — those belong to a separate stream
(projections / billing work-in-progress) and were intentionally
left untouched.

## Pending items

- Risks #3–#10 from the 200-account readiness assessment:
  single ingestion server, single MySQL primary, single Redis
  instance, hot-row contention on positions/orders, exchange
  API budgets, WS daemons single-process per exchange,
  CreatePositionsCommand serial loop, global
  `allow_opening_positions` flag missing per-account scope.
  Risks #1 (mark-price freshness gate) and #2 (dispatcher
  throughput ceiling — addressed via per-group parallelism +
  saturation tracking) are now closed structurally.
- Admin dashboard surface for `steps_dispatcher_saturation`
  (line chart per group, mean across groups, threshold lines
  at 70%/90%, class-breakdown drill-down). Not started.
- Per-account `allow_opening_positions` flag (replacing the
  global one so structure audit can halt opens for a single
  user without affecting the rest). Bruno still hasn't decided
  invasive (per-account column) vs scoped-flag-with-account-id.

## Key decisions made this session

- **Stale mark-price gate at 30s default**, configurable. Not
  zero, because brand-new symbols and the legacy column path
  legitimately have null sidecar; the gate only refuses
  candidates whose sidecar IS present and IS stale.
- **Per-group parallel dispatchers, not 20 groups**. Moving
  from 10 → 20 groups would double the dispatcher promotion
  ceiling but the actual bottleneck at burst is downstream
  (TAAPI, exchange API, observer chain). Pay the headroom
  cost only when the saturation telemetry says the cap is
  actually firing.
- **Saturation %% defined as
  `ticks_capped_with_leftover / ticks_observed`**, not
  `dispatchable_count / max_per_tick`. The "with leftover"
  qualifier matters: a tick that hit the cap but had nothing
  else waiting is not actually saturated — the cap fired by
  coincidence on the last batch.
- **Reading the previous completed minute in the flush cron**,
  never the current in-progress one. Avoids a race where the
  flusher consumes counters that ticks are still incrementing.
- **`runInBackground()` non-negotiable** on the per-group
  cron entries. Verified empirically: without it cadence
  regresses to 11–17s.
