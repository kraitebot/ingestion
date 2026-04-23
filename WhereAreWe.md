# WhereAreWe — Kraite Ingestion

## Date
2026-04-23 (late evening)

## Session summary
Third hardening pass on the same day, focused on building the S/R gate
end-to-end:

- **Binance WebSocket price daemon** — `kraite:stream-binance-prices`
  long-running process, now under supervisor
  (`kraite-ingestion-binance-prices.conf`). Subscribes to Binance's
  `!markPrice@arr@1s` stream and keeps `exchange_symbols.mark_price` +
  `mark_price_synced_at` fresh at 1 Hz for every Binance-listed
  symbol. Cross-exchange replication built in: one tick refreshes the
  Binance row AND every peer row on Bybit / KuCoin / Bitget via
  (token+quote) match with `token_mappers` overrides for naming
  divergence (BTC→XBT on KuCoin etc.).
- **Pivot indicator intake** — `PivotPointsIndicator` as a
  `ValidationIndicator` that always returns true, plugged into the
  conclude-indicators pipeline. TAAPI pivotpoints payload lands in
  `indicator_histories` + mirrors into
  `exchange_symbols.indicators_values['pivotpoints']`. Seeded in DB.
- **Pivot persistence** —
  `Jobs/Atomic/ExchangeSymbol/QueryAndStoreSupportAndResistanceJob`
  wired into `createFinalizationSteps()` at index 4, alongside
  `CopyDirectionToOtherExchangesJob`. Reads pivotpoints payload from
  indicators_values, writes the seven levels into dedicated
  DECIMAL(20,8) columns + stamps `pivot_synced_at`. Guards against
  direction being cleared between scheduling and execution.
- **Selection-phase S/R gate** —
  `Support\SupportResistanceProximity::computeMultiplier()` pure math,
  called from `HasTokenDiscovery::selectBestTokenByBtcBias` (line 631)
  AND `selectBestTokenFallback` (line 716). Each candidate's base
  score gets multiplied by the proximity factor: safe-zone candidates
  keep full score, penalty-band (approaching wrong-side pivot)
  candidates fade linearly to zero, breakouts through R3/S3 are
  direction-aware. Soft by construction — a risky token is just
  sorted to the bottom, not filtered.
- **Pivot clearing on direction invalidation** — all three paths that
  null direction (last-timeframe exhaustion, path-invalid rejection,
  --reset) now also null the seven pivot columns + `pivot_synced_at`.
  Stale pivots never survive a direction invalidation.

Three new Pest suites (21 tests / 36 assertions) covering the math,
the indicator contract, the migration columns, and the
finalization-step wiring. All green.

Live-verified the full chain against ONT: pulled TAAPI pivots inline,
persisted to columns, saw the selection multiplier correctly collapse
to 0.0 because ONT's mark had just crossed above R1 (penalty zone for
a LONG).

## Current state
- Test suite: all 21 new regression tests green. Earlier suites still
  green (9 from prior PM push). No known failures.
- Horizon reloaded after the new indicator + finalization step + S/R
  multiplier landed. Next conclude cron at :30 will exercise the full
  pipeline naturally.
- `can_trade=true` on Main Binance Account, 6×6 slots.
- Price daemon supervisor `kraite-ingestion-binance-prices` RUNNING,
  568 symbols + cross-exchange peers being refreshed at ~1 Hz.
- 0 Binance rows with `pivot_r1` populated from a clean conclude run
  yet — all 4 concluded symbols in the manual trigger tripped
  `ConfirmPriceAlignmentWithDirectionJob` at index 3 which cleared
  direction before index 4 ran (defensive guard behaved as designed).
  ONT was force-populated for validation. Normal run at :30 will give
  the next organic crop.

## WIP
Nothing mid-implementation. All evening work committed to local
branches, ready to push.

