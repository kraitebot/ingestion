# WhereAreWe — 2026-05-07 (breadcrumb lifecycle + managed OPTIMIZE schedule)

## Date
2026-05-07

## Session summary

Two releases shipped: `kraitebot/core` 1.30.0 (close-path / drift /
quantity-sync hardening) and 1.31.0 (breadcrumb lifecycle + maintenance-
windowed OPTIMIZE TABLE schedule). Both bumps committed and pushed to
`kraitebot/core` and `kraitebot/ingestion`; matching ingestion bumps
on the same SHAs.

The throughline of the session: the bot was producing a steady tide of
diagnostic breadcrumb rows (model_logs +534k/day, steps +432k/day,
api_request_logs +237k/day) that never got pruned for clean lifecycles.
Even with daily retention purges in place, the `.ibd` files kept
growing because InnoDB DELETE leaves freed pages stranded inside the
file. The combined fix is to (1) delete the breadcrumbs at the right
moment in the lifecycle, not by retention window, and (2) reclaim the
stranded pages with a nightly OPTIMIZE pass that respects the running
dispatcher.

Plus correctness fixes to the close path that surfaced during the
morning's incident review (positions #755 / #803 mismarked `failed`
after Binance / Bitget already-closed responses; recovery loop on
BSCS; orphan MARKET-CANCEL rows accumulating on TP-fill close paths).

Verified end-to-end against production:

- Janitor cleared 1496 breadcrumb rows for Position #1223 (SOLUSDT)
  in <1s; fan-out across all 359 already-closed positions cleared
  412,339 rows in 25s.
- Structure audit fired all three notification channels (Pushover
  #2169, Mail #2170, Telegram #2171) when a TP row was manually
  CANCELLED on Position #101; flag flipped, state restored cleanly.
- OPTIMIZE managed window invoked end-to-end on `api_snapshots` —
  pause engaged, drained, optimised in 0.08s, resumed; pause cache
  key cleared.

## Current state

- Test suite: green on the new paths
  - `MaintenanceModeTest` — 4 cases, 12 assertions
  - `PurgePositionTrailJobTest` — 6 cases, 35 assertions
  - `CheckPositionStructureIntegrityTest` — 7 cases, 18 assertions
  - 255 Position-related tests pass (no regression on existing
    PositionObserver dependents)
- Pint: clean across both repos (`kraitebot/core`,
  `kraitebot/ingestion`)
- Horizon: terminated + respawned at end of session so workers
  picked up the new `PurgePositionTrailJob`,
  `OptimizeBreadcrumbTablesCommand`, `MaintenanceMode`, and
  `PositionObserver::updated()` wiring

## WIP
None — both releases shipped and pushed.

## Pending items

- First real production firing of the structure-broken canonical
  hasn't happened yet (the 27 active positions on prod are all
  structurally whole). When it does, the recovery sequence
  (inspect missing component, place the missing order, flip the
  flag back) deserves a short cheat-sheet under `00-context/`.
- Disk reclamation hasn't visibly shifted yet because the bulk-
  purge run earlier this session compacted the file space already.
  Tomorrow morning's first scheduled OPTIMIZE pass (03:00 → 04:36)
  will produce the first steady-state reclamation numbers; trend
  worth watching for a few days.
- `--skip-structure-audit` flag is wired only for tests today; if
  a future incident needs a "skip Scope 3 for one tick" lever
  without code changes, the flag is already in place — schedule
  override or manual artisan call covers it.

## Key decisions made this session

- **Breadcrumbs are deleted at the lifecycle boundary, not by
  retention window.** The pre-existing `PurgeOldDataCommand`
  trimmed by `created_at` age. The new janitor trims when the
  position's lifecycle confirms the trail is no longer needed
  (clean `closed`). `cancelled` and `failed` exits keep the full
  forensic trail untouched.
- **OPTIMIZE runs sequentially per table, not in parallel.** A
  live benchmark showed parallel mode tripled per-table durations
  (5 concurrent rebuilds shared the disk bandwidth) and only saved
  ~26% wall-clock. Sequential per-table with brief metadata locks
  is friendlier to live traffic than 5 minutes of sustained
  contention.
- **`MaintenanceMode` is a project-side helper, not a
  step-dispatcher feature.** Project scope is right for a
  cache-backed flag with a 30-min TTL safety net; widening to the
  shared package would have meant a cross-package release for a
  trade-specific need. If reuse pressure builds, easy to promote
  later.
- **No quiet window on Scope 3 of the drift spotter.** The
  drift / orphan scopes wait 10 minutes so they don't race
  mid-flight writes. Scope 3 explicitly skips that filter — by the
  time a position is `active` its full order set must already
  exist, so any gap is a real anomaly that has to surface fast.
- **Cross-exchange already-closed signals all collapse to the
  same `already_closed=true` success shape.** Binance `-2022`,
  Bitget `22002` / "No position to close", and known Bybit /
  KuCoin variants all map to the same legacy snapshot pre-flight
  result. Restores the natural TP-close exit path on every
  exchange. No more `failed` mismarks for positions the exchange
  flattened a few seconds before our close call landed.

## Reference paths

- New job:
  `packages/kraitebot/core/src/Jobs/Atomic/Position/PurgePositionTrailJob.php`
- New command:
  `packages/kraitebot/core/src/Commands/Cronjobs/OptimizeBreadcrumbTablesCommand.php`
- New helper:
  `packages/kraitebot/core/src/Support/MaintenanceMode.php`
- Observer change:
  `packages/kraitebot/core/src/Observers/PositionObserver.php`
  (added `updated()` hook)
- Schedule entries:
  `routes/console.php` — five new `dailyAt()` registrations
  between the existing purge chain and the backup section
- Session log:
  `~/docs/kraite/03-logs/2026-05-07_breadcrumb_lifecycle_and_structure_audit.md`
