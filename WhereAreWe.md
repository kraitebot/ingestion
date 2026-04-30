# WhereAreWe — 2026-04-30 (User Data Stream design + production triage)

## Date
2026-04-30

## Session summary

Three threads in one session:

1. **Diagnosed the Binance market-stream silent mute** that froze
   `mark_price_synced_at` for ~3 days starting 2026-04-27 16:10:44.
   Root cause: Binance silently deprecated the `/ws/` and
   `/stream?streams=` URL roots on `fstream.binance.com` on or around
   2026-04-23. Resolved separately by Release 1.11.0 of
   `kraitebot/core` (commit `4a82b08`) which migrated to the new
   `/private/ws?listenKey=` and `/market/stream?streams=` roots.
   Earlier hypothesis (Binance soft-throttle from a reconnect storm)
   was wrong — but the correlated DNS resolver fix (fresh Pawl
   Connector per reconnect, pinned to 1.1.1.1, IPv6 disabled) shipped
   in the same release and is correct on its own merits.

2. **Diagnosed a Bitget rate-limit incident** — single 429 from
   `GET /api/v2/mix/order/detail`. Root cause is structural, not
   transient: `BaseApiThrottler::canDispatch` is consulted once per
   job, but `SyncPositionOrdersJob` fires N HTTP calls per job (one
   per order). 6× undercount today, becomes a permanent 12× overshoot
   at 200 accounts. Stopgap (add 429 to retryableHttpCodes) is
   deferred; the real fix is Thread 3.

3. **Designed v1 of the Binance user data stream daemon** to replace
   polling-based order sync. Full functional spec written to
   `~/docs/kraite/02-features/user-data-stream.md`. Polling is
   retained as a 15-minute fallback. Implementation NOT started.

## Current state

### Test suite
Not run this session. No code changes were made — design and docs
only. Test status carries forward from the last code change in
Release 1.11.0 (`4a82b08`).

### Static analysis
Not run this session.

### Production
- `kraite-ingestion-binance-prices` daemon healthy. Mark prices
  flowing at ~1Hz across 1984 / 2286 enabled symbols. The 17 stale
  symbols are all `is_marked_for_delisting=1` (correctly excluded by
  the new `kraite:cron-check-stale-data` filter).
- Six open Bitget positions on `account_id=1` (Main Binance Account)
  — wait, six on `api_system=bitget` accounts in general (positions
  1016 / 1018 / 1024 / 1036 / 1056 / 1070). All `active`.
- Two active waped APE/USDT SHORT positions on Binance (1021, 1022),
  WAP cleanly applied at 07:41:18.
- Pushover delivery confirmed working after a key-sync fix (5 user
  rows + 6 .env entries corrected to the real Bruno key).

## WIP

No mid-task implementation work. The user data stream feature is
specced but not implemented. When implementation begins, the
following surfaces will be touched (none currently in flight):

| Surface | Current status |
|---|---|
| `~/docs/kraite/02-features/user-data-stream.md` | Spec WRITTEN this session |
| Migration: `api_data_stream` table | Not started |
| Migration: `binance_listen_keys` table | Not started (was deleted previously, needs recreation) |
| `MapsUserDataStream` concern + per-exchange impls | Not started |
| `kraite:stream-binance-user-data` daemon command | Not started |
| `kraite:cron-refresh-binance-listen-keys` cron | Not started |
| `ProcessUserDataEventJob` step class | Not started |
| `user-data` Horizon queue config | Not started |
| Supervisor program config for the daemon | Not started |
| `routes/console.php` cadence change for `kraite:cron-sync-orders` | Not started (every-min → every-15-min) |

## Pending items

### Build out (in priority order)
1. **`api_data_stream` migration** — schema per the v1 spec (raw +
   flat-column hybrid). Indexes on
   `(account_id, received_at)`, `(api_system_id, event_type, received_at)`,
   `(exchange_order_id)`, `(symbol, received_at)`,
   `(normalized_status, received_at)`. UNIQUE on `idempotency_key`.
2. **`binance_listen_keys` migration** — per-account listenKey state.
3. **`MapsUserDataStream` concern** with inbound methods
   (`canonicalOrderStatus`, `extractExchangeOrderId`,
   `extractClientOrderId`, `extractSymbol`, `extractPrice`,
   `extractAveragePrice`, `extractOriginalQuantity`,
   `extractFilledQuantity`, `extractLastFilledPrice`,
   `extractLastFilledQuantity`, `extractEventTimeMs`,
   `extractWsEventType`).