Uncommitted files (both repos):
- `packages/kraitebot/core/src/Abstracts/BaseWebsocketClient.php` (new)
- `packages/kraitebot/core/src/Support/ApiClients/Websocket/BinanceApiClient.php` (new)
- `packages/kraitebot/core/src/Support/Apis/Websocket/BinanceApi.php` (new)
- `packages/kraitebot/core/src/Support/Proxies/ApiWebsocketProxy.php` (new)
- `packages/kraitebot/core/src/Support/SupportResistanceProximity.php` (new)
- `packages/kraitebot/core/src/Commands/Daemons/StreamBinancePricesCommand.php` (new)
- `packages/kraitebot/core/src/Indicators/RefreshData/PivotPointsIndicator.php` (new)
- `packages/kraitebot/core/src/Jobs/Atomic/ExchangeSymbol/QueryAndStoreSupportAndResistanceJob.php` (new)
- `packages/kraitebot/core/database/migrations/2026_04_23_213200_add_mark_price_synced_at_to_exchange_symbols.php` (new)
- `packages/kraitebot/core/database/migrations/2026_04_23_220000_add_pivot_columns_to_exchange_symbols.php` (new)
- `packages/kraitebot/core/src/Jobs/Models/ExchangeSymbol/ConcludeSymbolDirectionAtTimeframeJob.php` (finalization wiring + pivot clearing)
- `packages/kraitebot/core/src/Commands/Cronjobs/ConcludeSymbolsDirectionCommand.php` (pivot clearing in --reset)
- `packages/kraitebot/core/src/Concerns/Account/HasTokenDiscovery.php` (multiplier in both paths)
- `packages/kraitebot/core/src/CoreServiceProvider.php` (daemon registered)
- `packages/kraitebot/core/database/seeders/KraiteSeeder.php` (pivotpoints row)
- `packages/kraitebot/core/config/kraite.php` (sr_safe_zone)
- `/etc/supervisor/conf.d/kraite-ingestion-binance-prices.conf` (supervisor, not versioned in git but live)
- Three new Pest test files in `ingestion.kraite.com/tests/`
- docs + WhereAreWe updates

## Pending items
- Wait for hourly :30 conclude cron to populate pivots organically on
  symbols that survive the price-alignment check.
- `ConfirmPriceAlignmentWithDirectionJob` is rejecting almost every
  concluded symbol in today's runs. Not a new bug, just became
  visible while checking the S/R pipeline. Worth a separate look
  later — the rejection rate feels too aggressive.
- Admin UI timestamp display — Bruno flagged a timezone issue on
  `positions.opened_at`; server + DB + config all correct
  (Europe/Zurich, stored values match wall clock). Need the specific
  UI location from him to trace the display-side conversion bug.
- Re-enabling `position_opened` / `position_closed` Pushover
  canonicals once a digest / quiet-hours filter lands.
- TAAPI throttler 429 noise — `BaseApiThrottler` non-atomic race still
  open, tracked in memory.

## Key decisions this session
- **Binance as universal price reference.** One WebSocket
  subscription feeds the entire multi-exchange universe. Arbitrage
  keeps drift < 0.1 % for liquid tokens, well below any reasonable
  S/R threshold. Precedent already set by direction-by-copy; this is
  the same pattern extended to mark_price.
- **Pivot is a passive indicator, not a gate.** ValidationIndicator
  that always returns true — it participates in the pipeline for
  data-intake purposes only. Never contributes to a direction vote
  or invalidates a timeframe. Keeps the existing direction-conclusion
  logic untouched.
- **Soft penalty over hard filter.** The multiplier collapses a
  risky candidate's score toward 0 but doesn't exclude it. If the
  entire candidate pool is penalised, the sort still picks someone —
  matching Bruno's "if we don't have anything else, it's okay to pick
  those" rule. Linear fade within the penalty band means the least
  risky of the risky wins.
- **Pivots live as real columns, not JSON.** DECIMAL(20,8) columns
  enable SQL-level filtering (now or future) without JSON_EXTRACT
  overhead. The S/R check runs at selection time against live
  mark_price, so computing position-in-range in SQL or PHP is cheap
  either way. Columns also make admin-UI inspection trivial.
- **Raw UPDATE bypasses Eloquent observers on the hot path.** 1 Hz
  × 568 symbols × (potentially) cross-exchange replication = too
  chatty for the ModelLog observer chain to do useful work on.
  The price daemon uses chunked CASE/WHEN UPDATEs directly.
  Audit-trail responsibility for pivot writes stays with the atomic
  finalization job (which DOES go through updateSaving).
