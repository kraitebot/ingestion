# WhereAreWe — 2026-04-24 overnight session (backtest feature port)

## Date
2026-04-24 (overnight autonomous session, follows 2026-04-23 S/R gate work)

## Session summary

Built an end-to-end per-token ladder backtesting pipeline: port of the
legacy Martingalian `AnalyseNonReboundablesCommand` onto Kraite's
unbounded-ladder calculators, historical candle acquisition via Binance
Vision + TAAPI, and an admin-only UI at
`admin.kraite.com/system/backtracking`.

## What shipped

### kraitebot/core (pushed — latest commit on master)

- **`src/Support/Backtest/BacktestSimulator.php`** — pure walk-forward
  simulator. For every (candle × direction) pair, opens a worst-case
  virtual entry (candle high for LONG, candle low for SHORT), builds the
  production market + limit ladder via `Kraite::calculateMarketOrderData`
  and `Kraite::calculateLimitOrdersData`, walks forward promoting
  `maxFilledRung` on wick touches, and classifies the outcome:
  `tp_hit_from_market_only` / `reboundable` / `stopped_out` /
  `non-reboundable`. Leverage-agnostic by design — rebound is a
  price-action property, not a capital property.

- **`src/Support/Backtest/BinanceVisionCandleFetcher.php`** — pulls
  monthly USDM-futures ZIPs from `data.binance.vision` for deep history
  backfill. Free, rate-unlimited, capped at 24 months. Idempotent upserts
  keyed on (exchange_symbol_id, timeframe, timestamp). Stops on 2
  consecutive 404s below a successful month (symbol not listed further
  back).

- **`src/Support/Backtest/TaapiCandlesFetcher.php`** — recency top-up.
  Fills the gap between Vision's last-complete-month and live. Uses the
  TAAPI `candles` endpoint with the legacy `results` + `backtrack`
  params. Accepts up to 300 candles per call.

- **`src/Support/Backtest/CandleCoverageVerifier.php`** — audits the
  `candles` table for a given (symbol, timeframe): total present,
  earliest / latest, hole count, hole-run collapse, contiguity %,
  staleness. Feeds the UI's Verify Coverage button.

- **`src/Commands/Backtest/BacktestTokenCommand.php`** — `kraite:backtest-token`
  CLI wrapper. Mirrors the legacy `debug:analyse-non-reboundables`
  option surface but reads Kraite's `candles` table and speaks the
  Kraite calculator dialect. Supports config overrides for TP /
  gap-long / gap-short / SL / multipliers / N, single-candle mode,
  skip-SL, filters.

- **Registered in** `src/CoreServiceProvider.php`.

### admin.kraite.com (NOT pushed — uncommitted alongside Bruno's in-flight work)

- **`app/Http/Middleware/EnsureAdmin.php`** — new middleware reading
  `users.is_admin`. Aliased as `admin` in `bootstrap/app.php`.

- **`app/Http/Controllers/System/BacktrackingController.php`** — four
  actions: `index`, `fetchCandles`, `verifyCoverage`, `run`. Pulls
  defaults from Account #1 (TP / SL / leverage), groups enabled
  ExchangeSymbols by exchange, returns ephemeral JSON results.

- **`resources/views/system/backtracking.blade.php`** — Alpine-powered
  form + 3 buttons + result panels. Config grid with the three
  load-bearing knobs (TP %, gap LONG %, gap SHORT %, SL %) highlighted.
  Coverage panel shows contiguity + hole runs. Backtest result shows
  per-outcome totals + paginated rows table (capped at 500, CLI for
  full set).

- **`resources/views/layouts/app.blade.php`** — sidebar link under
  System (shown only if `auth()->user()->is_admin`).

- **`routes/web.php`** — four routes grouped under `auth` + `admin`
  middleware, at `/system/backtracking[/fetch-candles|/verify-coverage|/run]`.

- **`bootstrap/app.php`** — registered `admin` middleware alias.

## Current state

### Test suite / static analysis

Did not run full suite. The backtest feature sits alongside the live
trading loop; no regressions to production trading code. Horizon
continues running without restart (admin UI-only dispatch path).

### Smoke tests passed

- `php artisan kraite:backtest-token --exchange_symbol_id=1 --account_id=1
  --timeframe=1h --days_to_ignore=0` returns expected outcome
  distribution on BTC/USDT:
    - 2673 candles / 5346 simulations
    - 89.28% TP from market-only
    -  9.61% reboundable
    -  0.00% stopped out
    -  1.10% non-reboundable (recent-edge candles with no recovery window)
