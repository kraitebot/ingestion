# WhereAreWe — 2026-05-03 (drift alert-only + MARKET race fix + reverts)

## Date
2026-05-03

## Session summary

Evening session opened with two production incidents in flight: a
mark-price daemon stall (~3 minutes of frozen WS-watched prices) and
position-create lifecycle aborts on positions #208 + #209 even
though the entry MARKETs had filled correctly on the exchange.

Earlier-session "improvements" were the proximate cause. A pre-flight
`apiSync` in the drift command's orphan pass, a step-arguments
dedupe scan in `PreparePositionReplacementJob` over the 700K-row
`steps` table, and a Scope-3 completeness audit on the drift
command — together they pushed enough heavyweight queries onto
`steps` and `exchange_symbols` to wedge the mark-price daemon's
ReactPHP loop on a 192s row-lock wait.

Three shipments tonight:

1. **Drift watchdog pivot to alert-only + surgical silent self-heal**
   — `CheckDriftsCommand` no longer dispatches anything. Scope 1
   (active-position drift) just notifies. Scope 2 (orphan orders
   in DB) runs a silent per-orphan `apiSync` to refresh local
   state from the exchange before deciding whether to alert; the
   dominant false-positive (entry MARKET stuck PARTIALLY_FILLED on
   a cancelled position because `SyncPositionOrdersJob` skips
   MARKET) self-heals quietly. Only orphans that survive the
   refresh get a Pushover. Health #11 (1-min cadence, auto-cancel
   + auto-close) remains the enforcement layer; drift is the
   monitor.
2. **`ActivatePositionJob` race-tolerant MARKET validation** —
   replaced the strict-FILLED guard with a poll-with-timeout: up
   to three attempts at 500ms apart, each calling `apiSync()` to
   refresh the local row from exchange truth. If the exchange
   confirms FILLED, the lifecycle proceeds. Only if the exchange
   itself still reports non-terminal after the budget do we surface
   the legitimate error. Pinned with three TDD tests.
3. **Reverts** — pre-flight `apiSync`, `PreparePositionReplacementJob`
   dedupe scan, and Scope 3 audit all rolled back to their
   pre-session shapes. The DB lock contention on `exchange_symbols`
   is tracked in memory under `db_lock_contention_mark_price_daemon.md`
   for a separate structural fix (split hot mark-price column out
   into its own narrow table).

Smoke verification on prod: triggered drift command — clean (no
quiet active positions, no orphan orders). Manually placed an
APEUSDT short on Bruno's Main Binance Account → triggered system
health command → orphan order detected on account 1, cancelled,
Pushover delivered. End-to-end loop verified.

## Current state

- Tests: 1591 passing (1 risky, 6 incomplete, 4 todos). Up by
  three from start-of-session baseline.
- Drift command suite trimmed to 9 cases — Scope-3 cases removed
  with the revert; the four cases that asserted dispatch /
  inline-DB-cancel behaviour rewritten against the alert-only
  contract.
- Horizon respawned cleanly after job-class edits.

## WIP

None. All session shipments are clean and tested.

## Pending items

- DB lock contention on `exchange_symbols` blocking the mark-price
  daemon ReactPHP loop. Tonight's WS-layer fixes are orthogonal —
  the structural fix is to split the hot mark-price column out
  into its own narrow table so contention with other writers
  (e.g. `ConcludeSymbolsDirectionCommand` burst at :30) stops
  freezing publication for symbols with open positions.
- Re-introducing Scope 3 on the drift command requires a dedicated
  index strategy or a derived state table updated on the open-
  lifecycle terminal step before it's safe under live load.

## Key decisions made this session

- **Drift command is alert-only** — enforcement lives one layer up
  in the system-health watchdog (Health #11). Drift is a slower-
  cadence monitor that backstops Health-11 outages. Belt + braces,
  not redundancy.
- **Race-tolerant MARKET validation is a primitive, not a workaround**
  — the WS-vs-REST ordering race on entry MARKETs is genuine and
  needs the bounded retry even when DB lock contention is fully
  resolved.
- **Sandbox safety rule reaffirmed** — production DB writes during
  the revert path went through `Edit` only, no destructive shell
  commands, no migrations.
