# WhereAreWe — 2026-05-08 (dispatcher table-prefix split — trading vs default)

## Date
2026-05-08 (later session, follows the parallelism + saturation work
earlier the same day)

## Session summary

Major structural change shipped: the step-dispatcher now runs as two
isolated ecosystems against the same MySQL database — the default
`steps_*` set continues to handle calculation churn (klines,
indicators, BSCS, balance snapshots, leverage brackets) while a new
`trading_*` set carries every trade-critical workflow (opens, sync
orders, close, drift heals, WAP, order corrections, user-data
events). One dispatcher fleet per prefix, isolated saturation,
isolated retention, isolated cooldown.

The package (`brunocfalcao/step-dispatcher`) gained per-prefix
support end-to-end on the `feature/table-prefix` branch:
RuntimeContext + Steps facade + per-prefix flag files + per-prefix
cache keys + per-prefix CTE interpolation + per-prefix cache key
scoping + idempotent `steps:install --prefix=<name>` command +
universal `--prefix=` CLI option injected by `BaseCommand` +
worker-side prefix push in `BaseStepJob::__unserialize()` (the
critical fix that made the trading workers actually load their step
rows from the correct table during deserialize).

The Kraite host wraps every trade-critical entry point in
`Steps::usingPrefix('trading', …)` — `SyncOrdersCommand`,
`CreatePositionsCommand`, `CheckDriftsCommand`,
`StreamBinanceUserDataCommand` daemon, `OrderObserver` (5 sites),
`PositionObserver::updated`, `Concerns\Order\HandlesChanges` trait
(3-step WAP block).

`MaintenanceMode` is now per-prefix aware: pause null = all,
pause `''` = default only, pause `'trading'` = trading only.
`OptimizeBreadcrumbTablesCommand`'s no-arg call still pauses
everything (backward compat). The `routes/console.php` skip
callbacks pass each entry's prefix; the three Kraite callers
(SendStaleStepsNotification, CheckSystemHealthCommand,
CheckDriftsCommand) gate on the prefix that owns their data.

8-minute post-cutover monitor: zero leakage, zero failed jobs,
trading_steps grew 275 → 381 organically through opening + sync
cycles, default kept receiving only its expected classes
(DetectMarketShock, FetchKlines, StoreAccountBalance, etc.).

## Current state

- Package suite (`packages/brunocfalcao/step-dispatcher`):
  60 green (31 original + 29 new prefix tests).
- Production:
  - Default dispatcher: running, 712k+ historical steps,
    Pending=0.
  - Trading dispatcher: running, ~380 steps cycling cleanly
    through opens and syncs.
  - failed_jobs: 0.
- Branch state: `feature/table-prefix` local-only (not pushed
  remotely); the symlinked path repo means the live ingestion
  server runs the branch directly.

## WIP

Nothing in-flight. The split is fully deployed and verified.

## Pending

1. **Push the package branch** `feature/table-prefix` to
   `kraitebot/step-dispatcher` (remote backup anchor). Kraite
   host changes already ran in production but the package branch
   has not been pushed.
2. **`FlushDispatcherSaturationCommand` (Kraite host) is not
   prefix-aware.** It reads default-shape Redis keys; trading
   saturation metrics are lost on flush. Low priority
   (observability), follow-up.
3. **Per-prefix archive/purge retention** is currently identical
   to default (5-day rolling). If trading wants different
   retention, the schedule entry is the tuning point.
4. **Saturation telemetry table** does not yet have a `prefix`
   column; if trading saturation gets a dashboard the schema
   wants extending. Out of scope today.

## Key decisions made this session

- **Per-table idempotent installer** (after Bruno's pushback on
  the original "atomic refuse on any conflict" semantics).
  Re-running `steps:install --prefix=trading` is now a no-op when
  fully installed, and a heal pass when partially installed. The
  dispatcher seed (alpha..kappa rows) only fires on fresh
  dispatcher creation so re-runs cannot duplicate seeds.
- **Worker-side prefix push moved to `__unserialize()`**, not
  `handle()`. Laravel's `SerializesModels` trait runs
  deserialize-time DB fetches BEFORE handle() — without the gate,
  every prefixed job hit `ModelNotFoundException`. Surfaced as a
  failed_jobs spike on the cutover smoke test, fixed inline.
- **Daemon Step::create wrapped per-event, NOT lifetime.**
  StreamBinanceUserDataCommand is a long-running ReactPHP loop;
  pushing the prefix for the entire process would leak across
  any future non-trading event types. Tight closure around the
  per-frame Step::create only.
- **Per-prefix MaintenanceMode** chosen over single-flag:
  the whole point of the prefix split is isolation; a single
  pause that freezes both ecosystems undoes the design intent.
  The `null` argument keeps backward compat with
  `OptimizeBreadcrumbTablesCommand`'s no-arg call.
- **Kept default `steps_*` live during cutover.** The migration
  did not decommission default — the two table sets coexist
  permanently, and the default dispatcher continues to handle
  every workflow that isn't explicitly wrapped in
  `Steps::usingPrefix('trading', …)`.