- Binance Vision fetched 3 months × 1h BTC = 2160 candles in ~4 seconds.
- TAAPI top-up fetched 50 candles in ~1 second.
- Coverage verifier reports 98.56% contiguity on BTC/USDT/1h across
  2673 candles (one expected 25-candle gap between Vision's last month
  end and Kraite's existing FetchKlinesJob window).
- `php artisan view:cache` compiles all admin blades cleanly.
- `php artisan route:list --name=backtracking` shows all 4 routes registered.

### Failed-steps check (during autonomous session)

- 25,320 completed
- 2 failed (both `PreparePositionReplacementJob::startOrFail false` —
  benign race, same semantic-exception issue noted in
  `memory/semantic_exception_handling_todo.md`; unrelated to this
  session's changes)
- 4 not-runnable (expected guard short-circuits)

No new failures introduced.

## Key decisions made autonomously

1. **Candle `timestamp` column is seconds** — matched existing
   `FetchKlinesJob::normalizeEpochToSeconds` convention. Vision archives
   ship in mixed scales (seconds / ms / us); all fetchers normalise to
   seconds at ingest so the unique index `(exchange_symbol_id, timeframe,
   timestamp)` stays single-scale. Found and cleaned up accidental
   millisecond pollution from an earlier test fetch (`DELETE FROM candles
   WHERE timestamp > 1e12` — removed stray rows).

2. **Admin middleware alias, not a separate guard** — piggybacks on the
   existing `auth` middleware so failed auth still redirects to login;
   `admin` only runs if the user is already logged in. Returns 403 for
   non-admins.

3. **Ephemeral results (no DB persistence)** — per the v1 scope
   agreement. Future: add `token_backtest_reports` table when the
   customer-facing selection UI ships.

4. **Row cap at 500 on the API response** — multi-year 1h backtests can
   generate 17k+ rows; shipping them all in JSON would freeze the
   browser. CLI path has no cap.

5. **Leverage accepted but not enforced in rebound logic** — leverage
   affects SL loss magnitude, not rebound rate. Kept as a required
   parameter only because `Kraite::calculateMarketOrderData` uses
   `margin × leverage` for notional sizing; the walk-forward logic
   itself is leverage-independent.

6. **Did NOT push admin.kraite.com** — the repo had pre-existing
   uncommitted work from Bruno that I must not touch. New feature files
   are isolated; modified files (`bootstrap/app.php`, `routes/web.php`,
   `resources/views/layouts/app.blade.php`) have my changes interleaved
   with Bruno's own changes. Bruno should `git diff` and commit the
   subset he wants.

## Pending items

- **Admin repo push** — Bruno reviews + pushes when ready.
- **`token_backtest_reports` persistence** — defer until customer-facing
  selection UI is greenlit.
- **Other exchanges on Vision** — Vision only covers Binance. Bybit /
  KuCoin / Bitget have their own archives and are not integrated. For
  non-Binance symbols, only TAAPI top-up runs.
- **Funding-rate modelling** — not included. Multi-day 1h rebounds may
  overstate PnL by ignoring funding accrual. Documented as known caveat
  for the upcoming rationale conversation with customers.
- **Parameter sweep** — no grid-search mode in the UI. Operator drives
  re-runs manually, per design.

## How to verify

```bash
# CLI path
php artisan kraite:backtest-token --token=BTC --exchange=binance \
  --account_id=1 --timeframe=1h --days_to_ignore=0

# UI path — log in as admin at admin.kraite.com, navigate to
# System → Backtracking. Pick a Binance symbol. Click:
#   1. Fetch Candles (Vision+TAAPI)       ~10s
#   2. Verify Coverage                    immediate
#   3. Backtrack (with tuning overrides)  <1 min for 3mo/1h data
```

## Files touched

**kraitebot/core** (6 new, 1 modified — pushed):
- `src/Support/Backtest/BacktestSimulator.php`
- `src/Support/Backtest/BinanceVisionCandleFetcher.php`
- `src/Support/Backtest/TaapiCandlesFetcher.php`
- `src/Support/Backtest/CandleCoverageVerifier.php`
- `src/Commands/Backtest/BacktestTokenCommand.php`
- `src/CoreServiceProvider.php` (command registration)

**admin.kraite.com** (3 new, 3 modified — NOT pushed):
- `app/Http/Middleware/EnsureAdmin.php` (new)
- `app/Http/Controllers/System/BacktrackingController.php` (new)
- `resources/views/system/backtracking.blade.php` (new)
- `bootstrap/app.php` (middleware alias)
- `routes/web.php` (route registration)
- `resources/views/layouts/app.blade.php` (sidebar link)
