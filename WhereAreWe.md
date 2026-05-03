# WhereAreWe — 2026-05-04 (per-order cancel + test env isolation)

## Date
2026-05-04

## Session summary

Late-night session that opened with another collateral-damage
incident on position 211 ETCUSDT. Drift watchdog dispatched a
cancel-lifecycle for position 209 (already cancelled 11 min
earlier with stuck NEW orders); the lifecycle issued a
symbol-wide `DELETE /fapi/v1/allOpenOrders?symbol=ETCUSDT`
which Binance interprets literally — every working order on
ETCUSDT got killed, including position 211's TP and 4 LIMIT
ladder. WS pushes for the four cancellations then beat the
OrderObserver's SELECT-then-INSERT dedupe and inserted two
`PreparePositionReplacementJob` steps in the same second,
both spawning `SmartReplaceOrdersJob`.

Three shipments tonight:

1. **`CancelPositionOpenOrdersJob` is per-order on every exchange.**
   Replaced the dual-path job (Bitget already iterated, but
   Binance / Kucoin / Bybit relied on symbol-wide cancel) with
   a single per-order code path. Selects the position's own
   non-algo `NEW` / `PARTIALLY_FILLED` orders with a real
   `exchange_order_id`, bumps `reference_status='CANCELLED'`
   pre-call (intent gate for the OrderObserver), iterates
   `Order::apiCancel()` per row, classifies "already gone"
   responses as idempotent. Symbol-wide collateral damage
   eliminated by construction. Smoke-tested live with manual
   close on LTCUSDT (position 227) — four targeted DELETEs,
   zero `/fapi/v1/allOpenOrders`, position closed cleanly.
2. **`SendsNotificationsTest` fixture leak fix.** The two "no-op
   save doesn't re-fire delisting" cases created an
   `ExchangeSymbol` with token `BINANCE_NO_CHANGE` /
   `BYBIT_NO_CHANGE` AND a real `delivery_ts_ms` BEFORE calling
   `Notification::fake()`. The discovery save fired
   `token_delisting` through the real Notification facade.
   With no `.env.testing` existing, tests fell back to `.env`'s
   production Pushover credentials; every full-suite run
   leaked one BINANCE + one BYBIT alert to Bruno's phone.
   Fix: fake() before the row is created; reset fake immediately
   after creation so the no-op assertion isn't polluted.
3. **`.env.testing` defense-in-depth.** Full copy of `.env`
   with leak-prone keys overridden:
   `DB_DATABASE=kraite_tests` (defends against the 2026-05-01
   `migrate:fresh --env=testing` wipe pattern when no
   `.env.testing` existed), `MAIL_MAILER=array`, ZeptoMail key
   stubbed, `NOTIFICATIONS_ENABLED=false`, every Pushover key
   stubbed, `CACHE_STORE=array`, `SESSION_DRIVER=array`,
   `QUEUE_CONNECTION=sync`. `phpunit.xml` already had most of
   these as `<env>` overrides for the test runner; `.env.testing`
   covers the gap when artisan commands run with `--env=testing`
   outside pest.

## Current state

- Tests: 1595 passing (up by 4 from start-of-session baseline).
- New unit suite: `CancelPositionOpenOrdersPerOrderTest` —
  4 cases pinning per-order behaviour, cross-position isolation
  (the smoking-gun reproduction with two cohabiting ETCUSDT
  positions), `reference_status` intent-flag bump, and
  ghost / terminal / algo skip.
- Horizon respawned with new bytecode.
- LTCUSDT close validated end-to-end: 4 per-order DELETEs,
  zero symbol-wide calls, clean close.

## WIP

None — all session shipments are clean and tested.

## Pending items

- **OrderObserver dedupe race** (the second bug from the 211
  incident). Per-order cancel shipped tonight removed the
  collateral-damage trigger; but if a legitimate cancellation
  cohort ever produces simultaneous WS pushes for the same
  position, the SELECT-then-INSERT race in
  `OrderObserver::dispatchPositionReplacement` resurfaces.
  Real fix is DB-native: `SELECT FOR UPDATE` on the position
  row inside `DB::transaction(...)` around the dedupe block.
  Same pattern fits `dispatchClosePosition` and `dispatchApplyWap`.
- **DB lock contention on `exchange_symbols`** — recurred at
  00:10 tonight (197s on `api_request_logs.completed_at`
  UPDATE, 99s + 40s on `steps_dispatcher.can_dispatch` UPDATE,
  WS daemon force-reconnected after 198s frame silence).
  Structural fix (split hot mark-price column into narrow
  table) still pending — tracked in memory under
  `db_lock_contention_mark_price_daemon.md`.

## Key decisions made this session

- **Per-order cancel chosen over symbol-wide-with-pre-flight-check.**
  Bruno's open-close-reopen pattern means a sibling on the
  same symbol can enter mid-close, racing the pre-flight
  "any other open?" check. Per-order has no race window.
  Bulk DELETE is one weight, per-order is one weight per call —
  same rate-limit cost, no API saving. 6 sequential calls is
  ~150ms vs ~25ms for one — invisible on the close path
  which is already job-graph async.
- **DB-native locking, not cache locking.** Bruno pushed back
  that data lives in DB and locks should too. Stops me reaching
  for Redis/Cache::lock when `SELECT FOR UPDATE` on the
  position row is the right primitive. Will apply when the
  observer dedupe race fix ships.
- **Defense-in-depth env isolation, not cleanup of phpunit.xml.**
  `phpunit.xml` already had `<env>` overrides for the test
  runner; the gap was artisan commands run with `--env=testing`
  outside pest. `.env.testing` plugs that and reaffirms the
  CLAUDE.md hard rule from the 2026-05-01 wipe incident.