4. **`BinanceApiDataMapper` impl** of the new concern methods.
5. **`ProcessUserDataEventJob`** step class — persists raw frame to
   `api_data_stream`, then routes to `Order::updateSaving` for
   `order_update` events.
6. **`kraite:stream-binance-user-data` daemon** — single supervised
   process, ReactPHP loop, per-account WS, per-account error
   isolation, 60-second discovery sweep, jittered re-init on error.
7. **`kraite:cron-refresh-binance-listen-keys`** — keepalive cron
   at every-minute cadence (PUTs run through `BinanceThrottler`).
8. **Supervisor conf** for the new daemon.
9. **`routes/console.php`** — change SyncOrders schedule from
   `everyMinute` to `everyFifteenMinutes`.
10. **`config/logging.php`** — register the `user-data` channel.

### Deferred
- Bitget 429 stopgap: add `429` to
  `BitgetExceptionHandler::$retryableHttpCodes`.
- `notification_logs.gateway_response` capture for Pushover acks.
- Per-account `last_frame_at` cache + stale-account watchdog (after
  v1 daemon is stable).
- Bitget / Bybit / KuCoin user data stream daemons (after Binance v1
  proven).

## Key decisions made this session

1. **One daemon per exchange.** Binance first; Bitget / Bybit /
   KuCoin v1.1+ are additive. Different protocols, different auth,
   different failure domains.
2. **One unified `api_data_stream` table across all exchanges.** Raw
   JSON payload + flat extracted columns (status, normalized_status,
   price, average_price, original_quantity, filled_quantity,
   last_filled_price, last_filled_quantity, exchange_order_id,
   client_order_id, symbol, event_time). Per-exchange differentiation
   via `api_system_id` FK, not separate tables.
3. **Existing `*ApiDataMapper` pattern extended via a
   `MapsUserDataStream` concern**, not a parallel "extractor"
   hierarchy. Same architecture, same proxy lookup, same canonical
   vocabulary.
4. **Status normalization** lives in
   `canonicalOrderStatus($payload)`, mirroring the existing
   `canonicalOrderType($order)`. Vocabulary matches `Order::status`
   exactly so `OrderObserver` reacts unchanged.
5. **Dedicated `user-data` Horizon queue** for
   `ProcessUserDataEventJob`. Isolated from `positions` and
   `default`. Bruno's framing: WS daemon = heart, step dispatcher =
   brain. Queue isolation prevents cron-driven step backlog from
   throttling real-time event reactivity.
6. **`OrderObserver` is the dispatcher.** No new event-type → Step
   mapping needed. The router writes the new state into the `Order`
   model via `updateSaving` and the existing observer fires the
   right downstream Step.
7. **Existing `order_history` and `account_history` kept as-is** as
   Binance-specific structured projections. Not extended for new
   exchanges. The unified `api_data_stream` answers the same
   forensic questions universally.
8. **Polling sync (`kraite:cron-sync-orders`) reduced to 15-minute
   cadence** as a 100 % reconciliation safety net. Bruno's call:
   during transition the safety net is non-negotiable; cadence may
   relax later once the push path is proven.
9. **Discovery testing approach** — comment out the existing every-
   minute SyncOrders cron, run only the new daemon, manually trigger
   representative scenarios (place / partial fill / full fill /
   cancel / replace) and verify against `api_data_stream` rows + the
   `user-data` log channel that the right Step fired (or did not
   fire, where appropriate). 15-minute polling fallback bounds the
   blast radius of any missed event during testing.
10. **Per-exchange tables for raw stream events were considered and
    rejected.** Tables are passive sinks; per-exchange splitting buys
    no business value. Per-exchange differentiation belongs in the
    mapper, not the schema.

## Pushover correction (operational)

Five users (2, 3, 4, 5, 7) had a shared seed-leftover Pushover key
(`u5u3hmwb48y43k5xw76ykxug4bi7qg`). All updated to Bruno's real key
(`udsjufeqymhiq569mmix5dcad37xfk`). Six `.env` entries corrected
matching the env-driven seeders. `ADMIN_USER_PUSHOVER_APPLICATION_KEY`
left untouched as instructed. Seeders themselves not modified —
they read from env. Verification test sent successfully.
